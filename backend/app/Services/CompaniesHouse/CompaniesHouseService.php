<?php

namespace App\Services\CompaniesHouse;

use App\Services\CompaniesHouse\Exceptions\CompaniesHouseException;
use App\Services\CompaniesHouse\Exceptions\CompanyNotFoundException;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the Companies House public API.
 *
 * Authentication is HTTP Basic with the API key as the username and an empty
 * password, which is how Companies House expects it.
 *
 * Every request passes through the shared throttle first. When the budget is
 * spent this throws rather than sleeping, so the caller decides what to do:
 * a queued job releases itself, the Artisan command waits.
 */
class CompaniesHouseService
{
    public function __construct(private readonly CompaniesHouseThrottle $throttle) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function throttle(): CompaniesHouseThrottle
    {
        return $this->throttle;
    }

    /**
     * The company profile: status, type, incorporation, address, SIC codes,
     * accounts and confirmation statement.
     *
     * @return array<string, mixed>
     *
     * @throws CompanyNotFoundException|RateLimitExceededException|InvalidApiKeyException|CompaniesHouseException
     */
    public function profile(string $companyNumber): array
    {
        return $this->get("/company/{$this->normalise($companyNumber)}", $companyNumber);
    }

    /**
     * Total registered charges. Only worth calling when the profile reports
     * has_charges, since it is a whole extra request.
     *
     * Returns null when the endpoint is unavailable for this company rather
     * than failing the enrichment: the boolean from the profile is the
     * important part and the count is a bonus.
     */
    public function chargesCount(string $companyNumber): ?int
    {
        try {
            $payload = $this->get("/company/{$this->normalise($companyNumber)}/charges", $companyNumber, [
                'items_per_page' => 1,
            ]);
        } catch (CompanyNotFoundException) {
            // No charges filed at all is served as a 404 by this endpoint.
            return 0;
        }

        $count = $payload['total_count'] ?? null;

        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * Number of currently appointed officers.
     */
    public function officerCount(string $companyNumber): ?int
    {
        try {
            $payload = $this->get("/company/{$this->normalise($companyNumber)}/officers", $companyNumber, [
                'items_per_page' => 1,
            ]);
        } catch (CompanyNotFoundException) {
            return null;
        }

        $count = $payload['active_count'] ?? $payload['total_results'] ?? null;

        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, string $companyNumber, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new InvalidApiKeyException(
                'COMPANIES_HOUSE_API_KEY is not set. Get a free key at '.
                'https://developer.company-information.service.gov.uk and add it to backend/.env.'
            );
        }

        if (($waitFor = $this->throttle->reserve()) !== null) {
            throw new RateLimitExceededException($waitFor);
        }

        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw new CompaniesHouseException(
                "Could not reach Companies House for {$companyNumber}: {$e->getMessage()}",
                previous: $e
            );
        }

        return $this->handle($response, $companyNumber, $path);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('blockradar.companies_house.base_url'), '/'))
            ->withBasicAuth($this->apiKey(), '')
            ->acceptJson()
            ->timeout((int) config('blockradar.companies_house.timeout', 15))
            // Retry ONLY connection-level failures. Without this callback the
            // client retries every unsuccessful response, so a single 429
            // would cost three requests while we were already over the limit —
            // and the throttle, which reserves one slot per call, would lose
            // track of how much budget had really been spent.
            //
            // HTTP errors are handled below instead: 404 and 401 are
            // definitive, 429 carries its own Retry-After, and a 5xx is left
            // to the job's backoff so the retry passes the throttle again.
            ->retry(2, 250, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response, string $companyNumber, string $path): array
    {
        if ($response->successful()) {
            /** @var array<string, mixed> $data */
            $data = $response->json() ?? [];

            return $data;
        }

        $status = $response->status();

        if ($status === 404) {
            throw new CompanyNotFoundException($companyNumber);
        }

        if ($status === 401 || $status === 403) {
            throw new InvalidApiKeyException(
                "Companies House rejected the API key ({$status}). Check COMPANIES_HOUSE_API_KEY."
            );
        }

        if ($status === 429) {
            // Their count and ours have diverged, so stop using this window.
            $this->throttle->exhaustWindow();

            $retryAfter = (int) ($response->header('Retry-After')
                ?: config('blockradar.companies_house.default_retry_after', 60));

            Log::warning('Companies House returned 429', [
                'company_number' => $companyNumber,
                'retry_after' => $retryAfter,
            ]);

            throw new RateLimitExceededException(max(1, $retryAfter), 'Companies House returned 429.');
        }

        throw new CompaniesHouseException(sprintf(
            'Companies House %s returned %d for %s: %s',
            $path,
            $status,
            $companyNumber,
            mb_substr($response->body(), 0, 300)
        ));
    }

    private function apiKey(): string
    {
        return trim((string) config('blockradar.companies_house.api_key', ''));
    }

    private function normalise(string $companyNumber): string
    {
        return rawurlencode(strtoupper(trim($companyNumber)));
    }
}
