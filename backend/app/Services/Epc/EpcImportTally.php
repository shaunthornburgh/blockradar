<?php

namespace App\Services\Epc;

/**
 * Counters for one bulk EPC load.
 */
class EpcImportTally
{
    public int $rowsRead = 0;

    public int $rowsImported = 0;

    public int $rowsSkipped = 0;

    /** Dropped because we hold no title in that postcode. */
    public int $rowsOutsidePortfolio = 0;

    public int $rowsFailed = 0;

    public int $certificatesCreated = 0;

    public int $certificatesUpdated = 0;

    /** Number of distinct postcodes in the filter, or null when unfiltered. */
    public ?int $postcodeFilterSize = null;
}
