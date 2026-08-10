<?php

namespace App\Services\CompaniesHouse;

/**
 * What happened to one company during an enrichment run. Distinct from
 * EnrichmentStatus, which is the state persisted on the company: a company
 * that was skipped keeps whatever status it already had.
 */
enum EnrichmentOutcome: string
{
    case Enriched = 'enriched';
    case Skipped = 'skipped';
    case NotFound = 'not_found';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Enriched => 'Enriched',
            self::Skipped => 'Skipped (recently enriched)',
            self::NotFound => 'Not found at Companies House',
            self::Failed => 'Failed',
        };
    }
}
