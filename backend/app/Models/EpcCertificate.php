<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A domestic Energy Performance Certificate — one dwelling, not one building.
 */
#[Fillable([
    'certificate_number',
    'uprn',
    'building_reference_number',
    'address_line_1',
    'address_line_2',
    'address_line_3',
    'address',
    'address_hash',
    'building_key_hash',
    'postcode',
    'post_town',
    'council',
    'county',
    'current_energy_rating',
    'current_energy_efficiency',
    'potential_energy_rating',
    'potential_energy_efficiency',
    'property_type',
    'built_form',
    'total_floor_area',
    'number_habitable_rooms',
    'number_heated_rooms',
    'construction_age_band',
    'main_fuel',
    'main_heat_description',
    'mains_gas_flag',
    'floor_level',
    'flat_storey_count',
    'tenure',
    'transaction_type',
    'inspection_date',
    'lodgement_date',
    'source',
    'raw',
])]
class EpcCertificate extends Model
{
    use HasFactory;

    /** Worst to best, so a block can be summarised by its poorest flat. */
    public const RATING_ORDER = ['G', 'F', 'E', 'D', 'C', 'B', 'A'];

    protected function casts(): array
    {
        return [
            'total_floor_area' => 'decimal:2',
            'current_energy_efficiency' => 'integer',
            'potential_energy_efficiency' => 'integer',
            'number_habitable_rooms' => 'integer',
            'number_heated_rooms' => 'integer',
            'flat_storey_count' => 'integer',
            'inspection_date' => 'date',
            'lodgement_date' => 'date',
            'raw' => 'array',
        ];
    }

    /** @return BelongsToMany<Title, $this> */
    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class, 'title_epc_matches')
            ->withPivot(['method', 'confidence', 'similarity', 'is_primary'])
            ->withTimestamps();
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function inPostcode(Builder $query, string $postcode): void
    {
        $query->where('postcode', strtoupper(trim($postcode)));
    }

    /**
     * Certificates describing a self-contained flat, which is what a
     * splittable block is made of.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function flats(Builder $query): void
    {
        $query->whereIn('property_type', ['Flat', 'Maisonette']);
    }

    /**
     * Compares two SAP bands. Returns the worse of the pair, treating an
     * unknown band as no information rather than as the worst case.
     */
    public static function worstRating(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        $aIndex = array_search(strtoupper($a), self::RATING_ORDER, true);
        $bIndex = array_search(strtoupper($b), self::RATING_ORDER, true);

        if ($aIndex === false) {
            return $b;
        }

        if ($bIndex === false) {
            return $a;
        }

        return $aIndex <= $bIndex ? strtoupper($a) : strtoupper($b);
    }
}
