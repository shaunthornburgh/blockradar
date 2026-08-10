<?php

namespace App\Http\Controllers\Api;

use App\Enums\PipelineStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CandidateController extends Controller
{
    /** Columns the client is allowed to sort on. */
    private const SORTABLE = ['score', 'created_at', 'updated_at', 'next_action_at', 'estimated_units'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'stage' => ['sometimes', 'string'],
            'min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'region' => ['sometimes', 'string', 'max:120'],
            'search' => ['sometimes', 'string', 'max:120'],
            'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'score';

        $candidates = Candidate::query()
            ->with(['title.company', 'assignedTo'])
            ->withCount('notes')
            // See TitleController: $request->boolean() copes with "true"/"false".
            ->where('is_archived', $request->boolean('archived'))
            ->when(
                isset($filters['stage']) && PipelineStage::tryFrom($filters['stage']),
                fn ($query) => $query->where('stage', $filters['stage'])
            )
            ->when(
                isset($filters['min_score']),
                fn ($query) => $query->where('score', '>=', $filters['min_score'])
            )
            ->when(
                isset($filters['region']),
                fn ($query) => $query->whereHas('title', fn ($q) => $q->where('region', $filters['region']))
            )
            ->when(
                isset($filters['search']),
                fn ($query) => $query->whereHas('title', function ($q) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    // Grouped so the OR set does not escape the relation constraint.
                    $q->where(function ($inner) use ($term) {
                        $inner->where('property_address', 'like', $term)
                            ->orWhere('title_number', 'like', $term)
                            ->orWhere('postcode', 'like', $term)
                            ->orWhere('proprietor_name', 'like', $term);
                    });
                })
            )
            ->orderBy($sort, $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return CandidateResource::collection($candidates);
    }

    public function show(Candidate $candidate): CandidateResource
    {
        return CandidateResource::make(
            $candidate->load(['title.company', 'assignedTo', 'notes.user'])
        );
    }

    public function update(UpdateCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        $data = $request->validated();

        // Route stage changes through moveTo() so the stage timestamp is set.
        if (isset($data['stage'])) {
            $candidate->moveTo(PipelineStage::from($data['stage']));
            unset($data['stage']);
        }

        if ($data !== []) {
            $candidate->update($data);
        }

        return CandidateResource::make(
            $candidate->fresh()->load(['title.company', 'assignedTo'])
        );
    }
}
