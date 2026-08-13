<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use App\Services\Candidates\MufbSignals;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Just enough of a candidate to link to it from somewhere else.
 *
 * Deliberately not CandidateResource: that embeds the title, and the title
 * embeds this, so the pair would nest forever. This is also all the title
 * detail page needs — it links across to the candidate rather than trying to
 * be a second copy of it.
 *
 * @mixin Candidate
 */
class CandidateSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'score' => $this->score,
            'scored_at' => $this->scored_at?->toIso8601String(),
            'mufb' => app(MufbSignals::class)->forCandidate($this->resource),
            'is_archived' => $this->is_archived,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
