<?php

namespace Database\Factories;

use App\Enums\PipelineStage;
use App\Models\Candidate;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Candidate> */
class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $units = fake()->numberBetween(3, 20);
        // Pence.
        $gdv = $units * fake()->numberBetween(85_000, 180_000) * 100;
        $stage = fake()->randomElement(PipelineStage::cases());

        return [
            'title_id' => Title::factory(),
            'stage' => $stage,
            'score' => fake()->numberBetween(35, 96),
            'score_breakdown' => [
                'area_yield' => fake()->numberBetween(0, 30),
                'estimated_units' => fake()->numberBetween(0, 25),
                'title_split_upside' => fake()->numberBetween(0, 20),
                'ownership_duration' => fake()->numberBetween(0, 10),
                'company_dormancy' => fake()->numberBetween(0, 10),
                'no_existing_charges' => fake()->numberBetween(0, 5),
            ],
            'scored_at' => now()->subDays(fake()->numberBetween(0, 14)),
            'estimated_units' => $units,
            'estimated_gdv' => $gdv,
            'estimated_uplift' => (int) round($gdv * fake()->randomFloat(2, 0.08, 0.28)),
            'gross_yield' => fake()->randomFloat(2, 5, 12),
            'next_action_at' => fake()->boolean(40) ? fake()->dateTimeBetween('now', '+30 days') : null,
            'is_archived' => false,
            // Backfill the stage timestamps so the funnel looks coherent.
            'title_bought_at' => $stage->order() >= 1 ? now()->subDays(fake()->numberBetween(10, 60)) : null,
            'confirmed_at' => $stage->order() >= 2 ? now()->subDays(fake()->numberBetween(5, 30)) : null,
            'outreach_at' => $stage->order() >= 3 ? now()->subDays(fake()->numberBetween(2, 20)) : null,
            'offered_at' => $stage->order() >= 4 ? now()->subDays(fake()->numberBetween(0, 10)) : null,
        ];
    }

    public function inStage(PipelineStage $stage): static
    {
        return $this->state(fn () => ['stage' => $stage]);
    }
}
