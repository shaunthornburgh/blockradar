<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Area-level rental and price data keyed on postcode district (outward code),
 * used by the scorer to favour high-yield areas.
 */
#[Fillable([
    'postcode_district',
    'region',
    'county',
    'median_price',
    'median_rent_pcm',
    'gross_yield',
    'transaction_volume',
    'source',
    'as_of',
])]
class AreaMetric extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'median_price' => 'integer',
            'median_rent_pcm' => 'integer',
            'gross_yield' => 'decimal:2',
            'transaction_volume' => 'integer',
            'as_of' => 'date',
        ];
    }
}
