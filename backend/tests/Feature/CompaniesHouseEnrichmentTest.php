<?php

namespace Tests\Feature;

use App\Enums\EnrichmentStatus;
use App\Jobs\EnrichCompaniesJob;
use App\Jobs\EnrichCompanyJob;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Title;
use App\Services\CompaniesHouse\CompaniesHouseService;
use App\Services\CompaniesHouse\CompanyEnricher;
use App\Services\CompaniesHouse\EnrichmentOutcome;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\CompaniesHouseFixtures;
use Tests\TestCase;

class CompaniesHouseEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('blockradar.companies_house.api_key', 'test-key');
        config()->set('blockradar.companies_house.fetch_officers', false);
    }

    private function enricher(): CompanyEnricher
    {
        return app(CompanyEnricher::class);
    }

    private function company(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'company_number' => '04512378',
            'name' => 'STALE NAME LIMITED',
            'enriched_at' => null,
            'enrichment_status' => null,
            'enrichment_attempts' => 0,
        ], $attributes));
    }

    #[Test]
    public function it_maps_a_company_profile_onto_the_model(): void
    {
        Http::fake(['*/company/04512378' => Http::response(CompaniesHouseFixtures::profile())]);

        $company = $this->company();

        $this->assertSame(EnrichmentOutcome::Enriched, $this->enricher()->enrich($company));

        $company->refresh();

        $this->assertSame('NORTHERN BLOCKS LIMITED', $company->name, 'Companies House is authoritative for the name.');
        $this->assertSame('active', $company->status);
        $this->assertSame('ltd', $company->type);
        $this->assertSame('england-wales', $company->jurisdiction);
        $this->assertSame('2002-08-14', $company->incorporated_on->toDateString());
        $this->assertSame(['68209', '68100'], $company->sic_codes);
        $this->assertSame('M2 6AG', $company->registered_office_postcode);
        $this->assertSame('1 King Street', $company->registered_office_address['address_line_1']);

        $this->assertSame('2025-08-31', $company->accounts_last_made_up_to->toDateString());
        $this->assertSame('2027-05-31', $company->accounts_next_due->toDateString());
        $this->assertFalse($company->accounts_overdue);
        $this->assertFalse($company->confirmation_statement_overdue);
        $this->assertSame('2027-01-24', $company->confirmation_statement_next_due->toDateString());

        $this->assertFalse($company->has_charges);
        $this->assertFalse($company->has_insolvency_history);

        $this->assertNotNull($company->enriched_at);
        $this->assertSame(EnrichmentStatus::Enriched, $company->enrichment_status);
        $this->assertSame(0, $company->enrichment_attempts);
        $this->assertNull($company->enrichment_error);
        $this->assertSame('active', $company->ch_raw['company_status'], 'Raw payload is retained for auditing.');
    }

    #[Test]
    public function it_records_overdue_filings(): void
    {
        Http::fake([
            '*/company/04512378/charges*' => Http::response(CompaniesHouseFixtures::charges(2)),
            '*/company/04512378' => Http::response(CompaniesHouseFixtures::distressedProfile()),
        ]);

        $company = $this->company();
        $this->enricher()->enrich($company);

        $company->refresh();

        $this->assertTrue($company->accounts_overdue);
        $this->assertTrue($company->confirmation_statement_overdue);
        $this->assertTrue($company->has_charges);
        $this->assertSame(2, $company->charges_count);
    }

    #[Test]
    public function it_only_requests_charges_when_the_profile_reports_them(): void
    {
        Http::fake([
            '*/company/04512378/charges*' => Http::response(CompaniesHouseFixtures::charges(5)),
            '*/company/04512378' => Http::response(CompaniesHouseFixtures::profile(['has_charges' => false])),
        ]);

        $company = $this->company();
        $this->enricher()->enrich($company);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/charges'));

        $this->assertSame(0, $company->refresh()->charges_count);
    }

    #[Test]
    public function it_fetches_officer_counts_only_when_enabled(): void
    {
        config()->set('blockradar.companies_house.fetch_officers', true);

        Http::fake([
            '*/company/04512378/officers*' => Http::response(CompaniesHouseFixtures::officers(3)),
            '*/company/04512378' => Http::response(CompaniesHouseFixtures::profile()),
        ]);

        $company = $this->company();
        $this->enricher()->enrich($company);

        $this->assertSame(3, $company->refresh()->officer_count);
    }

    #[Test]
    public function it_marks_unknown_company_numbers_as_not_found_without_failing(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'company-profile-not-found']]], 404)]);

        $company = $this->company();

        $this->assertSame(EnrichmentOutcome::NotFound, $this->enricher()->enrich($company));

        $company->refresh();

        $this->assertSame(EnrichmentStatus::NotFound, $company->enrichment_status);
        $this->assertNull($company->enriched_at, 'A missing company was never successfully enriched.');
        $this->assertSame(1, $company->enrichment_attempts);

        // A definitive 404 is not worth retrying at the HTTP layer.
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_company_that_is_not_found_is_never_retried(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $company = $this->company();
        $this->enricher()->enrich($company);

        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $this->assertSame(EnrichmentOutcome::Skipped, $this->enricher()->enrich($company->refresh()));
        Http::assertNothingSent();

        $this->assertSame(0, Company::query()->needsEnrichment()->count());
    }

    #[Test]
    public function it_skips_companies_enriched_recently_unless_forced(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $company = $this->company(['enriched_at' => now()->subDays(3)]);

        $this->assertSame(EnrichmentOutcome::Skipped, $this->enricher()->enrich($company));
        Http::assertNothingSent();

        $this->assertSame(EnrichmentOutcome::Enriched, $this->enricher()->enrich($company, force: true));
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_re_enriches_once_the_data_is_stale(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $staleDays = (int) config('blockradar.companies_house.stale_after_days');
        $company = $this->company(['enriched_at' => now()->subDays($staleDays + 1)]);

        $this->assertSame(EnrichmentOutcome::Enriched, $this->enricher()->enrich($company));
    }

    #[Test]
    public function it_stops_requesting_once_the_local_rate_limit_is_spent(): void
    {
        config()->set('blockradar.companies_house.rate_limit_per_five_minutes', 2);

        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $api = app(CompaniesHouseService::class);

        $api->profile('04512378');
        $api->profile('04512379');

        $this->assertSame(0, $api->throttle()->remaining());

        try {
            $api->profile('04512380');
            $this->fail('The third request should have been throttled.');
        } catch (RateLimitExceededException $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }

        // The blocked request never reached the network.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_honours_a_retry_after_header_when_companies_house_throttles_us(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

        try {
            app(CompaniesHouseService::class)->profile('04512378');
            $this->fail('A 429 should raise RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame(42, $e->retryAfterSeconds);
        }

        // A 429 must cost exactly one request. The HTTP client retries only
        // connection failures, so being throttled cannot triple the damage.
        Http::assertSentCount(1);

        // Their limiter and ours disagreed, so the rest of the window is burnt.
        $this->assertSame(0, app(CompaniesHouseService::class)->throttle()->remaining());
    }

    #[Test]
    public function a_rate_limit_does_not_count_against_the_company(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '30'])]);

        $company = $this->company();

        $this->expectException(RateLimitExceededException::class);

        try {
            $this->enricher()->enrich($company);
        } finally {
            $company->refresh();
            $this->assertSame(0, $company->enrichment_attempts, 'Being throttled is not the company\'s fault.');
            $this->assertNull($company->enrichment_status);
        }
    }

    #[Test]
    public function an_invalid_api_key_is_raised_rather_than_recorded_against_the_company(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid Authorization'], 401)]);

        $company = $this->company();

        $this->expectException(InvalidApiKeyException::class);

        try {
            $this->enricher()->enrich($company);
        } finally {
            $this->assertSame(0, $company->refresh()->enrichment_attempts);
        }
    }

    #[Test]
    public function a_server_error_is_recorded_and_counted(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        $company = $this->company();

        $this->assertSame(EnrichmentOutcome::Failed, $this->enricher()->enrich($company));

        $company->refresh();

        $this->assertSame(EnrichmentStatus::Failed, $company->enrichment_status);
        $this->assertSame(1, $company->enrichment_attempts);
        $this->assertStringContainsString('500', $company->enrichment_error);
        $this->assertNull($company->enriched_at);
    }

    #[Test]
    public function companies_are_abandoned_after_too_many_attempts(): void
    {
        config()->set('blockradar.companies_house.max_enrichment_attempts', 2);

        Http::fake(['*' => Http::response('nope', 500)]);

        $company = $this->company();

        $this->enricher()->enrich($company);
        $this->enricher()->enrich($company->refresh());

        $this->assertSame(2, $company->refresh()->enrichment_attempts);
        $this->assertSame(EnrichmentOutcome::Skipped, $this->enricher()->enrich($company->refresh()));
        $this->assertSame(0, Company::query()->needsEnrichment()->count());
    }

    #[Test]
    public function the_job_enriches_a_single_company(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $company = $this->company();

        (new EnrichCompanyJob($company->id))->handle($this->enricher());

        $this->assertNotNull($company->refresh()->enriched_at);
    }

    #[Test]
    public function the_batch_job_requeues_only_what_is_left_when_throttled(): void
    {
        Queue::fake();
        config()->set('blockradar.companies_house.rate_limit_per_five_minutes', 1);

        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $first = $this->company(['company_number' => '00000001']);
        $second = $this->company(['company_number' => '00000002']);
        $third = $this->company(['company_number' => '00000003']);

        (new EnrichCompaniesJob([$first->id, $second->id, $third->id]))->handle($this->enricher());

        $this->assertNotNull($first->refresh()->enriched_at);
        $this->assertNull($second->refresh()->enriched_at);

        Queue::assertPushed(
            EnrichCompaniesJob::class,
            fn (EnrichCompaniesJob $job) => $job->companyIds === [$second->id, $third->id]
        );
    }

    #[Test]
    public function the_command_enriches_companies_and_reports_a_summary(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $this->company(['company_number' => '00000001']);
        $this->company(['company_number' => '00000002', 'enriched_at' => now()]);

        $this->artisan('companies:enrich', ['--sync' => true])
            ->expectsOutputToContain('Enriched')
            ->assertSuccessful();

        $this->assertSame(2, Company::query()->whereNotNull('enriched_at')->count());
    }

    #[Test]
    public function the_command_can_target_a_single_company_number(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $wanted = $this->company(['company_number' => '00000001']);
        $other = $this->company(['company_number' => '00000002']);

        $this->artisan('companies:enrich', ['company' => '00000001', '--sync' => true])
            ->assertSuccessful();

        $this->assertNotNull($wanted->refresh()->enriched_at);
        $this->assertNull($other->refresh()->enriched_at);
    }

    #[Test]
    public function only_candidates_restricts_to_companies_in_the_pipeline(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $linked = $this->company(['company_number' => '00000001']);
        $orphan = $this->company(['company_number' => '00000002']);

        $title = Title::factory()->create(['company_id' => $linked->id]);
        Candidate::factory()->create(['title_id' => $title->id]);

        Title::factory()->create(['company_id' => $orphan->id]);

        $this->artisan('companies:enrich', ['--sync' => true, '--only-candidates' => true])
            ->assertSuccessful();

        $this->assertNotNull($linked->refresh()->enriched_at);
        $this->assertNull($orphan->refresh()->enriched_at);
    }

    #[Test]
    public function it_enriches_the_highest_scoring_candidates_first(): void
    {
        Http::fake(['*' => Http::response(CompaniesHouseFixtures::profile())]);

        $low = $this->company(['company_number' => '00000001']);
        $high = $this->company(['company_number' => '00000002']);

        Candidate::factory()->create([
            'title_id' => Title::factory()->create(['company_id' => $low->id])->id,
            'score' => 20,
        ]);
        Candidate::factory()->create([
            'title_id' => Title::factory()->create(['company_id' => $high->id])->id,
            'score' => 95,
        ]);

        $this->artisan('companies:enrich', ['--sync' => true, '--limit' => 1])
            ->assertSuccessful();

        $this->assertNotNull($high->refresh()->enriched_at, 'The 95-scoring candidate should be enriched first.');
        $this->assertNull($low->refresh()->enriched_at);
    }

    #[Test]
    public function the_command_queues_batches_by_default(): void
    {
        Queue::fake();

        $this->company(['company_number' => '00000001']);
        $this->company(['company_number' => '00000002']);
        $this->company(['company_number' => '00000003']);

        $this->artisan('companies:enrich', ['--batch' => 2])->assertSuccessful();

        Queue::assertPushed(EnrichCompaniesJob::class, 2);
    }

    #[Test]
    public function the_command_refuses_to_run_without_an_api_key(): void
    {
        config()->set('blockradar.companies_house.api_key', null);

        $this->artisan('companies:enrich', ['--sync' => true])->assertFailed();

        Http::assertNothingSent();
    }
}
