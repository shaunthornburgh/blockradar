<?php

namespace App\Services\Epc;

use App\Enums\EpcMatchConfidence;

/**
 * Match-quality statistics for one enrichment run.
 */
class EpcEnrichmentTally
{
    public int $processed = 0;

    public int $matched = 0;

    public int $unmatched = 0;

    public int $skipped = 0;

    /** Matched, but below the configured confidence floor, so not written. */
    public int $belowThreshold = 0;

    public int $unitCountsImproved = 0;

    /** @var array<string, int> */
    public array $byConfidence = [
        'high' => 0,
        'medium' => 0,
        'low' => 0,
    ];

    /** @var array<string, int> */
    public array $byMethod = [];

    public int $certificatesLinked = 0;

    public function record(EpcMatchSet $set): void
    {
        if ($set->confidence !== null) {
            $this->byConfidence[$set->confidence->value]++;
        }

        if ($set->method !== null) {
            $this->byMethod[$set->method->value] = ($this->byMethod[$set->method->value] ?? 0) + 1;
        }
    }

    public function matchRate(): float
    {
        $considered = $this->processed - $this->skipped;

        return $considered > 0 ? round($this->matched / $considered * 100, 1) : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'matched' => $this->matched,
            'unmatched' => $this->unmatched,
            'skipped' => $this->skipped,
            'below_threshold' => $this->belowThreshold,
            'match_rate' => $this->matchRate(),
            'by_confidence' => $this->byConfidence,
            'by_method' => $this->byMethod,
            'certificates_linked' => $this->certificatesLinked,
            'unit_counts_improved' => $this->unitCountsImproved,
            'minimum_confidence' => (string) config('blockradar.epc.match.min_confidence', EpcMatchConfidence::Medium->value),
        ];
    }
}
