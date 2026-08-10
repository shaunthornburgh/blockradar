<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ImportCcodFile;
use App\Models\Candidate;
use App\Models\CcodImport;
use App\Models\Company;
use App\Models\Title;
use App\Services\Ccod\CcodFileLocator;
use App\Services\Ccod\CcodImporter;
use App\Services\Ccod\ImportTally;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

class ImportCcodCommand extends Command
{
    protected $signature = 'ccod:import
        {path? : Path to a CCOD CSV. Defaults to the newest .csv in storage/app/ccod}
        {--sync : Import in this process instead of dispatching to the queue}
        {--no-wait : Dispatch to the queue and exit without following progress}
        {--fresh : DESTRUCTIVE. Delete all imported titles, companies and candidates first}
        {--force : Skip confirmation prompts}';

    protected $description = 'Import an HM Land Registry CCOD extract and promote qualifying titles to candidates';

    public function handle(CcodFileLocator $locator, CcodImporter $importer): int
    {
        try {
            $path = $locator->resolve($this->argument('path'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Using '.$path);
        $this->line('  Size: '.$this->humanBytes((int) filesize($path)));

        if ($this->option('fresh') && ! $this->wipe()) {
            return self::FAILURE;
        }

        $import = $this->createImportRecord($locator, $path);

        $this->warnAboutPreviousImport($import);

        return $this->option('sync')
            ? $this->runSync($importer, $import)
            : $this->runQueued($import);
    }

    private function createImportRecord(CcodFileLocator $locator, string $path): CcodImport
    {
        $this->line('  Checksumming…');

        return CcodImport::create([
            'filename' => basename($path),
            'period' => $locator->periodFor($path),
            'checksum' => hash_file('sha256', $path) ?: null,
            'status' => ImportStatus::Pending,
            'meta' => ['path' => $path],
        ]);
    }

    /**
     * Re-importing is safe by design, but it is worth saying out loud when the
     * exact same bytes have already been through.
     */
    private function warnAboutPreviousImport(CcodImport $import): void
    {
        if ($import->checksum === null) {
            return;
        }

        $previous = CcodImport::query()
            ->where('checksum', $import->checksum)
            ->where('id', '!=', $import->id)
            ->where('status', ImportStatus::Completed)
            ->latest('finished_at')
            ->first();

        if ($previous !== null) {
            $this->components->warn(sprintf(
                'This exact file was already imported on %s. Re-running is safe — titles and companies are upserted, and existing candidates are left untouched.',
                $previous->finished_at?->toDayDateTimeString() ?? 'an earlier run'
            ));
        }
    }

    private function runSync(CcodImporter $importer, CcodImport $import): int
    {
        $this->newLine();
        $this->components->info("Importing in this process (import #{$import->id})");

        $bar = null;

        try {
            $tally = $importer->import($import, function (CcodImport $import, ImportTally $tally) use (&$bar) {
                $bar ??= $this->makeBar((int) $import->rows_total);
                $this->advanceBar($bar, $import, $tally);
            });
        } catch (Throwable $e) {
            $bar?->clear();
            $this->newLine();
            $this->components->error('Import failed: '.$e->getMessage());
            $this->line('  Recorded against import #'.$import->id);

            return self::FAILURE;
        }

        $bar?->finish();
        $this->newLine(2);

        $this->summarise($import->refresh(), $tally);

        return self::SUCCESS;
    }

    private function runQueued(CcodImport $import): int
    {
        ImportCcodFile::dispatch($import->id);

        $this->components->info("Queued as import #{$import->id}");

        if ($this->option('no-wait')) {
            $this->line('  Not waiting. Follow it with: php artisan ccod:status');

            return self::SUCCESS;
        }

        $this->line('  Waiting for a queue worker. Ctrl-C is safe — the import keeps running.');
        $this->newLine();

        return $this->followProgress($import);
    }

    /**
     * Polls the import record so the queued job can still be watched from the
     * terminal. Interrupting this does not interrupt the import.
     */
    private function followProgress(CcodImport $import): int
    {
        $bar = null;
        $waitedForWorker = 0;

        while (true) {
            $import->refresh();

            if ($import->status === ImportStatus::Pending) {
                $waitedForWorker++;

                // Roughly 20 seconds of nothing happening.
                if ($waitedForWorker === 40) {
                    $this->components->warn('Still pending — is a queue worker running? `docker compose ps queue`');
                }

                usleep(500_000);

                continue;
            }

            $bar ??= $this->makeBar((int) $import->rows_total);
            $this->advanceBar($bar, $import);

            if ($import->status->isFinished()) {
                break;
            }

            usleep(500_000);
        }

        $bar?->finish();
        $this->newLine(2);

        if ($import->status === ImportStatus::Failed) {
            $this->components->error('Import failed: '.($import->error ?? 'no error recorded'));

            return self::FAILURE;
        }

        $this->summarise($import);

        return self::SUCCESS;
    }

    private function makeBar(int $total): ProgressBar
    {
        $bar = $this->output->createProgressBar($total);

        $bar->setFormat($total > 0
            ? ' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %message%'
            : ' %current% rows [%bar%] %elapsed:6s%  %message%');

        $bar->setMessage('starting');
        $bar->start();

        return $bar;
    }

    private function advanceBar(ProgressBar $bar, CcodImport $import, ?ImportTally $tally = null): void
    {
        $processed = (int) $import->rows_imported
            + (int) $import->rows_skipped
            + (int) $import->rows_failed;

        $bar->setProgress($processed);
        $bar->setMessage(sprintf(
            '%s titles new, %s updated, %s candidates',
            number_format($import->titles_created),
            number_format($import->titles_updated),
            number_format($tally?->candidatesCreated ?? ($import->meta['candidates_created'] ?? 0))
        ));
    }

    private function summarise(CcodImport $import, ?ImportTally $tally = null): void
    {
        $meta = $import->meta ?? [];

        $this->components->info('Import #'.$import->id.' '.$import->status->label());

        $this->table(['Metric', 'Count'], [
            ['Rows in file', number_format((int) $import->rows_total)],
            ['Rows imported', number_format((int) $import->rows_imported)],
            ['Rows skipped (unusable)', number_format((int) $import->rows_skipped)],
            ['Rows failed', number_format((int) $import->rows_failed)],
            ['Titles created', number_format((int) $import->titles_created)],
            ['Titles updated', number_format((int) $import->titles_updated)],
            ['Companies created', number_format((int) ($meta['companies_created'] ?? 0))],
            ['Candidates created', number_format((int) ($tally?->candidatesCreated ?? $meta['candidates_created'] ?? 0))],
            ['Duration', $this->humanDuration($import->durationSeconds())],
        ]);

        $rejections = $tally?->rejections ?? ($meta['rejections'] ?? []);

        if ($rejections !== []) {
            $this->line('  Titles not promoted to candidates:');

            foreach ($rejections as $reason => $count) {
                $this->line(sprintf('    %-30s %s', str_replace('_', ' ', (string) $reason), number_format((int) $count)));
            }

            $this->newLine();
        }
    }

    /**
     * Deletes every imported record. Candidates go with their titles, so any
     * pipeline progress is destroyed too — hence the explicit confirmation.
     */
    private function wipe(): bool
    {
        $counts = sprintf(
            '%s titles, %s companies and %s candidates',
            number_format(Title::query()->count()),
            number_format(Company::query()->count()),
            number_format(Candidate::query()->count())
        );

        if (! $this->option('force') && ! $this->confirm("--fresh will permanently delete {$counts}, including all pipeline progress. Continue?", false)) {
            $this->components->warn('Aborted.');

            return false;
        }

        // Ordered for foreign keys: candidates hang off titles, and titles
        // reference both companies and previous imports.
        DB::transaction(function (): void {
            Candidate::query()->delete();
            Title::query()->delete();
            Company::query()->delete();
            CcodImport::query()->delete();
        });

        $this->components->warn('Deleted all previously imported data.');

        return true;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }

    private function humanDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds < 60
            ? "{$seconds}s"
            : sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
}
