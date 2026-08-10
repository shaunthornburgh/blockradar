<?php

namespace App\Services\Epc;

use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use App\Services\Epc\Exceptions\EpcApiException;
use App\Services\Epc\Exceptions\EpcAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The MHCLG developer API at
 * api.get-energy-performance-data.communities.gov.uk.
 *
 * Worth being clear about what this can and cannot do. The domestic search
 * endpoint returns a *summary* per certificate — number, address lines,
 * postcode, town, council, constituency, current rating band, registration
 * date and UPRN. It does NOT return floor area, property type, built form,
 * habitable rooms, construction age band or heating.
 *
 * So this is a discovery tool: it finds which certificates exist at an address
 * and what they are rated. Everything the scorer needs beyond that comes from
 * the bulk CSV, which is why `epc:import` is the primary path.
 *
 * Authentication is a bearer token, not the Basic auth the retired
 * epc.opendatacommunities.org API used.
 */
class EpcApiClient
{
    private const THROTTLE_KEY = 'epc-api:requests';

    private const WINDOW_SECONDS = 300;

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Every certificate the API knows about in a postcode.
     *
     * @return array<int, EpcRow>
     *
     * @throws RateLimitExceededException|EpcAuthException|EpcApiException
     */
    public function searchByPostcode(string $postcode): array
    {
        return $this->search(['postcode' => strtoupper(trim($postcode))]);
    }

    /**
     * @return array<int, EpcRow>
     */
    public function searchByUprn(string $uprn): array
    {
        return $this->search(['uprn' => str_pad(preg_replace('/\D/', '', $uprn) ?? '', 12, '0', STR_PAD_LEFT)]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, EpcRow>
     */
    private function search(array $query): array
    {
        if (! $this->isConfigured()) {
            throw new EpcAuthException(
                'EPC_API_TOKEN is not set. Sign in at '.
                'https://get-energy-performance-data.communities.gov.uk and copy the bearer token from your account page.'
            );
        }

        $pageSize = max(1, min(5000, (int) config('blockradar.epc.api.page_size', 100)));
        $path = (string) config('blockradar.epc.api.domestic_search_path', '/api/domestic/search');

        $payload = $this->get($path, $query + [
            'page_size' => $pageSize,
            'current_page' => 1,
        ]);

        $rows = [];

        foreach ($payload['data'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = EpcRow::fromArray($this->normaliseKeys($item));

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The API returns camelCase; EpcRow reads the same lowercase-alphanumeric
     * keys the CSV reader produces.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normaliseKeys(array $item): array
    {
        $out = [];

        foreach ($item as $key => $value) {
            $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));

            $out[$normalised] = is_scalar($value) ? (string) $value : $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        if (($waitFor = $this->reserveSlot()) !== null) {
            throw new RateLimitExceededException($waitFor);
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('blockradar.epc.api.base_url'), '/'))
                ->withToken($this->token())
                ->acceptJson()
                ->timeout((int) config('blockradar.epc.api.timeout', 15))
                // Connection blips only; HTTP statuses are handled below so a
                // 429 costs one request rather than three.
                ->retry(2, 250, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new EpcApiException("Could not reach the EPC API: {$e->getMessage()}", previous: $e);
        }

        if ($response->successful()) {
            /** @var array<string, mixed> $data */
            $data = $response->json() ?? [];

            return $data;
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new EpcAuthException("The EPC API rejected the bearer token ({$status}). Check EPC_API_TOKEN.");
        }

        if ($status === 429) {
            $this->exhaustWindow();

            $retryAfter = (int) ($response->header('Retry-After')
                ?: config('blockradar.epc.api.default_retry_after', 60));

            Log::warning('EPC API returned 429', ['retry_after' => $retryAfter]);

            throw new RateLimitExceededException(max(1, $retryAfter), 'The EPC API returned 429.');
        }

        if ($status === 404) {
            return ['data' => []];
        }

        throw new EpcApiException(sprintf(
            'EPC API %s returned %d: %s',
            $path,
            $status,
            mb_substr($response->body(), 0, 300)
        ));
    }

    /**
     * Documented limit is 6000 requests per 5 minutes per IP. Shared through
     * the cache so every worker draws on the same budget.
     */
    private function reserveSlot(): ?int
    {
        $limit = max(1, (int) config('blockradar.epc.api.rate_limit_per_five_minutes', 5500));

        if (RateLimiter::tooManyAttempts(self::THROTTLE_KEY, $limit)) {
            return max(1, RateLimiter::availableIn(self::THROTTLE_KEY));
        }

        RateLimiter::hit(self::THROTTLE_KEY, self::WINDOW_SECONDS);

        return null;
    }

    private function exhaustWindow(): void
    {
        $limit = max(1, (int) config('blockradar.epc.api.rate_limit_per_five_minutes', 5500));

        for ($i = RateLimiter::remaining(self::THROTTLE_KEY, $limit); $i > 0; $i--) {
            RateLimiter::hit(self::THROTTLE_KEY, self::WINDOW_SECONDS);
        }
    }

    public function remainingRequests(): int
    {
        return RateLimiter::remaining(
            self::THROTTLE_KEY,
            max(1, (int) config('blockradar.epc.api.rate_limit_per_five_minutes', 5500))
        );
    }

    private function token(): string
    {
        return trim((string) config('blockradar.epc.api.token', ''));
    }
}
