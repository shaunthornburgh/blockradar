<?php

namespace App\Enums;

/**
 * Outcome of the most recent Companies House enrichment attempt.
 */
enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Enriched = 'enriched';
    case NotFound = 'not_found';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enriched => 'Enriched',
            self::NotFound => 'Not found',
            self::Failed => 'Failed',
        };
    }

    /**
     * A company Companies House does not know about will never appear, so
     * retrying it every run just burns rate limit.
     */
    public function isPermanent(): bool
    {
        return $this === self::NotFound;
    }
}
