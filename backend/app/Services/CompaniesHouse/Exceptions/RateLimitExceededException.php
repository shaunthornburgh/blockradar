<?php

namespace App\Services\CompaniesHouse\Exceptions;

/**
 * Thrown before a request is made when the local throttle is exhausted, and
 * also when Companies House itself returns 429.
 *
 * Carries the number of seconds to wait, so a queued job can release itself
 * back onto the queue instead of blocking a worker.
 */
class RateLimitExceededException extends CompaniesHouseException
{
    public function __construct(public readonly int $retryAfterSeconds, string $message = '')
    {
        parent::__construct(
            $message !== '' ? $message : "Companies House rate limit reached; retry in {$retryAfterSeconds}s."
        );
    }
}
