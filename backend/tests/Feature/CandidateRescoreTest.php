<?php

namespace Tests\Feature;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Enums\PipelineStage;
use App\Jobs\RescoreCandidatesJob;
use App\Models\AreaMetric;
use App\Models\Candidate;
use App\Models\CandidateNote;
use App\Models\Company;
use App\Models\Title;
use App\Models\User;
use App\Services\Candidates\CandidateRescorer;
use App\Services\Candidates\RescoreOptions;
use App\Services\Candidates\RescoreTally;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CandidateRescoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A candidate scored before any enrichment existed, whose company and
     * title have since been enriched.
     */
    private function staleCandidate(array $candidate = [], array $company = [], array $title = []): Candidate
    {
        $companyModel = Company::factory()->create(array_merge([
            'enriched_at' => now()->subDay(),
            'status' => 'active',
            'accounts_overdue' => true,
            'confirmation_statement_overdue' => true,
            'accounts_last_made_up_to' => now()->subYears(4),
            'incorporated_on' => now()->subYears(25),
            'has_charges' => true,
            'charges_count' => 2,
        ], $company));

        $titleModel = Title::factory()->create(array_merge([
            'company_id' => $companyModel->id,
            'postcode' => 'M8 8EL',
            'price_paid' => 420_000_00,
            'estimated_unit_count' => 8,
            'date_proprietor_added' => now()->subYears(10),
            'epc_enriched_at' => now()->subDay(),
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_match_method' => EpcMatchMethod::ExactAddress,
            'epc_certificate_count' => 8,
            'epc_average_energy_efficiency' => 35,
            'epc_current_rating' => 'F',
            'epc_total_floor_area' => 400.00,
        ], $title));

        return Candidate::factory()->create(array_merge([
            'title_id' => $titleModel->id,
            'stage' => PipelineStage::New,
            'score' => 10,
            'score_breakdown' => ['score' => 10, 'components' => []],
            // Scored before the enrichment above.
            'scored_at' => now()->subMonth(),
        ], $candidate));
    }

    /** A candidate with no enrichment at all, scored after creation. */
    private function unenrichedCandidate(): Candidate
    {
        $title = Title::factory()->create([
            'company_id' => Company::factory()->create(['enriched_at' => null])->id,
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
        ]);

        return Candidate::factory()->create([
            'title_id' => $title->id,
            'score' => 42,
            // Not today, so "was it rescored?" is actually observable.
            'scored_at' => now()->subDays(2),
        ]);
    }

    private function rescorer(): CandidateRescorer
    {
        return app(CandidateRescorer::class);
    }

    // ------------------------------------------------------------- rescoring

    #[Test]
    public function it_rescores_a_candidate_against_the_latest_enrichment(): void
    {
        AreaMetric::factory()->create([
            'postcode_district' => 'M8',
            'gross_yield' => 9.5,
            'median_price' => 150_000_00,
        ]);

        $candidate = $this->staleCandidate();

        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();

        $candidate->refresh();

        $this->assertGreaterThan(10, $candidate->score, 'The stale score should rise once the data exists.');
        $this->assertTrue($candidate->scored_at->isToday());

        $breakdown = $candidate->score_breakdown;

        $this->assertTrue($breakdown['components']['filing_distress']['available']);
        $this->assertTrue($breakdown['components']['epc_refurb_potential']['available']);
        $this->assertSame(110, $breakdown['weight_available'], 'Every component now has data.');
    }

    #[Test]
    public function it_never_touches_the_pipeline_stage_or_user_edited_fields(): void
    {
        $user = User::factory()->create();

        $candidate = $this->staleCandidate([
            'stage' => PipelineStage::Outreach,
            'outreach_at' => now()->subWeek(),
            'assigned_to_id' => $user->id,
            'next_action_at' => now()->addWeek()->toDateString(),
            // Deliberate manual overrides: the API lets users edit all of these.
            'estimated_units' => 99,
            'estimated_gdv' => 123_456_00,
            'estimated_uplift' => 7_000_00,
            'gross_yield' => 12.34,
        ]);

        CandidateNote::factory()->create(['candidate_id' => $candidate->id, 'body' => 'Spoke to the owner']);

        $before = $candidate->only([
            'stage', 'outreach_at', 'assigned_to_id', 'next_action_at',
            'estimated_units', 'estimated_gdv', 'estimated_uplift', 'gross_yield',
            'is_archived', 'created_at',
        ]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--force' => true])->assertSuccessful();

        $candidate->refresh();

        $this->assertSame(PipelineStage::Outreach, $candidate->stage);
        $this->assertEquals($before['outreach_at'], $candidate->outreach_at);
        $this->assertSame($before['assigned_to_id'], $candidate->assigned_to_id);
        $this->assertEquals($before['next_action_at'], $candidate->next_action_at);
        $this->assertSame(99, $candidate->estimated_units, 'A manual unit override must survive.');
        $this->assertSame(123_456_00, $candidate->estimated_gdv);
        $this->assertSame(7_000_00, $candidate->estimated_uplift);
        $this->assertSame('12.34', $candidate->gross_yield);
        $this->assertFalse($candidate->is_archived);
        $this->assertEquals($before['created_at'], $candidate->created_at);
        $this->assertSame(1, $candidate->notes()->count());
    }

    #[Test]
    public function rescoring_is_idempotent(): void
    {
        $candidate = $this->staleCandidate();

        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();
        $first = $candidate->refresh()->score;

        $this->artisan('candidates:rescore', ['--sync' => true, '--force' => true])->assertSuccessful();

        $this->assertSame($first, $candidate->refresh()->score);
        $this->assertSame(1, Candidate::count());
    }

    // ------------------------------------------------------------- selection

    #[Test]
    public function by_default_it_only_examines_candidates_enriched_since_they_were_scored(): void
    {
        $stale = $this->staleCandidate();
        $fresh = $this->unenrichedCandidate();

        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();

        $this->assertTrue($stale->refresh()->scored_at->isToday());

        $fresh->refresh();
        $this->assertFalse($fresh->scored_at->isToday(), 'Nothing new to say about this one.');
        $this->assertSame(42, $fresh->score);
    }

    #[Test]
    public function a_candidate_scored_after_its_enrichment_is_left_alone(): void
    {
        $candidate = $this->staleCandidate(['scored_at' => now()]);

        $this->artisan('candidates:rescore', ['--sync' => true])
            ->expectsOutputToContain('Nothing to rescore')
            ->assertSuccessful();

        $this->assertSame(10, $candidate->refresh()->score);
    }

    #[Test]
    public function force_examines_candidates_regardless_of_staleness(): void
    {
        $candidate = $this->staleCandidate(['scored_at' => now()]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(10, $candidate->refresh()->score);
    }

    #[Test]
    public function a_never_scored_candidate_is_always_selected(): void
    {
        $candidate = $this->staleCandidate(['scored_at' => null], ['enriched_at' => null], [
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
        ]);

        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();

        $this->assertNotNull($candidate->refresh()->scored_at);
    }

    #[Test]
    public function company_enriched_restricts_to_candidates_with_companies_house_data(): void
    {
        $withCompany = $this->staleCandidate();
        $withoutCompany = $this->staleCandidate([], ['enriched_at' => null]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--company-enriched' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertTrue($withCompany->refresh()->scored_at->isToday());
        $this->assertFalse($withoutCompany->refresh()->scored_at->isToday());
    }

    #[Test]
    public function epc_enriched_restricts_to_candidates_with_a_matched_certificate(): void
    {
        $withEpc = $this->staleCandidate();
        $withoutEpc = $this->staleCandidate([], [], [
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
        ]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--epc-enriched' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertTrue($withEpc->refresh()->scored_at->isToday());
        $this->assertFalse($withoutEpc->refresh()->scored_at->isToday());
    }

    #[Test]
    public function only_enriched_accepts_either_source(): void
    {
        $companyOnly = $this->staleCandidate([], [], [
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
        ]);
        $epcOnly = $this->staleCandidate([], ['enriched_at' => null]);
        $neither = $this->staleCandidate([], ['enriched_at' => null], [
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
        ]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--only-enriched' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertTrue($companyOnly->refresh()->scored_at->isToday());
        $this->assertTrue($epcOnly->refresh()->scored_at->isToday());
        $this->assertFalse($neither->refresh()->scored_at->isToday());
    }

    #[Test]
    public function archived_candidates_are_excluded_unless_asked_for(): void
    {
        $archived = $this->staleCandidate(['is_archived' => true]);

        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();
        $this->assertSame(10, $archived->refresh()->score);

        $this->artisan('candidates:rescore', ['--sync' => true, '--include-archived' => true])->assertSuccessful();
        $this->assertGreaterThan(10, $archived->refresh()->score);
    }

    #[Test]
    public function the_limit_takes_the_stalest_scores_first(): void
    {
        $oldest = $this->staleCandidate(['scored_at' => now()->subYear()]);
        $newer = $this->staleCandidate(['scored_at' => now()->subWeek()]);

        $this->artisan('candidates:rescore', ['--sync' => true, '--limit' => 1])->assertSuccessful();

        $this->assertTrue($oldest->refresh()->scored_at->isToday());
        $this->assertFalse($newer->refresh()->scored_at->isToday());
    }

    // --------------------------------------------------------------- dry run

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $candidate = $this->staleCandidate();
        $before = $candidate->only(['score', 'score_breakdown', 'scored_at']);

        $this->artisan('candidates:rescore', ['--sync' => true, '--dry-run' => true])
            ->expectsOutputToContain('Nothing was written')
            ->assertSuccessful();

        $candidate->refresh();

        $this->assertSame($before['score'], $candidate->score);
        $this->assertSame($before['score_breakdown'], $candidate->score_breakdown);
        $this->assertEquals($before['scored_at'], $candidate->scored_at);
    }

    #[Test]
    public function a_dry_run_reports_the_same_movement_the_real_run_applies(): void
    {
        $candidate = $this->staleCandidate();

        $dry = new RescoreTally;
        $this->rescorer()->rescore(
            $this->rescorer()->load([$candidate->id]),
            new RescoreOptions(dryRun: true),
            $dry
        );

        $real = new RescoreTally;
        $this->rescorer()->rescore(
            $this->rescorer()->load([$candidate->id]),
            new RescoreOptions,
            $real
        );

        $this->assertSame($dry->movements, $real->movements);
        $this->assertSame($dry->meanMovement(), $real->meanMovement());
        $this->assertSame(10 + (int) array_key_first($dry->movements), $candidate->refresh()->score);
    }

    // ------------------------------------------------------ min-score-change

    #[Test]
    public function a_movement_below_the_threshold_only_stamps_scored_at(): void
    {
        $candidate = $this->staleCandidate();

        // Establish the true score, then nudge it by one point so the next
        // pass produces a movement smaller than the threshold.
        $this->artisan('candidates:rescore', ['--sync' => true])->assertSuccessful();
        $trueScore = $candidate->refresh()->score;

        $candidate->forceFill(['score' => $trueScore - 1, 'scored_at' => now()->subMonth()])->save();

        $this->artisan('candidates:rescore', [
            '--sync' => true,
            '--force' => true,
            '--min-score-change' => 5,
        ])->assertSuccessful();

        $candidate->refresh();

        $this->assertSame($trueScore - 1, $candidate->score, 'A one-point move is not written.');
        $this->assertTrue($candidate->scored_at->isToday(), 'But it is marked as checked, so the run progresses.');
    }

    #[Test]
    public function a_movement_at_the_threshold_is_written(): void
    {
        $candidate = $this->staleCandidate();

        $this->artisan('candidates:rescore', ['--sync' => true, '--min-score-change' => 5])->assertSuccessful();

        $this->assertGreaterThanOrEqual(15, $candidate->refresh()->score);
    }

    // ---------------------------------------------------------------- queued

    #[Test]
    public function it_queues_batched_jobs_by_default(): void
    {
        Bus::fake();

        $this->staleCandidate();
        $this->staleCandidate();
        $this->staleCandidate();

        // --no-wait, or the command follows the batch and a faked one never
        // reports itself finished.
        $this->artisan('candidates:rescore', ['--batch' => 2, '--no-wait' => true])->assertSuccessful();

        Bus::assertBatched(fn ($batch) => $batch->name === 'candidates:rescore' && $batch->jobs->count() === 2);
    }

    #[Test]
    public function the_queued_job_rescores_its_chunk(): void
    {
        $candidate = $this->staleCandidate();

        (new RescoreCandidatesJob([$candidate->id]))->handle($this->rescorer());

        $this->assertGreaterThan(10, $candidate->refresh()->score);
    }

    // ------------------------------------------------------------ statistics

    #[Test]
    public function the_tally_reports_exact_statistics(): void
    {
        $tally = new RescoreTally;

        // Movements of +10, +20, -5, 0, +65.
        $tally->record(50, 60);
        $tally->record(50, 70);
        $tally->record(50, 45);
        $tally->record(50, 50);
        $tally->record(15, 80);

        $this->assertSame(5, $tally->examined);
        $this->assertSame(18.0, $tally->meanMovement());
        $this->assertSame(10.0, $tally->medianMovement());
        $this->assertSame(20.0, $tally->meanAbsoluteMovement());
        $this->assertSame(65, $tally->largestRise());
        $this->assertSame(-5, $tally->largestFall());

        // 50->60, 50->70 and 15->80 all land at or above 60.
        $this->assertSame(['up' => 3, 'down' => 0], $tally->crossings[60]);
        $this->assertSame(['up' => 2, 'down' => 0], $tally->crossings[70]);
        $this->assertSame(['up' => 1, 'down' => 0], $tally->crossings[80]);
    }

    #[Test]
    public function the_tally_counts_candidates_dropping_below_a_threshold(): void
    {
        $tally = new RescoreTally;

        $tally->record(75, 55);

        $this->assertSame(['up' => 0, 'down' => 1], $tally->crossings[60]);
        $this->assertSame(['up' => 0, 'down' => 1], $tally->crossings[70]);
        $this->assertSame(['up' => 0, 'down' => 0], $tally->crossings[80]);
    }

    #[Test]
    public function tallies_merge_across_chunks_without_losing_the_median(): void
    {
        $a = new RescoreTally;
        $a->record(50, 60);
        $a->record(50, 70);

        $b = new RescoreTally;
        $b->record(50, 45);
        $b->record(50, 50);
        $b->record(15, 80);

        $a->merge($b);

        $this->assertSame(5, $a->examined);
        $this->assertSame(18.0, $a->meanMovement());
        $this->assertSame(10.0, $a->medianMovement());
        $this->assertSame(['up' => 3, 'down' => 0], $a->crossings[60]);
    }

    #[Test]
    public function a_tally_survives_a_round_trip_through_the_cache(): void
    {
        $tally = new RescoreTally;
        $tally->record(50, 62);
        $tally->written = 1;
        $tally->scoreChanged = 1;

        $restored = RescoreTally::fromArray($tally->toArray());

        $this->assertSame($tally->examined, $restored->examined);
        $this->assertSame($tally->written, $restored->written);
        $this->assertSame($tally->movements, $restored->movements);
        $this->assertSame($tally->crossings, $restored->crossings);
        $this->assertSame($tally->medianMovement(), $restored->medianMovement());
    }

    #[Test]
    public function the_summary_reports_threshold_crossings(): void
    {
        // Scored at 10; enrichment pushes it well past 60.
        $this->staleCandidate();

        $this->artisan('candidates:rescore', ['--sync' => true])
            ->expectsOutputToContain('Threshold crossings')
            ->expectsOutputToContain('Median movement')
            ->assertSuccessful();
    }
}
