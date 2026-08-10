<?php

namespace App\Services\Candidates;

/**
 * The outcome of scoring a title: a 0-100 headline figure plus the working
 * behind it, so the dashboard can explain why a candidate ranks where it does.
 */
readonly class ScoreResult
{
    /**
     * @param  array<string, array{value: float|null, weight: int, points: float, available: bool, note: string}>  $components
     */
    public function __construct(
        public int $score,
        public array $components,
        public int $weightAvailable,
        public int $weightTotal,
    ) {}

    /**
     * Shape persisted to `candidates.score_breakdown`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'weight_available' => $this->weightAvailable,
            'weight_total' => $this->weightTotal,
            'components' => $this->components,
        ];
    }
}
