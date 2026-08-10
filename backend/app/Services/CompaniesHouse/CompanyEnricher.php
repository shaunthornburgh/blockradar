<?php

namespace App\Services\CompaniesHouse;

use App\Enums\EnrichmentStatus;
use App\Models\Company;
use App\Services\CompaniesHouse\Exceptions\CompanyNotFoundException;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one company from Companies House and writes the result onto the
 * model. Shared by the queued job and the Artisan command so both behave
 * identically.
 *
 * Companies House is treated as authoritative for company details — unlike
 * the CCOD importer, which deliberately never overwrites them.
 */
class CompanyEnricher
{
    public function __construct(private readonly CompaniesHouseService $api) {}

    /**
     * A rate limit is never swallowed: it propagates so the caller can wait or
     * release the job. Everything else is recorded on the company.
     *
     * @throws RateLimitExceededException|InvalidApiKeyException
     */
    public function enrich(Company $company, bool $force = false): EnrichmentOutcome
    {
        if (! $force && ! $this->needsEnrichment($company)) {
            return EnrichmentOutcome::Skipped;
        }

        try {
            $profile = $this->api->profile($company->company_number);
        } catch (CompanyNotFoundException) {
            $this->markNotFound($company);

            return EnrichmentOutcome::NotFound;
        } catch (RateLimitExceededException|InvalidApiKeyException $e) {
            // Neither is the company's fault, so no attempt is recorded
            // against it — it will be picked up again on the next pass.
            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($company, $e);

            return EnrichmentOutcome::Failed;
        }

        try {
            $this->apply($company, $profile);
        } catch (RateLimitExceededException|InvalidApiKeyException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($company, $e);

            return EnrichmentOutcome::Failed;
        }

        return EnrichmentOutcome::Enriched;
    }

    /**
     * Mirrors Company::needsEnrichment() for a single already-loaded model.
     */
    public function needsEnrichment(Company $company): bool
    {
        if ($company->enrichment_status?->isPermanent()) {
            return false;
        }

        $maxAttempts = (int) config('blockradar.companies_house.max_enrichment_attempts', 5);

        if ($company->enrichment_attempts >= $maxAttempts) {
            return false;
        }

        if ($company->enriched_at === null) {
            return true;
        }

        $staleDays = (int) config('blockradar.companies_house.stale_after_days', 30);

        return $company->enriched_at->lt(now()->subDays($staleDays));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function apply(Company $company, array $profile): void
    {
        $address = Arr::get($profile, 'registered_office_address');
        $hasCharges = (bool) Arr::get($profile, 'has_charges', false);

        $attributes = [
            'name' => $this->string(Arr::get($profile, 'company_name')) ?? $company->name,
            'status' => $this->string(Arr::get($profile, 'company_status')),
            'type' => $this->string(Arr::get($profile, 'type')),
            'jurisdiction' => $this->string(Arr::get($profile, 'jurisdiction')),
            'incorporated_on' => $this->date(Arr::get($profile, 'date_of_creation')),
            'dissolved_on' => $this->date(Arr::get($profile, 'date_of_cessation')),
            'sic_codes' => is_array(Arr::get($profile, 'sic_codes')) ? array_values($profile['sic_codes']) : null,
            'registered_office_address' => is_array($address) ? $address : null,
            'registered_office_postcode' => $this->string(Arr::get($address, 'postal_code'), 12),

            'accounts_last_made_up_to' => $this->date(Arr::get($profile, 'accounts.last_accounts.made_up_to')),
            'accounts_next_due' => $this->date(
                Arr::get($profile, 'accounts.next_due') ?? Arr::get($profile, 'accounts.next_accounts.due_on')
            ),
            'accounts_overdue' => $this->bool(
                Arr::get($profile, 'accounts.overdue') ?? Arr::get($profile, 'accounts.next_accounts.overdue')
            ),

            'confirmation_statement_overdue' => $this->bool(Arr::get($profile, 'confirmation_statement.overdue')),
            'confirmation_statement_last_made_up_to' => $this->date(Arr::get($profile, 'confirmation_statement.last_made_up_to')),
            'confirmation_statement_next_due' => $this->date(Arr::get($profile, 'confirmation_statement.next_due')),

            'has_charges' => $hasCharges,
            'has_insolvency_history' => $this->bool(Arr::get($profile, 'has_insolvency_history')),

            'enriched_at' => now(),
            'enrichment_status' => EnrichmentStatus::Enriched,
            'enrichment_attempted_at' => now(),
            'enrichment_attempts' => 0,
            'enrichment_error' => null,
        ];

        if (config('blockradar.companies_house.store_raw', true)) {
            $attributes['ch_raw'] = $profile;
        }

        // Each of these is a further request, so they are opt-in and only made
        // when they can actually tell us something.
        if ($hasCharges && config('blockradar.companies_house.fetch_charges', true)) {
            $attributes['charges_count'] = $this->api->chargesCount($company->company_number);
        } elseif (! $hasCharges) {
            $attributes['charges_count'] = 0;
        }

        if (config('blockradar.companies_house.fetch_officers', false)) {
            $attributes['officer_count'] = $this->api->officerCount($company->company_number);
        }

        $company->forceFill($attributes)->save();
    }

    private function markNotFound(Company $company): void
    {
        $company->forceFill([
            'enrichment_status' => EnrichmentStatus::NotFound,
            'enrichment_attempted_at' => now(),
            'enrichment_attempts' => $company->enrichment_attempts + 1,
            'enrichment_error' => 'Companies House has no record of this company number.',
        ])->save();
    }

    private function markFailed(Company $company, Throwable $e): void
    {
        $company->forceFill([
            'enrichment_status' => EnrichmentStatus::Failed,
            'enrichment_attempted_at' => now(),
            'enrichment_attempts' => $company->enrichment_attempts + 1,
            'enrichment_error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        Log::warning('Companies House enrichment failed', [
            'company_id' => $company->id,
            'company_number' => $company->company_number,
            'attempts' => $company->enrichment_attempts,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function string(mixed $value, ?int $limit = null): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit === null ? $value : mb_substr($value, 0, $limit);
    }

    private function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
