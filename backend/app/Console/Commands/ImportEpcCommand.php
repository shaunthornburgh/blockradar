<?php

namespace App\Console\Commands;

use App\Models\EpcCertificate;
use App\Models\Title;
use App\Services\Epc\EpcBulkImporter;
use App\Services\Epc\EpcCsvReader;
use App\Services\Epc\EpcImportTally;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportEpcCommand extends Command
{
    protected $signature = 'epc:import
        {path? : Path to a bulk EPC CSV. Defaults to the newest .csv in storage/app/epc}
        {--all-postcodes : Keep every certificate, not just postcodes we hold titles in}
        {--fresh : DESTRUCTIVE. Delete all loaded certificates first}
        {--force : Skip confirmation prompts}';

    protected $description = 'Load a bulk EPC domestic extract into the certificate table';

    public function handle(EpcBulkImporter $importer): int
    {
        try {
            $path = $this->resolvePath($this->argument('path'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $this->line('  Download the domestic bulk extract from');
            $this->line('  https://get-energy-performance-data.communities.gov.uk and unzip it there.');

            return self::FAILURE;
        }

        $this->components->info('Using '.$path);
        $this->line('  Size: '.$this->humanBytes((int) filesize($path)));

        if ($this->option('fresh') && ! $this->wipe()) {
            return self::FAILURE;
        }

        $restrict = ! $this->option('all-postcodes')
            && (bool) config('blockradar.epc.restrict_to_known_postcodes', true);

        if ($restrict) {
            $postcodes = Title::query()->whereNotNull('postcode')->distinct()->count('postcode');

            if ($postcodes === 0) {
                $this->components->warn(
                    'No titles have postcodes yet, so the filter would discard everything. '.
                    'Run ccod:import first, or pass --all-postcodes.'
                );

                return self::FAILURE;
            }

            $this->line("  Keeping only the {$postcodes} postcodes we hold titles in (--all-postcodes to keep everything).");
        }

        $this->line('  Counting rows…');
        $total = (new EpcCsvReader($path))->estimateRows();

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        try {
            $tally = $importer->import($path, $restrict, function (EpcImportTally $tally) use ($bar) {
                $bar->setProgress(min($tally->rowsRead, $bar->getMaxSteps()));
                $bar->setMessage(sprintf(
                    '%s kept, %s outside portfolio',
                    number_format($tally->rowsImported),
                    number_format($tally->rowsOutsidePortfolio)
                ));
            });
        } catch (Throwable $e) {
            $bar->clear();
            $this->newLine();
            $this->components->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Count'], [
            ['Rows read', number_format($tally->rowsRead)],
            ['Certificates created', number_format($tally->certificatesCreated)],
            ['Certificates updated', number_format($tally->certificatesUpdated)],
            ['Skipped (unusable)', number_format($tally->rowsSkipped)],
            ['Skipped (outside portfolio)', number_format($tally->rowsOutsidePortfolio)],
            ['Failed', number_format($tally->rowsFailed)],
            ['Certificates held', number_format(EpcCertificate::query()->count())],
        ]);

        $this->components->info('Next: php artisan epc:enrich --only-candidates');

        return self::SUCCESS;
    }

    private function resolvePath(?string $path): string
    {
        $directory = storage_path('app/'.trim((string) config('blockradar.epc.storage_path', 'epc'), '/'));

        if ($path !== null && trim($path) !== '') {
            foreach ([$path, getcwd().'/'.$path, $directory.'/'.$path] as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    return (string) (realpath($candidate) ?: $candidate);
                }
            }

            throw new RuntimeException("No readable EPC file at [{$path}].");
        }

        if (! is_dir($directory)) {
            throw new RuntimeException("The EPC directory does not exist: {$directory}");
        }

        $files = array_values(array_filter(
            (array) glob($directory.'/*.[cC][sS][vV]'),
            static fn ($file) => is_string($file) && is_readable($file)
        ));

        if ($files === []) {
            throw new RuntimeException("No CSV files found in {$directory}.");
        }

        usort($files, static fn (string $a, string $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return (string) (realpath($files[0]) ?: $files[0]);
    }

    private function wipe(): bool
    {
        $count = EpcCertificate::query()->count();

        if (! $this->option('force') && ! $this->confirm(
            "--fresh will delete {$count} certificates and every title match built from them. Continue?",
            false
        )) {
            $this->components->warn('Aborted.');

            return false;
        }

        // Matches cascade with the certificates; the aggregates on titles are
        // rebuilt next time epc:enrich runs.
        EpcCertificate::query()->delete();

        $this->components->warn('Deleted all loaded EPC certificates.');

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
}
