<?php

namespace App\Jobs;

use App\Services\Candidates\CandidateRescorer;
use App\Services\Candidates\RescoreOptions;
use App\Services\Candidates\RescoreTally;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rescores one chunk of candidates.
 *
 * Batchable, so a run over tens of thousands of candidates reports real
 * progress and can be cancelled. Each chunk merges its statistics into a
 * shared cache entry keyed on the batch, which is what lets the command print
 * an exact median across the whole run rather than per chunk.
 */
class RescoreCandidatesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /** Statistics survive a little past the run so the summary can be read. */
    private const TALLY_TTL_SECONDS = 3600;

    /**
     * @param  array<int, int>  $candidateIds
     */
    public function __construct(
        public readonly array $candidateIds,
        public readonly int $minScoreChange = 0,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(CandidateRescorer $rescorer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tally = new RescoreTally;

        $rescorer->rescore(
            $rescorer->load($this->candidateIds),
            new RescoreOptions(dryRun: $this->dryRun, minScoreChange: $this->minScoreChange),
            $tally
        );

        $this->publish($tally);
    }

    public static function tallyKey(string $batchId): string
    {
        return "candidates-rescore:{$batchId}";
    }

    /**
     * Reads back the aggregate for a batch.
     */
    public static function tallyFor(string $batchId): RescoreTally
    {
        $data = Cache::get(self::tallyKey($batchId));

        return is_array($data) ? RescoreTally::fromArray($data) : new RescoreTally;
    }

    /**
     * Merges this chunk's statistics into the batch total.
     *
     * Locked because several workers can finish at the same moment and a
     * read-modify-write would otherwise lose counts.
     */
    private function publish(RescoreTally $tally): void
    {
        $batchId = $this->batch()?->id;

        if ($batchId === null) {
            return;
        }

        $key = self::tallyKey($batchId);

        try {
            Cache::lock($key.':lock', 10)->block(5, function () use ($key, $tally) {
                $total = self::tallyFor(str_replace('candidates-rescore:', '', $key));
                $total->merge($tally);

                Cache::put($key, $total->toArray(), self::TALLY_TTL_SECONDS);
            });
        } catch (Throwable $e) {
            // Losing statistics must never fail the actual rescoring work.
            Log::warning('Could not publish rescore statistics', [
                'batch_id' => $batchId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('RescoreCandidatesJob failed', [
            'candidates' => count($this->candidateIds),
            'message' => $e?->getMessage(),
        ]);
    }
}
