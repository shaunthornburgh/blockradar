<?php

namespace App\Models;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Enums\Tenure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single registered title as published in the CCOD dataset.
 *
 * One row per title number. Re-importing a later monthly file updates the
 * existing row rather than inserting a duplicate.
 */
#[Fillable([
    'title_number',
    'company_id',
    'ccod_import_id',
    'tenure',
    'property_address',
    'property_address_hash',
    'postcode',
    'district',
    'county',
    'region',
    'multiple_address_indicator',
    'additional_proprietor_indicator',
    'proprietor_name',
    'proprietorship_category',
    'price_paid',
    'date_proprietor_added',
    'estimated_unit_count',
    'unit_count_source',
    'uprn',
    'epc_enriched_at',
    'epc_match_confidence',
    'epc_match_method',
    'epc_certificate_count',
    'epc_primary_certificate_id',
    'epc_current_rating',
    'epc_average_energy_efficiency',
    'epc_total_floor_area',
    'epc_habitable_rooms',
    'epc_property_type',
    'epc_built_form',
    'epc_construction_age_band',
    'epc_main_heating',
    'epc_uprn',
    'epc_latest_lodgement_date',
    'raw',
    'first_seen_at',
    'last_seen_at',
])]
class Title extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tenure' => Tenure::class,
            'multiple_address_indicator' => 'boolean',
            'additional_proprietor_indicator' => 'boolean',
            'price_paid' => 'integer',
            'estimated_unit_count' => 'integer',
            'epc_enriched_at' => 'datetime',
            'epc_match_confidence' => EpcMatchConfidence::class,
            'epc_match_method' => EpcMatchMethod::class,
            'epc_certificate_count' => 'integer',
            'epc_average_energy_efficiency' => 'integer',
            'epc_total_floor_area' => 'decimal:2',
            'epc_habitable_rooms' => 'integer',
            'epc_latest_lodgement_date' => 'date',
            'date_proprietor_added' => 'date',
            'raw' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CcodImport, $this> */
    public function ccodImport(): BelongsTo
    {
        return $this->belongsTo(CcodImport::class);
    }

    /** @return HasOne<Candidate, $this> */
    public function candidate(): HasOne
    {
        return $this->hasOne(Candidate::class);
    }

    /** @return HasMany<TitleEpcMatch, $this> */
    public function epcMatches(): HasMany
    {
        return $this->hasMany(TitleEpcMatch::class);
    }

    /** @return BelongsToMany<EpcCertificate, $this> */
    public function epcCertificates(): BelongsToMany
    {
        return $this->belongsToMany(EpcCertificate::class, 'title_epc_matches')
            ->withPivot(['method', 'confidence', 'similarity', 'is_primary'])
            ->withTimestamps();
    }

    /** @return BelongsTo<EpcCertificate, $this> */
    public function primaryEpcCertificate(): BelongsTo
    {
        return $this->belongsTo(EpcCertificate::class, 'epc_primary_certificate_id');
    }

    /**
     * Whether the EPC link is strong enough to base decisions on. A
     * postcode-only match is deliberately not.
     */
    public function hasUsableEpc(): bool
    {
        return $this->epc_match_confidence !== null
            && $this->epc_match_confidence->atLeast(EpcMatchConfidence::Medium);
    }

    /**
     * The core CCOD filter: freehold titles flagged as covering multiple
     * addresses. This is the population we treat as possible MUFBs.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function splitCandidates(Builder $query): void
    {
        $query->where('tenure', Tenure::Freehold)
            ->where('multiple_address_indicator', true);
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function inRegion(Builder $query, string $region): void
    {
        $query->where('region', $region);
    }

    /**
     * The outward code of the postcode, e.g. "M14" from "M14 5TP". Used to
     * join against area-level yield data.
     */
    public function postcodeDistrict(): ?string
    {
        if (! $this->postcode) {
            return null;
        }

        return strtoupper(explode(' ', trim($this->postcode))[0]) ?: null;
    }

    /**
     * Normalised hash used to spot the same building appearing under
     * different title numbers.
     */
    public static function hashAddress(string $address): string
    {
        $normalised = preg_replace('/[^a-z0-9]+/', ' ', strtolower($address));

        return sha1(trim((string) $normalised));
    }
}
