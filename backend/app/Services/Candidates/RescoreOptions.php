<?php

namespace App\Services\Candidates;

/**
 * How one rescoring pass should behave.
 */
readonly class RescoreOptions
{
    /**
     * @param  int  $minScoreChange  Points the score must move before the change is
     *                               written. Zero writes every recomputation, which
     *                               keeps score_breakdown in step with the data.
     */
    public function __construct(
        public bool $dryRun = false,
        public int $minScoreChange = 0,
    ) {}
}
