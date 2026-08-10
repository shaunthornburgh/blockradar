<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\CcodImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CcodImport> */
class CcodImportFactory extends Factory
{
    protected $model = CcodImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $period = now()->startOfMonth()->subMonth();
        $total = fake()->numberBetween(3_000_000, 3_600_000);
        $skipped = (int) round($total * 0.02);

        return [
            'filename' => 'CCOD_FULL_'.$period->format('Y_m').'.csv',
            'period' => $period,
            'checksum' => fake()->sha256(),
            'status' => ImportStatus::Completed,
            'rows_total' => $total,
            'rows_imported' => $total - $skipped,
            'rows_skipped' => $skipped,
            'rows_failed' => 0,
            'titles_created' => (int) round($total * 0.1),
            'titles_updated' => (int) round($total * 0.85),
            'started_at' => $period->copy()->addDays(3)->setTime(2, 0),
            'finished_at' => $period->copy()->addDays(3)->setTime(2, 41),
        ];
    }
}
