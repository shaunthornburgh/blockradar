<?php

namespace Database\Seeders;

use App\Models\AreaMetric;
use App\Models\Candidate;
use App\Models\CandidateNote;
use App\Models\CcodImport;
use App\Models\Title;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data so the dashboard has something to render before the real CCOD
 * import exists.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Shaun Thornburgh',
            'email' => 'admin@blockradar.test',
            'password' => Hash::make('password'),
        ]);

        $import = CcodImport::factory()->create();

        AreaMetric::factory(40)->create();

        // Freehold + multiple-address titles, each promoted to a candidate.
        Title::factory(60)
            ->create(['ccod_import_id' => $import->id])
            ->each(function (Title $title) use ($user) {
                $candidate = Candidate::factory()->create([
                    'title_id' => $title->id,
                    'assigned_to_id' => fake()->boolean(60) ? $user->id : null,
                ]);

                CandidateNote::factory(fake()->numberBetween(0, 3))->create([
                    'candidate_id' => $candidate->id,
                    'user_id' => $user->id,
                ]);
            });

        // Background noise the split filter should exclude.
        Title::factory(25)->leasehold()->create(['ccod_import_id' => $import->id]);
        Title::factory(25)->singleAddress()->create(['ccod_import_id' => $import->id]);
    }
}
