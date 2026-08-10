<?php

namespace App\Http\Controllers\Api;

use App\Enums\PipelineStage;
use App\Enums\Tenure;
use App\Http\Controllers\Controller;
use App\Http\Resources\CandidateResource;
use App\Models\Candidate;
use App\Models\CcodImport;
use App\Models\Company;
use App\Models\Title;
use Illuminate\Http\JsonResponse;

/**
 * Summary figures for the admin dashboard.
 */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStage = Candidate::query()
            ->active()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $pipeline = collect(PipelineStage::cases())->map(fn (PipelineStage $stage) => [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'count' => (int) ($byStage[$stage->value] ?? 0),
        ])->all();

        $topCandidates = Candidate::query()
            ->active()
            ->with(['title.company', 'assignedTo'])
            ->orderByDesc('score')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'totals' => [
                    'titles' => Title::query()->count(),
                    'split_candidates' => Title::query()->splitCandidates()->count(),
                    'freehold_titles' => Title::query()->where('tenure', Tenure::Freehold)->count(),
                    'companies' => Company::query()->count(),
                    'companies_awaiting_enrichment' => Company::query()->whereNull('enriched_at')->count(),
                    'candidates' => Candidate::query()->active()->count(),
                    'high_score_candidates' => Candidate::query()->active()->scoredAtLeast(70)->count(),
                ],
                'pipeline' => $pipeline,
                'top_candidates' => CandidateResource::collection($topCandidates),
                'latest_import' => CcodImport::query()->latest('period')->first(),
            ],
        ]);
    }
}
