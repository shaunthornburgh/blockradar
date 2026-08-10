<?php

namespace Tests\Feature;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Enums\Tenure;
use App\Jobs\EnrichTitlesEpcJob;
use App\Models\Candidate;
use App\Models\EpcCertificate;
use App\Models\Title;
use App\Models\TitleEpcMatch;
use App\Services\Candidates\CandidateFilter;
use App\Services\Epc\AddressNormaliser;
use App\Services\Epc\EpcMatcher;
use App\Services\Epc\TitleEpcEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/Fixtures/epc_sample.csv');
    }

    /** Loads the fixture certificates. */
    private function loadCertificates(bool $allPostcodes = true): void
    {
        $this->artisan('epc:import', [
            'path' => $this->fixture(),
            '--all-postcodes' => $allPostcodes,
            '--force' => true,
        ])->assertSuccessful();
    }

    private function block(array $overrides = []): Title
    {
        return Title::factory()->create(array_merge([
            'title_number' => 'MU100001',
            'property_address' => 'Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester',
            'postcode' => 'M8 8EL',
            'district' => 'Manchester',
            'county' => 'Greater Manchester',
            'region' => 'North West',
            'estimated_unit_count' => 8,
            'unit_count_source' => 'address',
            'uprn' => null,
            'epc_enriched_at' => null,
        ], $overrides));
    }

    private function enricher(): TitleEpcEnricher
    {
        return app(TitleEpcEnricher::class);
    }

    // ---------------------------------------------------------------- import

    #[Test]
    public function it_loads_bulk_certificates(): void
    {
        $this->loadCertificates();

        $this->assertSame(10, EpcCertificate::count());

        $certificate = EpcCertificate::where('certificate_number', '1111-0000-0000-0000-0001')->firstOrFail();

        $this->assertSame('M8 8EL', $certificate->postcode);
        $this->assertSame('E', $certificate->current_energy_rating);
        $this->assertSame(48, $certificate->current_energy_efficiency);
        $this->assertSame('Flat', $certificate->property_type);
        $this->assertSame('52.50', $certificate->total_floor_area);
        $this->assertSame(2, $certificate->number_habitable_rooms);
        $this->assertSame('England and Wales: 1900-1929', $certificate->construction_age_band);
        $this->assertSame('100000000001', $certificate->uprn);
        $this->assertSame('2019-05-04', $certificate->lodgement_date->toDateString());
        $this->assertNotNull($certificate->building_key_hash);
    }

    #[Test]
    public function it_treats_placeholder_values_as_missing(): void
    {
        $this->loadCertificates();

        // "NO DATA!" in the rating column and an empty floor area and UPRN.
        $certificate = EpcCertificate::where('certificate_number', '4444-0000-0000-0000-0001')->firstOrFail();

        $this->assertNull($certificate->current_energy_rating);
        $this->assertNull($certificate->total_floor_area);
        $this->assertNull($certificate->uprn);
    }

    #[Test]
    public function the_bulk_import_is_idempotent(): void
    {
        $this->loadCertificates();
        $this->loadCertificates();

        $this->assertSame(10, EpcCertificate::count());
    }

    #[Test]
    public function it_keeps_only_postcodes_we_hold_titles_in_by_default(): void
    {
        $this->block();

        $this->artisan('epc:import', ['path' => $this->fixture(), '--force' => true])
            ->assertSuccessful();

        // Only the five M8 8EL certificates survive the filter.
        $this->assertSame(5, EpcCertificate::count());
        $this->assertSame(0, EpcCertificate::where('postcode', 'LS4 2LE')->count());
    }

    // --------------------------------------------------------------- matching

    #[Test]
    public function it_matches_a_block_to_every_flat_in_it(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        $set = $this->enricher()->enrich($title);

        $this->assertSame(EpcMatchMethod::ExactAddress, $set->method);
        $this->assertSame(EpcMatchConfidence::High, $set->confidence);

        // Flats 1-3 share the building key exactly. Flat 4 is spelled
        // "Hawthorne House, 23 Bury New Rd" so it is not an exact match, and
        // Oakwood Court in the same postcode is a different building.
        $this->assertSame(3, $set->count());
    }

    #[Test]
    public function a_typo_in_the_building_name_is_caught_by_fuzzy_matching(): void
    {
        $this->loadCertificates();

        // Remove the exactly-matching flats so fuzzy matching has to work.
        EpcCertificate::whereIn('certificate_number', [
            '1111-0000-0000-0000-0001',
            '1111-0000-0000-0000-0002',
            '1111-0000-0000-0000-0003',
        ])->delete();

        $set = $this->enricher()->enrich($this->block());

        $this->assertSame(EpcMatchMethod::FuzzyAddress, $set->method);
        $this->assertSame(EpcMatchConfidence::Medium, $set->confidence);
        $this->assertSame('1111-0000-0000-0000-0004', $set->certificates->first()->certificate_number);
        $this->assertGreaterThanOrEqual(82.0, $set->similarity);
    }

    #[Test]
    public function fuzzy_matching_does_not_sweep_in_similarly_named_neighbours(): void
    {
        $this->loadCertificates();

        // Two more buildings in the same postcode with names close enough to
        // clear the similarity threshold on their own.
        foreach ([['A', 'Hawthorn Court'], ['B', 'Hawthorn Lodge']] as [$suffix, $name]) {
            foreach ([1, 2] as $flat) {
                $address = "Flat {$flat}, {$name}, 23 Bury New Road";

                EpcCertificate::factory()->create([
                    'certificate_number' => "9999-{$suffix}-{$flat}",
                    'postcode' => 'M8 8EL',
                    'address' => $address,
                    'building_key_hash' => sha1(app(AddressNormaliser::class)->buildingKey($address)),
                    'property_type' => 'Flat',
                ]);
            }
        }

        // Force fuzzy matching by removing the exactly-matching flats.
        EpcCertificate::whereIn('certificate_number', [
            '1111-0000-0000-0000-0001',
            '1111-0000-0000-0000-0002',
            '1111-0000-0000-0000-0003',
        ])->delete();

        $set = $this->enricher()->enrich($this->block());

        $this->assertSame(EpcMatchMethod::FuzzyAddress, $set->method);

        // Only the winning building's certificates, never the lookalikes.
        $this->assertSame(1, $set->count());
        $this->assertSame('1111-0000-0000-0000-0004', $set->certificates->first()->certificate_number);
    }

    #[Test]
    public function a_uprn_on_the_title_takes_priority_over_the_address(): void
    {
        $this->loadCertificates();

        $title = $this->block([
            'uprn' => '100000000005',
            // Deliberately the address of a different building.
            'property_address' => 'Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester',
        ]);

        $set = $this->enricher()->enrich($title);

        $this->assertSame(EpcMatchMethod::Uprn, $set->method);
        $this->assertSame(EpcMatchConfidence::High, $set->confidence);
        $this->assertSame('1111-0000-0000-0000-0005', $set->certificates->first()->certificate_number);
    }

    #[Test]
    public function an_unrelated_address_falls_back_to_a_low_confidence_postcode_match(): void
    {
        $this->loadCertificates();

        $title = $this->block(['property_address' => 'Nonexistent Building, 999 Imaginary Street, Manchester']);

        $set = app(EpcMatcher::class)->match($title);

        $this->assertSame(EpcMatchMethod::Postcode, $set->method);
        $this->assertSame(EpcMatchConfidence::Low, $set->confidence);
        $this->assertSame(5, $set->count());
    }

    #[Test]
    public function low_confidence_matches_are_not_written_at_the_default_threshold(): void
    {
        $this->loadCertificates();

        $title = $this->block(['property_address' => 'Nonexistent Building, 999 Imaginary Street, Manchester']);

        $this->enricher()->enrich($title);

        $title->refresh();

        $this->assertSame(0, TitleEpcMatch::count(), 'A postcode-only match must not attach the neighbours.');
        $this->assertSame(0, $title->epc_certificate_count);
        $this->assertNull($title->epc_match_confidence);
        $this->assertNotNull($title->epc_enriched_at, 'It was still checked, so it is not retried endlessly.');
    }

    #[Test]
    public function low_confidence_matches_are_written_when_explicitly_allowed(): void
    {
        $this->loadCertificates();

        $title = $this->block(['property_address' => 'Nonexistent Building, 999 Imaginary Street, Manchester']);

        $this->enricher()->enrich($title, minimum: EpcMatchConfidence::Low);

        $title->refresh();

        $this->assertSame(5, $title->epc_certificate_count);
        $this->assertSame(EpcMatchConfidence::Low, $title->epc_match_confidence);
    }

    #[Test]
    public function a_title_with_no_postcode_is_left_unmatched(): void
    {
        $this->loadCertificates();

        $set = $this->enricher()->enrich($this->block(['postcode' => null]));

        $this->assertTrue($set->isEmpty());
    }

    // ------------------------------------------------------------ aggregates

    #[Test]
    public function it_writes_building_level_aggregates_onto_the_title(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        $this->enricher()->enrich($title);

        $title->refresh();

        $this->assertSame(3, $title->epc_certificate_count);
        // Worst of E, F, E — the block is only as good as its poorest flat.
        $this->assertSame('F', $title->epc_current_rating);
        // (48 + 34 + 45) / 3
        $this->assertSame(42, $title->epc_average_energy_efficiency);
        // 52.5 + 49.0 + 51.0 summed across the building.
        $this->assertSame('152.50', $title->epc_total_floor_area);
        $this->assertSame(6, $title->epc_habitable_rooms);
        $this->assertSame('Flat', $title->epc_property_type);
        $this->assertSame('England and Wales: 1900-1929', $title->epc_construction_age_band);
        $this->assertStringContainsString('mains gas', $title->epc_main_heating);
        $this->assertSame('2020-01-15', $title->epc_latest_lodgement_date->toDateString());
        $this->assertNotNull($title->epc_enriched_at);
    }

    #[Test]
    public function the_primary_certificate_is_the_most_recently_lodged(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        $this->enricher()->enrich($title);

        $this->assertSame(
            '1111-0000-0000-0000-0003',
            $title->refresh()->primaryEpcCertificate->certificate_number
        );

        $this->assertSame(1, TitleEpcMatch::where('is_primary', true)->count());
    }

    #[Test]
    public function re_running_rebuilds_matches_rather_than_duplicating_them(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        $this->enricher()->enrich($title);
        $this->enricher()->enrich($title, force: true);

        $this->assertSame(3, TitleEpcMatch::where('title_id', $title->id)->count());
        $this->assertSame(3, $title->refresh()->epc_certificate_count);
    }

    // ----------------------------------------------------------- unit counts

    #[Test]
    public function certificate_counts_replace_the_address_derived_unit_estimate(): void
    {
        $this->loadCertificates();

        // The address says eight; only three certificates exist.
        $title = $this->block(['estimated_unit_count' => 8, 'unit_count_source' => 'address']);

        $this->enricher()->enrich($title);

        $title->refresh();

        $this->assertSame(3, $title->estimated_unit_count);
        $this->assertSame('epc', $title->unit_count_source);
    }

    #[Test]
    public function a_single_certificate_does_not_override_the_address_estimate(): void
    {
        $this->loadCertificates();

        $title = Title::factory()->create([
            'property_address' => '96 Cardigan Lane, Leeds',
            'postcode' => 'LS4 2LE',
            'district' => 'Leeds',
            'county' => 'West Yorkshire',
            'estimated_unit_count' => 4,
            'unit_count_source' => 'address',
        ]);

        $this->enricher()->enrich($title);

        $title->refresh();

        $this->assertSame(1, $title->epc_certificate_count);
        $this->assertSame(4, $title->estimated_unit_count, 'One surveyed dwelling is not a unit count.');
        $this->assertSame('address', $title->unit_count_source);
    }

    #[Test]
    public function a_ccod_re_import_does_not_overwrite_an_epc_unit_count(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        $this->enricher()->enrich($title);
        $this->assertSame(3, $title->refresh()->estimated_unit_count);

        // Re-run the CCOD import over a file containing this title.
        $this->artisan('ccod:import', [
            'path' => base_path('tests/Fixtures/ccod_sample.csv'),
            '--sync' => true,
            '--force' => true,
        ])->assertSuccessful();

        $title->refresh();

        $this->assertSame(3, $title->estimated_unit_count, 'The weaker address signal must not win.');
        $this->assertSame('epc', $title->unit_count_source);
    }

    // -------------------------------------------------------------- command

    #[Test]
    public function the_command_matches_titles_and_reports_quality(): void
    {
        $this->loadCertificates();
        $this->block();

        $this->artisan('epc:enrich', ['--sync' => true])
            ->expectsOutputToContain('Matched and written')
            ->assertSuccessful();

        $this->assertSame(3, Title::where('title_number', 'MU100001')->value('epc_certificate_count'));
    }

    #[Test]
    public function the_command_can_restrict_to_pipeline_titles(): void
    {
        $this->loadCertificates();

        $inPipeline = $this->block();
        Candidate::factory()->create(['title_id' => $inPipeline->id]);

        $notInPipeline = $this->block([
            'title_number' => 'MU100002',
            'property_address' => 'Flats 1-3 Elm Lodge, 5 Clifton Drive, Birmingham',
            'postcode' => 'B16 9RT',
            'district' => 'Birmingham',
            'county' => 'West Midlands',
        ]);

        $this->artisan('epc:enrich', ['--sync' => true, '--only-candidates' => true])
            ->assertSuccessful();

        $this->assertNotNull($inPipeline->refresh()->epc_enriched_at);
        $this->assertNull($notInPipeline->refresh()->epc_enriched_at);
    }

    #[Test]
    public function the_command_rejects_an_invalid_confidence(): void
    {
        $this->loadCertificates();

        $this->artisan('epc:enrich', ['--sync' => true, '--min-confidence' => 'perfect'])
            ->assertFailed();
    }

    #[Test]
    public function the_command_fails_clearly_when_no_certificates_are_loaded(): void
    {
        $this->block();

        $this->artisan('epc:enrich', ['--sync' => true])
            ->expectsOutputToContain('No EPC certificates are loaded')
            ->assertFailed();
    }

    #[Test]
    public function the_command_queues_batches_by_default(): void
    {
        $this->loadCertificates();
        Queue::fake();

        $this->block();
        $this->block(['title_number' => 'MU100002']);

        $this->artisan('epc:enrich', ['--batch' => 1])->assertSuccessful();

        Queue::assertPushed(EnrichTitlesEpcJob::class, 2);
    }

    #[Test]
    public function the_queued_job_enriches_its_batch(): void
    {
        $this->loadCertificates();
        $title = $this->block();

        (new EnrichTitlesEpcJob([$title->id]))->handle($this->enricher());

        $this->assertSame(3, $title->refresh()->epc_certificate_count);
    }

    // --------------------------------------------------------------- filter

    #[Test]
    public function an_epc_confirmed_single_house_is_filtered_out_of_the_pipeline(): void
    {
        $title = Title::factory()->create([
            'tenure' => Tenure::Freehold,
            'multiple_address_indicator' => true,
            'property_address' => '96 Cardigan Lane, Leeds',
            'estimated_unit_count' => 4,
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_certificate_count' => 1,
            'epc_property_type' => 'House',
        ]);

        $filter = app(CandidateFilter::class);

        $this->assertSame('epc_single_dwelling', $filter->rejectionReason($title));

        // A single certificate for a flat is not conclusive, so it stays in.
        $title->update(['epc_property_type' => 'Flat']);

        $this->assertNull($filter->rejectionReason($title->refresh()));
    }

    #[Test]
    public function the_single_dwelling_filter_needs_a_trustworthy_match(): void
    {
        $title = Title::factory()->create([
            'tenure' => Tenure::Freehold,
            'multiple_address_indicator' => true,
            'property_address' => '96 Cardigan Lane, Leeds',
            'estimated_unit_count' => 4,
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::Low,
            'epc_certificate_count' => 1,
            'epc_property_type' => 'House',
        ]);

        $this->assertNull(app(CandidateFilter::class)->rejectionReason($title));
    }

    #[Test]
    public function titles_matched_recently_are_skipped_unless_forced(): void
    {
        $this->loadCertificates();
        $title = $this->block(['epc_enriched_at' => now()->subDay()]);

        $this->assertFalse($this->enricher()->needsEnrichment($title));

        $title->update(['epc_enriched_at' => now()->subDays(400)]);

        $this->assertTrue($this->enricher()->needsEnrichment($title->refresh()));
    }
}
