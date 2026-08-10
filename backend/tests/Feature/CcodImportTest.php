<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Enums\PipelineStage;
use App\Enums\Tenure;
use App\Jobs\ImportCcodFile;
use App\Models\AreaMetric;
use App\Models\Candidate;
use App\Models\CcodImport;
use App\Models\Company;
use App\Models\Title;
use App\Services\Ccod\CcodImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CcodImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/Fixtures/ccod_sample.csv');
    }

    private function import(): int
    {
        return $this->artisan('ccod:import', [
            'path' => $this->fixture(),
            '--sync' => true,
            '--force' => true,
        ])->run();
    }

    #[Test]
    public function it_imports_titles_and_companies_from_a_ccod_extract(): void
    {
        $this->assertSame(0, $this->import());

        // Six data rows carry a title number; the seventh is an orphan and the
        // final line is the "Row Count" trailer.
        $this->assertSame(6, Title::count());

        $title = Title::where('title_number', 'MU100001')->firstOrFail();

        $this->assertSame(Tenure::Freehold, $title->tenure);
        $this->assertTrue($title->multiple_address_indicator);
        $this->assertSame('M8 8EL', $title->postcode);
        $this->assertSame('North West', $title->region);
        $this->assertSame('NORTHERN BLOCKS LIMITED', $title->proprietor_name);
        $this->assertSame(8, $title->estimated_unit_count);
        $this->assertNotNull($title->first_seen_at);

        $this->assertSame('NORTHERN BLOCKS LIMITED', $title->company->name);
        $this->assertSame('04512378', $title->company->company_number);
    }

    #[Test]
    public function it_stores_price_paid_in_pence_and_parses_the_proprietor_date(): void
    {
        $this->import();

        $title = Title::where('title_number', 'MU100001')->firstOrFail();

        // The CSV says 420000 pounds.
        $this->assertSame(42_000_000, $title->price_paid);
        $this->assertSame('2009-06-15', $title->date_proprietor_added->toDateString());
    }

    #[Test]
    public function it_promotes_only_freehold_multiple_address_titles_to_candidates(): void
    {
        $this->import();

        $promoted = Candidate::query()
            ->with('title')
            ->get()
            ->map(fn (Candidate $candidate) => $candidate->title->title_number)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['MU100001', 'MU100002', 'MU100003'], $promoted);
    }

    #[Test]
    public function it_does_not_promote_leasehold_titles(): void
    {
        $this->import();

        $title = Title::where('title_number', 'LH200001')->firstOrFail();

        $this->assertSame(Tenure::Leasehold, $title->tenure);
        $this->assertTrue($title->multiple_address_indicator);
        $this->assertNull($title->candidate);
    }

    #[Test]
    public function it_does_not_promote_single_address_titles(): void
    {
        $this->import();

        $title = Title::where('title_number', 'SA300001')->firstOrFail();

        $this->assertSame(Tenure::Freehold, $title->tenure);
        $this->assertFalse($title->multiple_address_indicator);
        $this->assertNull($title->candidate);
    }

    #[Test]
    public function it_does_not_promote_addresses_matching_the_commercial_keyword_filter(): void
    {
        $this->import();

        $title = Title::where('title_number', 'CP400001')->firstOrFail();

        // Freehold and flagged as multiple address, so only the keyword filter
        // keeps this car park out of the pipeline.
        $this->assertSame(Tenure::Freehold, $title->tenure);
        $this->assertTrue($title->multiple_address_indicator);
        $this->assertNull($title->candidate);

        $this->assertSame(1, CcodImport::latest('id')->firstOrFail()->meta['rejections']['excluded_address']);
    }

    #[Test]
    public function it_creates_candidates_in_the_new_stage_with_a_scored_breakdown(): void
    {
        $this->import();

        $candidate = Candidate::query()
            ->whereRelation('title', 'title_number', 'MU100001')
            ->firstOrFail();

        $this->assertSame(PipelineStage::New, $candidate->stage);
        $this->assertGreaterThan(0, $candidate->score);
        $this->assertLessThanOrEqual(100, $candidate->score);
        $this->assertSame(8, $candidate->estimated_units);
        $this->assertNotNull($candidate->scored_at);

        $breakdown = $candidate->score_breakdown;

        $this->assertArrayHasKey('components', $breakdown);
        // Weights are relative, not a percentage: the EPC component takes the
        // total past 100 and the score normalises over available weight.
        $this->assertSame(110, $breakdown['weight_total']);

        // Both Companies House components stay unavailable until the company
        // is enriched, so they are excluded from the denominator rather than
        // scored as zero.
        $this->assertFalse($breakdown['components']['filing_distress']['available']);
        $this->assertFalse($breakdown['components']['charges_pressure']['available']);
        $this->assertTrue($breakdown['components']['estimated_units']['available']);
        $this->assertFalse($breakdown['components']['epc_refurb_potential']['available']);
        $this->assertSame(55, $breakdown['weight_available']);
    }

    #[Test]
    public function it_uses_area_metrics_when_scoring(): void
    {
        AreaMetric::factory()->create([
            'postcode_district' => 'M8',
            'gross_yield' => 11.5,
            'median_price' => 150_000_00,
        ]);

        $this->import();

        $candidate = Candidate::query()
            ->whereRelation('title', 'title_number', 'MU100001')
            ->firstOrFail();

        $yield = $candidate->score_breakdown['components']['area_yield'];

        $this->assertTrue($yield['available']);

        // Area yield adds its 30 points to the live model; only the 15 points
        // of Companies House data are still missing.
        $this->assertSame(85, $candidate->score_breakdown['weight_available']);
    }

    #[Test]
    public function it_normalises_company_numbers_so_padded_registrations_match(): void
    {
        $this->import();

        // 04512378 and the unpadded 4512378 are the same company.
        $this->assertSame(1, Company::where('company_number', '04512378')->count());

        $this->assertSame(
            Title::where('title_number', 'MU100001')->value('company_id'),
            Title::where('title_number', 'CP400001')->value('company_id'),
        );

        // Four distinct registrations appear across the six imported rows.
        $this->assertSame(4, Company::count());
    }

    #[Test]
    public function it_imports_titles_that_have_no_company_registration_number(): void
    {
        $this->import();

        $title = Title::where('title_number', 'MU100003')->firstOrFail();

        $this->assertNull($title->company_id);
        $this->assertSame('MIDLANDS BLOCK CO LIMITED', $title->proprietor_name);
        $this->assertNotNull($title->candidate);
    }

    #[Test]
    public function it_keeps_additional_proprietors_in_the_raw_payload(): void
    {
        $this->import();

        $title = Title::where('title_number', 'MU100002')->firstOrFail();

        $this->assertSame('SECOND HOLDER LIMITED', $title->raw['proprietors'][2]['name']);
        $this->assertSame('09887766', $title->raw['proprietors'][2]['company_number']);
    }

    #[Test]
    public function it_records_row_counts_on_the_import(): void
    {
        $this->import();

        $import = CcodImport::latest('id')->firstOrFail();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(7, (int) $import->rows_total, 'Total comes from the file\'s own Row Count trailer.');
        $this->assertSame(6, (int) $import->rows_imported);
        $this->assertSame(1, (int) $import->rows_skipped, 'The row with no title number is unusable.');
        $this->assertSame(0, (int) $import->rows_failed);
        $this->assertSame(6, (int) $import->titles_created);
        $this->assertSame(0, (int) $import->titles_updated);
        $this->assertSame(3, $import->meta['candidates_created']);
        $this->assertNotNull($import->started_at);
        $this->assertNotNull($import->finished_at);
    }

    #[Test]
    public function it_is_idempotent_when_the_same_file_is_imported_twice(): void
    {
        $this->import();

        $firstTitleId = Title::where('title_number', 'MU100001')->value('id');
        $firstSeenAt = Title::where('title_number', 'MU100001')->value('first_seen_at');

        $this->import();

        $this->assertSame(6, Title::count(), 'Titles are upserted, not duplicated.');
        $this->assertSame(4, Company::count(), 'Companies are upserted, not duplicated.');
        $this->assertSame(3, Candidate::count(), 'Candidates are created once per title.');

        $this->assertSame($firstTitleId, Title::where('title_number', 'MU100001')->value('id'));
        $this->assertEquals($firstSeenAt, Title::where('title_number', 'MU100001')->value('first_seen_at'));

        $second = CcodImport::latest('id')->firstOrFail();

        $this->assertSame(0, (int) $second->titles_created);
        $this->assertSame(6, (int) $second->titles_updated);
        $this->assertSame(0, $second->meta['candidates_created']);
    }

    #[Test]
    public function re_importing_does_not_disturb_pipeline_progress(): void
    {
        $this->import();

        $candidate = Candidate::query()
            ->whereRelation('title', 'title_number', 'MU100001')
            ->firstOrFail();

        $candidate->moveTo(PipelineStage::Outreach);
        $candidate->update(['score' => 3]);

        $this->import();

        $candidate->refresh();

        $this->assertSame(PipelineStage::Outreach, $candidate->stage);
        $this->assertNotNull($candidate->outreach_at);
        $this->assertSame(3, $candidate->score, 'A re-import must not overwrite a curated candidate.');
    }

    #[Test]
    public function it_dispatches_a_queued_job_by_default(): void
    {
        Queue::fake();

        $this->artisan('ccod:import', [
            'path' => $this->fixture(),
            '--no-wait' => true,
        ])->assertSuccessful();

        Queue::assertPushed(ImportCcodFile::class);

        $import = CcodImport::latest('id')->firstOrFail();

        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertSame('ccod_sample.csv', $import->filename);
        $this->assertNotNull($import->checksum);
    }

    #[Test]
    public function the_queued_job_runs_the_import(): void
    {
        $import = CcodImport::create([
            'filename' => 'ccod_sample.csv',
            'period' => now()->startOfMonth(),
            'status' => ImportStatus::Pending,
            'meta' => ['path' => $this->fixture()],
        ]);

        (new ImportCcodFile($import->id))->handle(app(CcodImporter::class));

        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
        $this->assertSame(6, Title::count());
        $this->assertSame(3, Candidate::count());
    }

    #[Test]
    public function it_fails_cleanly_when_the_file_is_missing(): void
    {
        $this->artisan('ccod:import', [
            'path' => '/tmp/does-not-exist-'.uniqid().'.csv',
            '--sync' => true,
        ])->assertFailed();

        $this->assertSame(0, CcodImport::count(), 'No import record is created for an unreadable file.');
    }

    #[Test]
    public function fresh_clears_previously_imported_data(): void
    {
        $this->import();
        $this->assertSame(6, Title::count());

        $this->artisan('ccod:import', [
            'path' => $this->fixture(),
            '--sync' => true,
            '--fresh' => true,
            '--force' => true,
        ])->assertSuccessful();

        // Wiped and re-imported, so the counts match a single clean run.
        $this->assertSame(6, Title::count());
        $this->assertSame(3, Candidate::count());
        $this->assertSame(1, CcodImport::count());
    }

    #[Test]
    public function it_respects_the_configurable_region_filter(): void
    {
        config()->set('blockradar.candidate_filters.regions', ['North West']);

        $this->import();

        $promoted = Candidate::query()
            ->with('title')
            ->get()
            ->map(fn (Candidate $candidate) => $candidate->title->title_number)
            ->sort()
            ->values()
            ->all();

        // MU100003 is in the West Midlands and is now filtered out.
        $this->assertSame(['MU100001', 'MU100002'], $promoted);
    }
}
