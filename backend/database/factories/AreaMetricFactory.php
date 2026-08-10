<?php

namespace Database\Factories;

use App\Models\AreaMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AreaMetric> */
class AreaMetricFactory extends Factory
{
    protected $model = AreaMetric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Pence.
        $price = fake()->numberBetween(90_000, 320_000) * 100;
        $rent = fake()->numberBetween(600, 1_600) * 100;

        return [
            'postcode_district' => strtoupper(fake()->unique()->bothify('??#')),
            'region' => fake()->randomElement(['North West', 'Yorkshire and The Humber', 'West Midlands', 'North East', 'Wales']),
            'county' => fake()->city(),
            'median_price' => $price,
            'median_rent_pcm' => $rent,
            'gross_yield' => round(($rent * 12) / $price * 100, 2),
            'transaction_volume' => fake()->numberBetween(50, 900),
            'source' => 'seed',
            'as_of' => now()->startOfMonth()->subMonth(),
        ];
    }
}
