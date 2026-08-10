<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\CcodImport;
use App\Services\Ccod\CcodImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a CCOD extract through the importer on the queue.
 *
 * The monthly FULL file is over a gigabyte, so this is deliberately a single
 * long-running job rather than many small ones: the CSV is a stream that
 * cannot be split without a pre-pass, and the importer already commits every
 * chunk as it goes.
 */
class ImportCcodFile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Six hours. A job-level timeout overrides the worker's --timeout, which
     * is far too short for a full extract.
     */
    public int $timeout = 21600;

    /**
     * Re-running a half-finished import is safe, but it is never what you
     * want automatically: the failure is recorded and a human decides.
     */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * The uniqueness lock expires on its own after seven hours, just past the
     * job timeout. Without this the lock outlives a worker that was killed
     * mid-import and the same import could never be queued again.
     */
    public int $uniqueFor = 25200;

    public function __construct(public readonly int $importId) {}

    /** Stops the same import being queued twice. */
    public function uniqueId(): string
    {
        return "ccod-import-{$this->importId}";
    }

    public function handle(CcodImporter $importer): void
    {
        $import = CcodImport::find($this->importId);

        if ($import === null) {
            Log::warning('CCOD import record disappeared before the job ran', [
                'import_id' => $this->importId,
            ]);

            return;
        }

        $tally = $importer->import($import);

        Log::info('CCOD import finished', [
            'import_id' => $import->id,
            'filename' => $import->filename,
            'rows_imported' => $tally->rowsImported,
            'titles_created' => $tally->titlesCreated,
            'titles_updated' => $tally->titlesUpdated,
            'candidates_created' => $tally->candidatesCreated,
        ]);
    }

    /**
     * The importer marks the record failed itself, but this also catches the
     * cases it cannot see: a timeout, or the worker being killed.
     */
    public function failed(?Throwable $e): void
    {
        $import = CcodImport::find($this->importId);

        if ($import === null || $import->status === ImportStatus::Completed) {
            return;
        }

        $import->forceFill([
            'status' => ImportStatus::Failed,
            'finished_at' => $import->finished_at ?? now(),
            'error' => $import->error ?? mb_substr((string) $e?->getMessage(), 0, 2000),
        ])->save();
    }
}
