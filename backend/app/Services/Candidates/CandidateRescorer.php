<?php

namespace App\Services\Candidates;

use App\Models\AreaMetric;
use App\Models\Candidate;
use App\Models\Title;
use Illuminate\Support\Collection;

/**
 * Recomputes candidate scores against whatever data exists now.
 *
 * Scores were written once, at creation, before Companies House and EPC
 * enrichment existed. This brings them up to date without disturbing anything
 * a person has touched.
 *
 * Exactly three columns are ever written: `score`, `score_breakdown` and
 * `scored_at`. Stage, assignee, notes and the estimate fields the API lets
 * users edit — estimated_units, estimated_gdv, estimated_uplift, gross_yield —
 * are left alone, because any of them may be a deliberate override.
 */
class CandidateRescorer
{
    public function __construct(private readonly CandidateScorer $scorer) {}

    /**
     * Rescores a chunk of candidates. Callers pass them already loaded with
     * `title.company`; area metrics are fetched once per chunk.
     *
     * @param  Collection<int, Candidate>  $candidates
     */
    public function rescore(Collection $candidates, RescoreOptions $options, RescoreTally $tally): void
    {
        $areas = $this->areaMetricsFor($candidates);

        foreach ($candidates as $candidate) {
            $title = $candidate->title;

            if ($title === null) {
                $tally->skipped++;

                continue;
            }

            $result = $this->scorer->score($title, $areas[$title->postcodeDistrict()] ?? null);

            $oldScore = (int) $candidate->score;
            $newScore = $result->score;

            $tally->record($oldScore, $newScore);

            if (abs($newScore - $oldScore) < $options->minScoreChange) {
                // Not a material move. Stamp scored_at anyway so a --limit-ed
                // run keeps making progress instead of re-examining the same
                // candidates on every pass.
                if (! $options->dryRun) {
                    $candidate->forceFill(['scored_at' => now()])->save();
                }

                $tally->touchedOnly++;

                continue;
            }

            if (! $options->dryRun) {
                $candidate->forceFill([
                    'score' => $newScore,
                    'score_breakdown' => $result->toArray(),
                    'scored_at' => now(),
                ])->save();
            }

            $tally->written++;

            if ($newScore !== $oldScore) {
                $tally->scoreChanged++;
            }
        }
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @return array<string, AreaMetric>
     */
    private function areaMetricsFor(Collection $candidates): array
    {
        $districts = $candidates
            ->map(fn (Candidate $candidate) => $candidate->title?->postcodeDistrict())
            ->filter()
            ->unique()
            ->values();

        if ($districts->isEmpty()) {
            return [];
        }

        return AreaMetric::query()
            ->whereIn('postcode_district', $districts)
            ->get()
            ->keyBy('postcode_district')
            ->all();
    }

    /**
     * Loads a chunk in the shape rescore() expects.
     *
     * @param  array<int, int>  $candidateIds
     * @return Collection<int, Candidate>
     */
    public function load(array $candidateIds): Collection
    {
        return Candidate::query()
            // company is what the Companies House components read, and the EPC
            // aggregates live on the title itself.
            ->with(['title' => fn ($query) => $query->with('company')])
            ->whereIn('id', $candidateIds)
            ->get();
    }

    /** @return Collection<int, Title> */
    public function titlesFor(Collection $candidates): Collection
    {
        return $candidates->map(fn (Candidate $candidate) => $candidate->title)->filter();
    }
}
