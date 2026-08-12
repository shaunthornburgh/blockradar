<?php

namespace App\Models;

use App\Enums\EnrichmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A proprietor company, keyed on its Companies House number and enriched
 * from the Companies House public API.
 */
#[Fillable([
    'company_number',
    'name',
    'status',
    'type',
    'jurisdiction',
    'incorporated_on',
    'dissolved_on',
    'sic_codes',
    'registered_office_address',
    'registered_office_postcode',
    'officer_count',
    'accounts_last_made_up_to',
    'accounts_next_due',
    'accounts_overdue',
    'confirmation_statement_overdue',
    'confirmation_statement_last_made_up_to',
    'confirmation_statement_next_due',
    'has_charges',
    'charges_count',
    'has_insolvency_history',
    'enriched_at',
    'enrichment_status',
    'enrichment_attempted_at',
    'enrichment_attempts',
    'enrichment_error',
    'ch_raw',
])]
class Company extends Model
{
    use HasFactory;

    /** Statuses meaning the company is in an insolvency process. */
    public const DISTRESSED_STATUSES = [
        'liquidation',
        'receivership',
        'administration',
        'voluntary-arrangement',
        'insolvency-proceedings',
    ];

    /** Statuses meaning the company no longer exists. */
    public const DISSOLVED_STATUSES = ['dissolved', 'converted-closed', 'closed', 'removed'];

    protected function casts(): array
    {
        return [
            'incorporated_on' => 'date',
            'dissolved_on' => 'date',
            'accounts_last_made_up_to' => 'date',
            'accounts_next_due' => 'date',
            'accounts_overdue' => 'boolean',
            'confirmation_statement_overdue' => 'boolean',
            'confirmation_statement_last_made_up_to' => 'date',
            'confirmation_statement_next_due' => 'date',
            'has_insolvency_history' => 'boolean',
            'sic_codes' => 'array',
            'registered_office_address' => 'array',
            'ch_raw' => 'array',
            'has_charges' => 'boolean',
            'officer_count' => 'integer',
            'charges_count' => 'integer',
            'enriched_at' => 'datetime',
            'enrichment_status' => EnrichmentStatus::class,
            'enrichment_attempted_at' => 'datetime',
            'enrichment_attempts' => 'integer',
        ];
    }

    /** @return HasMany<Title, $this> */
    public function titles(): HasMany
    {
        return $this->hasMany(Title::class);
    }

    /**
     * Candidates reachable through this company's titles. Used to prioritise
     * enrichment towards companies that actually matter to the pipeline.
     *
     * @return HasManyThrough<Candidate, Title, $this>
     */
    public function candidates(): HasManyThrough
    {
        return $this->hasManyThrough(Candidate::class, Title::class);
    }

    /**
     * Companies that have never been pulled from Companies House, or whose
     * enrichment has gone stale.
     *
     * Companies House will never resolve a number it does not know, and a
     * company that has failed repeatedly is usually a data problem rather than
     * a transient one — both are excluded so they stop consuming rate limit
     * on every run. `--force` bypasses this scope entirely.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function needsEnrichment(Builder $query, ?int $staleAfterDays = null, ?int $maxAttempts = null): void
    {
        $staleAfterDays ??= (int) config('blockradar.companies_house.stale_after_days', 30);
        $maxAttempts ??= (int) config('blockradar.companies_house.max_enrichment_attempts', 5);

        $query
            ->where(function (Builder $inner) use ($staleAfterDays) {
                $inner->whereNull('enriched_at')
                    ->orWhere('enriched_at', '<', now()->subDays($staleAfterDays));
            })
            ->where(function (Builder $inner) {
                $inner->whereNull('enrichment_status')
                    ->orWhere('enrichment_status', '!=', EnrichmentStatus::NotFound);
            })
            ->where('enrichment_attempts', '<', $maxAttempts);
    }

    public function isEnriched(): bool
    {
        return $this->enriched_at !== null;
    }

    /**
     * Whether Companies House currently shows the company in an insolvency
     * process. Distinct from dissolved, which means it no longer exists.
     */
    public function isDistressed(): bool
    {
        return in_array($this->status, self::DISTRESSED_STATUSES, true);
    }

    public function isDissolved(): bool
    {
        return in_array($this->status, self::DISSOLVED_STATUSES, true);
    }

    /**
     * Short labels for every distress signal Companies House currently shows.
     *
     * Empty means either "no signals" or "not enriched yet" — check
     * isEnriched() to tell those apart. The list is what the candidates list
     * row displays, and applyDistressFilter() below selects on exactly the
     * same set of conditions.
     *
     * @return array<int, string>
     */
    public function distressSignals(): array
    {
        $signals = [];

        if ($this->accounts_overdue === true) {
            $signals[] = 'accounts overdue';
        }

        if ($this->confirmation_statement_overdue === true) {
            $signals[] = 'confirmation statement overdue';
        }

        if ($this->isDistressed()) {
            $signals[] = str_replace('-', ' ', (string) $this->status);
        }

        if ($this->isDissolved()) {
            $signals[] = str_replace('-', ' ', (string) $this->status);
        }

        if ($this->has_insolvency_history === true) {
            $signals[] = 'insolvency history';
        }

        return array_values(array_unique($signals));
    }

    public function hasDistressSignals(): bool
    {
        return $this->distressSignals() !== [];
    }

    /**
     * Constrains a query to companies carrying at least one distress signal.
     *
     * Takes a plain query builder and a table name rather than being a model
     * scope, because the candidates list reaches companies through a join
     * rather than through the relation.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $query
     */
    public static function applyDistressFilter($query, string $table = 'companies'): void
    {
        $query->where(function ($inner) use ($table) {
            $inner->where($table.'.accounts_overdue', true)
                ->orWhere($table.'.confirmation_statement_overdue', true)
                ->orWhere($table.'.has_insolvency_history', true)
                ->orWhereIn($table.'.status', self::DISTRESSED_STATUSES)
                ->orWhereIn($table.'.status', self::DISSOLVED_STATUSES);
        });
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
