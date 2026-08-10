<?php

namespace Database\Factories;

use App\Enums\Tenure;
use App\Models\Company;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Title> */
class TitleFactory extends Factory
{
    protected $model = Title::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $regions = [
            'North West' => ['Manchester', 'Liverpool', 'Bolton'],
            'Yorkshire and The Humber' => ['Leeds', 'Sheffield', 'Bradford'],
            'West Midlands' => ['Birmingham', 'Coventry', 'Wolverhampton'],
            'North East' => ['Newcastle upon Tyne', 'Sunderland', 'Gateshead'],
            'Wales' => ['Cardiff', 'Swansea', 'Newport'],
        ];

        $region = fake()->randomElement(array_keys($regions));
        $district = fake()->randomElement($regions[$region]);
        $address = sprintf(
            '%d-%d %s, %s',
            $start = fake()->numberBetween(1, 120),
            $start + fake()->numberBetween(2, 10),
            fake()->streetName(),
            $district
        );

        return [
            'title_number' => strtoupper(fake()->unique()->bothify('??######')),
            'company_id' => Company::factory(),
            'tenure' => Tenure::Freehold,
            'property_address' => $address,
            'property_address_hash' => Title::hashAddress($address),
            'postcode' => strtoupper(fake()->bothify('?## #??')),
            'district' => $district,
            'county' => $district,
            'region' => $region,
            'multiple_address_indicator' => true,
            'additional_proprietor_indicator' => fake()->boolean(20),
            'proprietor_name' => null,
            'proprietorship_category' => 'Limited Company or Public Limited Company',
            // Pence.
            'price_paid' => fake()->numberBetween(150_000, 2_500_000) * 100,
            'date_proprietor_added' => fake()->dateTimeBetween('-18 years', '-6 months'),
            'estimated_unit_count' => fake()->numberBetween(3, 24),
            'first_seen_at' => now()->subMonths(fake()->numberBetween(1, 12)),
            'last_seen_at' => now(),
        ];
    }

    public function leasehold(): static
    {
        return $this->state(fn () => ['tenure' => Tenure::Leasehold]);
    }

    public function singleAddress(): static
    {
        return $this->state(fn () => [
            'multiple_address_indicator' => false,
            'estimated_unit_count' => 1,
        ]);
    }
}
