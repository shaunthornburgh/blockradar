<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompaniesHouse\CompanyEnricher;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enriches a single company from Companies House.
 *
 * One company per job keeps the unit of work small enough that a rate limit
 * costs nothing: the job releases itself back onto the queue with the exact
 * delay the API asked for, and the worker is free in the meantime.
 */
class EnrichCompanyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Long enough to outlast a full rate-limit window plus retries. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $companyId,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return "enrich-company-{$this->companyId}";
    }

    /** Transient failures back off; a rate limit sets its own delay. */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(CompanyEnricher $enricher): void
    {
        $company = Company::find($this->companyId);

        if ($company === null) {
            return;
        }

        try {
            $enricher->enrich($company, $this->force);
        } catch (RateLimitExceededException $e) {
            // Not a failure: put it back and try again when the window frees.
            // release() does not count against tries.
            $this->release($e->retryAfterSeconds);
        } catch (InvalidApiKeyException $e) {
            // Retrying cannot help, and every attempt burns a request.
            Log::error('Companies House enrichment stopped: '.$e->getMessage());

            $this->fail($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('EnrichCompanyJob failed', [
            'company_id' => $this->companyId,
            'message' => $e?->getMessage(),
        ]);
    }
}
