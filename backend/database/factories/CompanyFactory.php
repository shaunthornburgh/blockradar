<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $suffix = fake()->randomElement(['Limited', 'Ltd', 'Properties Limited', 'Estates Ltd', 'Holdings Limited']);

        return [
            'company_number' => str_pad((string) fake()->unique()->numberBetween(1, 9999999), 8, '0', STR_PAD_LEFT),
            'name' => strtoupper(fake()->lastName().' '.$suffix),
            'status' => fake()->randomElement(['active', 'active', 'active', 'dissolved']),
            'type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'incorporated_on' => fake()->dateTimeBetween('-30 years', '-1 year'),
            'sic_codes' => fake()->randomElements(['68209', '68100', '68320', '98000'], 2),
            'registered_office_address' => [
                'address_line_1' => fake()->streetAddress(),
                'locality' => fake()->city(),
                'postal_code' => fake()->postcode(),
                'country' => 'England',
            ],
            'registered_office_postcode' => fake()->postcode(),
            'officer_count' => fake()->numberBetween(1, 4),
            'has_charges' => fake()->boolean(45),
            'charges_count' => fake()->numberBetween(0, 3),
            'enriched_at' => fake()->boolean(70) ? now()->subDays(fake()->numberBetween(0, 20)) : null,
        ];
    }

    public function unenriched(): static
    {
        return $this->state(fn () => ['enriched_at' => null]);
    }
}
