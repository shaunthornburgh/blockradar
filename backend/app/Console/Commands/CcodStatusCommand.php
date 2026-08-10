<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\CcodImport;
use Illuminate\Console\Command;

/**
 * Reports on CCOD imports without touching them — useful while a queued
 * import of the full monthly extract is grinding away.
 */
class CcodStatusCommand extends Command
{
    protected $signature = 'ccod:status
        {--limit=5 : How many recent imports to list}
        {--watch : Refresh until the most recent import finishes}';

    protected $description = 'Show the status of recent CCOD imports';

    public function handle(): int
    {
        do {
            if ($this->option('watch')) {
                $this->output->write("\033[2J\033[H");
            }

            $imports = CcodImport::query()
                ->latest('id')
                ->limit(max(1, (int) $this->option('limit')))
                ->get();

            if ($imports->isEmpty()) {
                $this->components->warn('No CCOD imports recorded yet. Run: php artisan ccod:import');

                return self::SUCCESS;
            }

            $this->table(
                ['#', 'File', 'Period', 'Status', 'Progress', 'Titles +/~', 'Candidates', 'Started'],
                $imports->map(fn (CcodImport $import) => [
                    $import->id,
                    $import->filename,
                    $import->period?->format('M Y') ?? '—',
                    $import->status->label(),
                    $this->progress($import),
                    number_format((int) $import->titles_created).' / '.number_format((int) $import->titles_updated),
                    number_format((int) ($import->meta['candidates_created'] ?? 0)),
                    $import->started_at?->diffForHumans() ?? '—',
                ])->all()
            );

            $latest = $imports->first();

            if ($latest->status === ImportStatus::Failed && $latest->error !== null) {
                $this->components->error($latest->error);
            }

            if (! $this->option('watch') || $latest->status->isFinished()) {
                return self::SUCCESS;
            }

            sleep(2);
        } while (true);
    }

    private function progress(CcodImport $import): string
    {
        $processed = (int) $import->rows_imported
            + (int) $import->rows_skipped
            + (int) $import->rows_failed;

        $total = (int) $import->rows_total;

        if ($total <= 0) {
            return number_format($processed);
        }

        return sprintf('%s / %s (%d%%)', number_format($processed), number_format($total), (int) round($processed / $total * 100));
    }
}
