<?php

namespace Database\Seeders;

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
    }
}
