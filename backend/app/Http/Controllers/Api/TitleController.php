<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TitleResource;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TitleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'region' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Read separately: the `boolean` rule rejects the "true"/"false"
        // strings a JavaScript client puts in a query string, whereas
        // $request->boolean() understands them.
        $splitOnly = $request->boolean('split_only');

        $titles = Title::query()
            ->with('company')
            // One EXISTS per row, so the list can say which titles are already
            // in the pipeline without loading the candidates themselves.
            ->withExists('candidate')
            ->when($splitOnly, fn ($query) => $query->splitCandidates())
            ->when(isset($filters['region']), fn ($query) => $query->inRegion($filters['region']))
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';

                // Grouped so the OR set does not override the other filters.
                $query->where(function ($inner) use ($term) {
                    $inner->where('property_address', 'like', $term)
                        ->orWhere('title_number', 'like', $term)
                        ->orWhere('postcode', 'like', $term);
                });
            })
            ->orderByDesc('date_proprietor_added')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return TitleResource::collection($titles);
    }

    /**
     * The read-only research view of one title: the CCOD row, its proprietor
     * company with the Companies House signals, the matched EPC certificates,
     * and whether it reached the MUFB pipeline.
     */
    public function show(Title $title): TitleResource
    {
        $title->load([
            'company',
            'candidate',
            // Freshest survey first — that is the one the aggregates came from.
            'epcCertificates' => fn ($query) => $query
                ->orderByDesc('lodgement_date')
                ->limit(100),
        ]);

        // The candidate summary reports MUFB confidence, which is derived from
        // the title. Handing it the one already in memory saves a query and
        // guarantees the two describe the same row.
        $title->candidate?->setRelation('title', $title);

        return TitleResource::make($title);
    }
}
