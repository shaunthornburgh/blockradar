<?php

namespace App\Services\Candidates;

/**
 * Statistics for a rescoring run.
 *
 * Movements are kept as a histogram rather than a list. Scores are integers
 * from 0 to 100, so a movement is an integer from -100 to 100 and 201 buckets
 * describe the run exactly — which means an exact median, and a tally that
 * merges cleanly across queued chunks without shipping every value around.
 */
class RescoreTally
{
    /** Score bands worth knowing a candidate has crossed. */
    public const THRESHOLDS = [60, 70, 80];

    public int $examined = 0;

    /** Rows saved with a fresh score and breakdown. */
    public int $written = 0;

    /** Of those, the ones whose number actually moved. */
    public int $scoreChanged = 0;

    /** Below --min-score-change: only scored_at was stamped. */
    public int $touchedOnly = 0;

    /** Nothing to score — no title attached. */
    public int $skipped = 0;

    /** @var array<int, int> movement in points => how many candidates */
    public array $movements = [];

    /** @var array<int, array{up: int, down: int}> */
    public array $crossings = [];

    public function __construct()
    {
        foreach (self::THRESHOLDS as $threshold) {
            $this->crossings[$threshold] = ['up' => 0, 'down' => 0];
        }
    }

    public function record(int $oldScore, int $newScore): void
    {
        $this->examined++;

        $movement = $newScore - $oldScore;
        $this->movements[$movement] = ($this->movements[$movement] ?? 0) + 1;

        foreach (self::THRESHOLDS as $threshold) {
            if ($oldScore < $threshold && $newScore >= $threshold) {
                $this->crossings[$threshold]['up']++;
            } elseif ($oldScore >= $threshold && $newScore < $threshold) {
                $this->crossings[$threshold]['down']++;
            }
        }
    }

    /** Mean signed movement: positive means scores rose overall. */
    public function meanMovement(): float
    {
        if ($this->examined === 0) {
            return 0.0;
        }

        $total = 0;

        foreach ($this->movements as $movement => $count) {
            $total += $movement * $count;
        }

        return round($total / $this->examined, 2);
    }

    /** Mean of the absolute movement, so rises and falls do not cancel out. */
    public function meanAbsoluteMovement(): float
    {
        if ($this->examined === 0) {
            return 0.0;
        }

        $total = 0;

        foreach ($this->movements as $movement => $count) {
            $total += abs((int) $movement) * $count;
        }

        return round($total / $this->examined, 2);
    }

    /** Exact median signed movement, read straight out of the histogram. */
    public function medianMovement(): float
    {
        if ($this->examined === 0) {
            return 0.0;
        }

        $movements = $this->movements;
        ksort($movements);

        $middle = ($this->examined - 1) / 2;
        $lower = (int) floor($middle);
        $upper = (int) ceil($middle);

        $seen = 0;
        $lowerValue = null;

        foreach ($movements as $movement => $count) {
            $seen += $count;

            if ($lowerValue === null && $seen > $lower) {
                $lowerValue = (int) $movement;
            }

            if ($seen > $upper) {
                return round((($lowerValue ?? (int) $movement) + (int) $movement) / 2, 1);
            }
        }

        return (float) ($lowerValue ?? 0);
    }

    public function largestRise(): int
    {
        $movements = array_keys($this->movements);

        return $movements === [] ? 0 : max(0, max($movements));
    }

    public function largestFall(): int
    {
        $movements = array_keys($this->movements);

        return $movements === [] ? 0 : min(0, min($movements));
    }

    public function merge(self $other): void
    {
        $this->examined += $other->examined;
        $this->written += $other->written;
        $this->scoreChanged += $other->scoreChanged;
        $this->touchedOnly += $other->touchedOnly;
        $this->skipped += $other->skipped;

        foreach ($other->movements as $movement => $count) {
            $this->movements[$movement] = ($this->movements[$movement] ?? 0) + $count;
        }

        foreach ($other->crossings as $threshold => $counts) {
            $this->crossings[$threshold]['up'] += $counts['up'];
            $this->crossings[$threshold]['down'] += $counts['down'];
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'written' => $this->written,
            'score_changed' => $this->scoreChanged,
            'touched_only' => $this->touchedOnly,
            'skipped' => $this->skipped,
            'movements' => $this->movements,
            'crossings' => $this->crossings,
            'mean_movement' => $this->meanMovement(),
            'mean_absolute_movement' => $this->meanAbsoluteMovement(),
            'median_movement' => $this->medianMovement(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tally = new self;

        $tally->examined = (int) ($data['examined'] ?? 0);
        $tally->written = (int) ($data['written'] ?? 0);
        $tally->scoreChanged = (int) ($data['score_changed'] ?? 0);
        $tally->touchedOnly = (int) ($data['touched_only'] ?? 0);
        $tally->skipped = (int) ($data['skipped'] ?? 0);

        foreach ((array) ($data['movements'] ?? []) as $movement => $count) {
            $tally->movements[(int) $movement] = (int) $count;
        }

        foreach ((array) ($data['crossings'] ?? []) as $threshold => $counts) {
            $tally->crossings[(int) $threshold] = [
                'up' => (int) ($counts['up'] ?? 0),
                'down' => (int) ($counts['down'] ?? 0),
            ];
        }

        return $tally;
    }
}
