<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use App\Services\Candidates\MufbSignals;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Candidate */
class CandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $mufb = app(MufbSignals::class);

        return [
            'id' => $this->id,
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'stage_order' => $this->stage->order(),
            'score' => $this->score,
            'score_breakdown' => $this->score_breakdown ?? [],
            'scored_at' => $this->scored_at?->toIso8601String(),
            'estimated_units' => $this->estimated_units,

            // The unit count the list should actually show, and where it came
            // from: 'epc' means certificates were counted, 'estimate' means it
            // was read out of the address. See MufbSignals::unitsFor().
            'units' => $mufb->unitsFor($this->resource),
            'units_source' => $mufb->unitSourceFor($this->resource),

            // Derived per request, not stored — retuning config/blockradar.php
            // moves this without a rescore.
            'mufb' => $mufb->forCandidate($this->resource),

            'estimated_gdv' => $this->estimated_gdv,
            'estimated_uplift' => $this->estimated_uplift,
            'gross_yield' => $this->gross_yield !== null ? (float) $this->gross_yield : null,
            'next_action_at' => $this->next_action_at?->toDateString(),
            'is_archived' => $this->is_archived,
            'title_bought_at' => $this->title_bought_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'outreach_at' => $this->outreach_at?->toIso8601String(),
            'offered_at' => $this->offered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'title' => TitleResource::make($this->whenLoaded('title')),
            'assigned_to' => UserResource::make($this->whenLoaded('assignedTo')),
            'notes' => CandidateNoteResource::collection($this->whenLoaded('notes')),
            'notes_count' => $this->whenCounted('notes'),
        ];
    }
}
