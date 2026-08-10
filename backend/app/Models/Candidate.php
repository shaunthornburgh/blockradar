<?php

namespace App\Models;

use App\Enums\PipelineStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A title that has been promoted into the deal pipeline, along with its
 * score and the sourcing progress against it.
 */
#[Fillable([
    'title_id',
    'stage',
    'score',
    'score_breakdown',
    'scored_at',
    'estimated_units',
    'estimated_gdv',
    'estimated_uplift',
    'gross_yield',
    'assigned_to_id',
    'next_action_at',
    'is_archived',
])]
class Candidate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'stage' => PipelineStage::class,
            'score' => 'integer',
            'score_breakdown' => 'array',
            'scored_at' => 'datetime',
            'estimated_units' => 'integer',
            'estimated_gdv' => 'integer',
            'estimated_uplift' => 'integer',
            'gross_yield' => 'decimal:2',
            'next_action_at' => 'date',
            'is_archived' => 'boolean',
            'title_bought_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'outreach_at' => 'datetime',
            'offered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Title, $this> */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /** @return HasMany<CandidateNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CandidateNote::class)->latest();
    }

    /**
     * Move the candidate to a new stage, stamping the first time it got there.
     */
    public function moveTo(PipelineStage $stage): static
    {
        $this->stage = $stage;

        $column = $stage->timestampColumn();

        if ($column !== null && $this->{$column} === null) {
            $this->{$column} = now();
        }

        $this->save();

        return $this;
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function inStage(Builder $query, PipelineStage $stage): void
    {
        $query->where('stage', $stage);
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function scoredAtLeast(Builder $query, int $score): void
    {
        $query->where('score', '>=', $score);
    }

    /**
     * Candidates whose proprietor company has been pulled from Companies House.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function companyEnriched(Builder $query): void
    {
        $query->whereHas('title.company', fn (Builder $company) => $company->whereNotNull('enriched_at'));
    }

    /**
     * Candidates whose title carries an EPC match good enough to score on.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function epcEnriched(Builder $query): void
    {
        $query->whereHas('title', fn (Builder $title) => $title->whereNotNull('epc_match_confidence'));
    }

    /**
     * Candidates carrying data that did not exist when they were last scored —
     * either never scored at all, or enriched since.
     *
     * The comparisons are against `candidates.scored_at` from inside the
     * correlated subqueries, so this asks "is this candidate's score older than
     * its own data?" rather than applying a single global cut-off.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function scoredBeforeEnrichment(Builder $query): void
    {
        $query->where(function (Builder $outer) {
            $outer
                ->whereNull('candidates.scored_at')
                ->orWhereHas('title', fn (Builder $title) => $title
                    ->whereNotNull('titles.epc_enriched_at')
                    ->whereColumn('titles.epc_enriched_at', '>', 'candidates.scored_at'))
                ->orWhereHas('title.company', fn (Builder $company) => $company
                    ->whereNotNull('companies.enriched_at')
                    ->whereColumn('companies.enriched_at', '>', 'candidates.scored_at'));
        });
    }
}
