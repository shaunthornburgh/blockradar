<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompaniesHouse\CompanyEnricher;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enriches a batch of companies in one job.
 *
 * Cheaper than one job per company when there are tens of thousands to get
 * through. On hitting the rate limit the job releases itself with only the
 * companies it has not reached yet, so no work is repeated and nothing is
 * lost — the batch is resumable rather than restarted.
 */
class EnrichCompaniesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Generous: a batch can sit through more than one rate-limit window. */
    public int $timeout = 1800;

    /**
     * @param  array<int, int>  $companyIds
     */
    public function __construct(
        public readonly array $companyIds,
        public readonly bool $force = false,
    ) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CompanyEnricher $enricher): void
    {
        $remaining = $this->companyIds;

        foreach ($this->companyIds as $index => $companyId) {
            $company = Company::find($companyId);

            if ($company === null) {
                array_shift($remaining);

                continue;
            }

            try {
                $enricher->enrich($company, $this->force);
            } catch (RateLimitExceededException $e) {
                // Requeue exactly what is left, including this company.
                self::dispatch($remaining, $this->force)->delay($e->retryAfterSeconds);

                Log::info('Companies House batch paused for rate limit', [
                    'remaining' => count($remaining),
                    'retry_after' => $e->retryAfterSeconds,
                ]);

                return;
            } catch (InvalidApiKeyException $e) {
                Log::error('Companies House enrichment stopped: '.$e->getMessage());

                $this->fail($e);

                return;
            }

            array_shift($remaining);
            unset($index);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('EnrichCompaniesJob failed', [
            'companies' => count($this->companyIds),
            'message' => $e?->getMessage(),
        ]);
    }
}
