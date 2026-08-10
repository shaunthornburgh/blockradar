<?php

namespace App\Jobs;

use App\Enums\EpcMatchConfidence;
use App\Models\Title;
use App\Services\Epc\TitleEpcEnricher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matches a batch of titles against the loaded EPC certificates.
 *
 * Matching is entirely local — it reads `epc_certificates`, which `epc:import`
 * fills — so there is no rate limit to respect and no network to fail. An
 * individual title that blows up is logged and skipped rather than taking the
 * batch down with it.
 */
class EnrichTitlesEpcJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    /**
     * @param  array<int, int>  $titleIds
     */
    public function __construct(
        public readonly array $titleIds,
        public readonly bool $force = false,
        public readonly ?string $minimumConfidence = null,
    ) {}

    public function handle(TitleEpcEnricher $enricher): void
    {
        $minimum = $this->minimumConfidence !== null
            ? EpcMatchConfidence::tryFrom($this->minimumConfidence)
            : null;

        $matched = 0;
        $failed = 0;

        foreach ($this->titleIds as $titleId) {
            $title = Title::find($titleId);

            if ($title === null) {
                continue;
            }

            if (! $this->force && ! $enricher->needsEnrichment($title)) {
                continue;
            }

            try {
                $set = $enricher->enrich($title, $this->force, $minimum);

                if (! $set->isEmpty()) {
                    $matched++;
                }
            } catch (Throwable $e) {
                $failed++;

                Log::warning('EPC enrichment failed for a title', [
                    'title_id' => $titleId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        Log::info('EPC batch enriched', [
            'titles' => count($this->titleIds),
            'matched' => $matched,
            'failed' => $failed,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('EnrichTitlesEpcJob failed', [
            'titles' => count($this->titleIds),
            'message' => $e?->getMessage(),
        ]);
    }
}
