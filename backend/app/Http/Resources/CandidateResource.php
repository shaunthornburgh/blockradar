<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Candidate */
class CandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'stage_order' => $this->stage->order(),
            'score' => $this->score,
            'score_breakdown' => $this->score_breakdown ?? [],
            'scored_at' => $this->scored_at?->toIso8601String(),
            'estimated_units' => $this->estimated_units,
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
