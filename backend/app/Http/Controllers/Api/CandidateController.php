<?php

namespace App\Http\Controllers\Api;

use App\Enums\EpcMatchConfidence;
use App\Enums\PipelineStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCandidateRequest;
use App\Http\Requests\UpdateCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Title;
use App\Services\Candidates\MufbSignals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class CandidateController extends Controller
{
    /**
     * Sort keys the client may ask for, mapped to what they order by.
     *
     * `units` and `mufb` are expressions rather than columns, so they are
     * resolved in applySort() instead. The bare column names are listed here
     * once and reused by the request's validation rules.
     */
    public const SORTS = [
        'score' => 'candidates.score',
        'units' => null,
        'mufb' => null,
        'epc_certificate_count' => 'titles.epc_certificate_count',
        'created_at' => 'candidates.created_at',
        'updated_at' => 'candidates.updated_at',
        'scored_at' => 'candidates.scored_at',
        'next_action_at' => 'candidates.next_action_at',
        // Kept so links saved before `units` existed still sort sensibly.
        'estimated_units' => 'candidates.estimated_units',
    ];

    public function __construct(private readonly MufbSignals $mufb) {}

    /**
     * The candidate list, filtered towards blocks of flats.
     *
     * Titles and companies are joined rather than reached through whereHas.
     * The join is on `candidates.title_id`, which is unique and foreign-keyed,
     * so it cannot duplicate a row; in exchange every filter, the search and
     * the MUFB expression read plain columns instead of stacking one
     * correlated subquery per filter.
     */
    public function index(IndexCandidateRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $candidates = Candidate::query()
            ->select('candidates.*')
            ->join('titles', 'titles.id', '=', 'candidates.title_id')
            // Left, because a CCOD proprietor we could not resolve to a
            // Companies House record leaves company_id null — and that title
            // is still a candidate.
            ->leftJoin('companies', 'companies.id', '=', 'titles.company_id')
            ->with(['title.company', 'assignedTo'])
            ->withCount('notes')
            // See TitleController: $request->boolean() copes with "true"/"false".
            ->where('candidates.is_archived', $request->boolean('archived'));

        $this->applyFilters($candidates, $request, $filters);
        $this->applySort($candidates, $filters);

        return CandidateResource::collection(
            $candidates->paginate($filters['per_page'] ?? 25)->withQueryString()
        );
    }

    /**
     * The values worth offering in the region and postcode-area pickers.
     *
     * Taken from the candidate population rather than from all 3.7M titles,
     * so the list is short and every option returns something. Cached because
     * it only moves when a CCOD import lands.
     */
    public function filterOptions(): JsonResponse
    {
        $options = Cache::remember('candidates:filter-options', now()->addHour(), function () {
            $titles = Title::query()->whereHas('candidate');

            $regions = (clone $titles)
                ->select('region')
                ->whereNotNull('region')
                ->distinct()
                ->orderBy('region')
                ->pluck('region')
                ->all();

            // Postcode area = the letters at the front of the outward code.
            // Derived in PHP: no index can serve a substring on 3.8k rows any
            // faster than reading them.
            $areas = (clone $titles)
                ->whereNotNull('postcode')
                ->pluck('postcode')
                ->map(fn (string $postcode) => strtoupper(
                    (string) preg_replace('/[0-9].*$/', '', explode(' ', trim($postcode))[0])
                ))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            return ['regions' => $regions, 'postcode_areas' => $areas];
        });

        return response()->json(['data' => $options]);
    }

    /**
     * Everything the detail page needs in one request: the title with its EPC
     * aggregates and matched certificates, the proprietor company with its
     * Companies House signals, the notes timeline and the assignee.
     */
    public function show(Candidate $candidate): CandidateResource
    {
        return CandidateResource::make($candidate->load([
            'title.company',
            // Freshest survey first — that is the one the aggregates came from.
            'title.epcCertificates' => fn ($query) => $query
                ->orderByDesc('lodgement_date')
                ->limit(100),
            'assignedTo',
            'notes.user',
        ]));
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

    /**
     * @param  Builder<Candidate>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, IndexCandidateRequest $request, array $filters): void
    {
        $query
            ->when(
                isset($filters['stage']),
                fn (Builder $q) => $q->where('candidates.stage', $filters['stage'])
            )
            ->when(
                isset($filters['min_score']),
                fn (Builder $q) => $q->where('candidates.score', '>=', $filters['min_score'])
            )
            ->when(
                isset($filters['max_score']),
                fn (Builder $q) => $q->where('candidates.score', '<=', $filters['max_score'])
            )
            ->when(
                isset($filters['region']),
                fn (Builder $q) => $q->where('titles.region', $filters['region'])
            )
            ->when(
                isset($filters['postcode_area']),
                // Postcode area is the letters at the front of the outward
                // code — "M" for M14 5TP. A prefix LIKE, so the index on
                // titles.postcode is still usable.
                fn (Builder $q) => $q->where('titles.postcode', 'like', strtoupper($filters['postcode_area']).'%')
            )
            ->when(
                isset($filters['min_epc_certificates']),
                fn (Builder $q) => $q->where('titles.epc_certificate_count', '>=', $filters['min_epc_certificates'])
            )
            ->when(isset($filters['search']), fn (Builder $q) => $this->applySearch($q, $filters['search']));

        if ($request->filled('has_epc')) {
            $this->applyEpcFilter($query, $request->boolean('has_epc'));
        }

        if ($request->filled('has_charges')) {
            $this->applyChargesFilter($query, $request->boolean('has_charges'));
        }

        if ($request->boolean('company_distressed')) {
            Company::applyDistressFilter($query);
        }

        if (isset($filters['min_units'])) {
            $this->applyUnitsFilter($query, (int) $filters['min_units'], $request->boolean('include_unknown_units'));
        }

        if (isset($filters['min_mufb'])) {
            [$sql, $bindings] = $this->mufb->confidenceExpression();

            $query->whereRaw($sql.' >= ?', array_merge($bindings, [$this->resolveMinMufb($filters['min_mufb'])]));
        }
    }

    /**
     * Address, title number, postcode, the CCOD proprietor name and the
     * matched Companies House name.
     *
     * @param  Builder<Candidate>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';

        // Grouped so the OR set cannot escape the other filters.
        $query->where(function (Builder $inner) use ($like) {
            $inner->where('titles.property_address', 'like', $like)
                ->orWhere('titles.title_number', 'like', $like)
                ->orWhere('titles.postcode', 'like', $like)
                ->orWhere('titles.proprietor_name', 'like', $like)
                ->orWhere('companies.name', 'like', $like)
                ->orWhere('companies.company_number', 'like', $like);
        });
    }

    /**
     * "Has an EPC" means a match good enough to count on — the same bar
     * Title::hasUsableEpc() applies. A postcode-only match would have
     * attached the neighbours' certificates, so it is not evidence of
     * anything about this building.
     *
     * @param  Builder<Candidate>  $query
     */
    private function applyEpcFilter(Builder $query, bool $hasEpc): void
    {
        $usable = [EpcMatchConfidence::Medium->value, EpcMatchConfidence::High->value];

        if ($hasEpc) {
            $query->whereIn('titles.epc_match_confidence', $usable)
                ->where('titles.epc_certificate_count', '>=', 1);

            return;
        }

        $query->where(function (Builder $inner) use ($usable) {
            $inner->whereNotIn('titles.epc_match_confidence', $usable)
                ->orWhereNull('titles.epc_match_confidence')
                ->orWhere('titles.epc_certificate_count', '<', 1);
        });
    }

    /**
     * Registered charges at Companies House.
     *
     * "No charges" also covers a title whose proprietor never resolved to a
     * Companies House record, so that yes and no between them account for
     * every candidate rather than quietly dropping the unmatched ones.
     *
     * @param  Builder<Candidate>  $query
     */
    private function applyChargesFilter(Builder $query, bool $hasCharges): void
    {
        if ($hasCharges) {
            $query->where('companies.has_charges', true);

            return;
        }

        $query->where(function (Builder $inner) {
            $inner->where('companies.has_charges', false)
                ->orWhereNull('companies.id');
        });
    }

    /**
     * Filters on the best unit count available for the title.
     *
     * Titles whose unit count could not be worked out at all are dropped by
     * default — with a minimum set they would otherwise be the bulk of the
     * list. `include_unknown_units=true` keeps them, which matters before EPC
     * enrichment has reached a region.
     *
     * @param  Builder<Candidate>  $query
     */
    private function applyUnitsFilter(Builder $query, int $minimum, bool $includeUnknown): void
    {
        $units = $this->mufb->unitsSql();

        $query->where(function (Builder $inner) use ($units, $minimum, $includeUnknown) {
            $inner->whereRaw($units.' >= ?', [$minimum]);

            if ($includeUnknown) {
                $inner->orWhereRaw($units.' is null');
            }
        });
    }

    /**
     * `min_mufb` takes either a number or a band name, so the UI can ask for
     * "high confidence" without hard-coding where that boundary sits.
     */
    private function resolveMinMufb(string $value): int
    {
        return $this->mufb->thresholdFor($value) ?? (int) $value;
    }

    /**
     * @param  Builder<Candidate>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sort = $filters['sort'] ?? 'score';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'units' => $query->orderByRaw($this->mufb->unitsSql().' '.$direction),
            'mufb' => $query->orderByRaw(...$this->orderableConfidence($direction)),
            default => $query->orderBy(self::SORTS[$sort] ?? 'candidates.score', $direction),
        };

        // A stable tiebreak, so paging through equal scores cannot repeat or
        // skip a row.
        $query->orderBy('candidates.id');
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function orderableConfidence(string $direction): array
    {
        [$sql, $bindings] = $this->mufb->confidenceExpression();

        return [$sql.' '.$direction, $bindings];
    }
}
