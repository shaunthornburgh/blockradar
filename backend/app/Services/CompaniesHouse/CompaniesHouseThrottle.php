<?php

namespace App\Services\CompaniesHouse;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A shared budget of Companies House requests, held in the cache.
 *
 * Redis-backed in every environment that matters, so the limit is respected
 * across all queue workers and any CLI run happening at the same time — not
 * per process.
 */
class CompaniesHouseThrottle
{
    private const KEY = 'companies-house:requests';

    private const WINDOW_SECONDS = 300;

    /**
     * Reserves one request. Returns null when the caller may proceed, or the
     * number of seconds to wait when the budget is spent.
     */
    public function reserve(): ?int
    {
        $limit = $this->limit();

        if (RateLimiter::tooManyAttempts(self::KEY, $limit)) {
            return max(1, RateLimiter::availableIn(self::KEY));
        }

        RateLimiter::hit(self::KEY, self::WINDOW_SECONDS);

        return null;
    }

    public function remaining(): int
    {
        return RateLimiter::remaining(self::KEY, $this->limit());
    }

    public function availableIn(): int
    {
        return max(0, RateLimiter::availableIn(self::KEY));
    }

    /**
     * Records that Companies House throttled us regardless of our own count,
     * which means the local budget is out of step with theirs. Burning the
     * rest of the window is the safe response.
     */
    public function exhaustWindow(): void
    {
        $limit = $this->limit();

        for ($i = $this->remaining(); $i > 0; $i--) {
            RateLimiter::hit(self::KEY, self::WINDOW_SECONDS);
        }

        unset($limit);
    }

    public function reset(): void
    {
        RateLimiter::clear(self::KEY);
    }

    public function limit(): int
    {
        return max(1, (int) config('blockradar.companies_house.rate_limit_per_five_minutes', 550));
    }

    public function windowSeconds(): int
    {
        return self::WINDOW_SECONDS;
    }
}
