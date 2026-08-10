<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\CandidateNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CandidateNote> */
class CandidateNoteFactory extends Factory
{
    protected $model = CandidateNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'user_id' => null,
            'type' => fake()->randomElement(['note', 'call', 'letter', 'email']),
            'body' => fake()->sentence(fake()->numberBetween(8, 20)),
        ];
    }
}
