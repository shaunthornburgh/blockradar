<?php

namespace Database\Factories;

use App\Models\EpcCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EpcCertificate> */
class EpcCertificateFactory extends Factory
{
    protected $model = EpcCertificate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $address = fake()->buildingNumber().' '.fake()->streetName();

        return [
            'certificate_number' => fake()->unique()->bothify('####-####-####-####-####'),
            'address' => $address,
            'postcode' => strtoupper(fake()->bothify('?# #??')),
            'current_energy_rating' => fake()->randomElement(['C', 'D', 'E', 'F']),
            'current_energy_efficiency' => fake()->numberBetween(20, 80),
            'property_type' => 'Flat',
            'built_form' => 'Mid-Terrace',
            'total_floor_area' => fake()->randomFloat(2, 35, 90),
            'number_habitable_rooms' => fake()->numberBetween(1, 4),
            'lodgement_date' => fake()->dateTimeBetween('-6 years', '-1 month'),
            'source' => 'bulk',
        ];
    }
}
