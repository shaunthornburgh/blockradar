<?php

namespace App\Console\Commands;

use App\Jobs\RescoreCandidatesJob;
use App\Models\Candidate;
use App\Services\Candidates\CandidateRescorer;
use App\Services\Candidates\RescoreOptions;
use App\Services\Candidates\RescoreTally;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Console\Helper\ProgressBar;

class RescoreCandidatesCommand extends Command
{
    protected $signature = 'candidates:rescore
        {--limit=500 : Maximum number of candidates to examine}
        {--force : Examine every candidate, not just those enriched since they were scored}
        {--only-enriched : Only candidates with Companies House or EPC data}
        {--company-enriched : Only candidates whose company has Companies House data}
        {--epc-enriched : Only candidates whose title has a usable EPC match}
        {--min-score-change=0 : Only write when the score moves at least this many points}
        {--sync : Rescore in this process instead of dispatching to the queue}
        {--dry-run : Report what would change without writing anything}
        {--batch=250 : Candidates per queued job}
        {--no-wait : Dispatch and exit without following progress}
        {--include-archived : Also rescore archived candidates}';

    protected $description = 'Recompute candidate scores against the latest Companies House and EPC data';

    public function handle(CandidateRescorer $rescorer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minScoreChange = max(0, (int) $this->option('min-score-change'));

        $ids = $this->select();

        if ($ids->isEmpty()) {
            $this->components->info('Nothing to rescore.');
            $this->explainEmptySelection();

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%s candidates selected.%s',
            number_format($ids->count()),
            $minScoreChange > 0 ? " Writing only movements of {$minScoreChange}+ points." : ''
        ));

        if ($minScoreChange > 0) {
            $this->line('  Below that, only scored_at is stamped — the stored breakdown will lag the data.');
        }

        if ($dryRun) {
            // A queued dry run would report nothing useful here, and the work
            // is local computation, so it always runs in-process.
            $this->components->warn('Dry run: nothing will be written.');

            return $this->runSync($ids, $rescorer, new RescoreOptions(dryRun: true, minScoreChange: $minScoreChange));
        }

        return $this->option('sync')
            ? $this->runSync($ids, $rescorer, new RescoreOptions(minScoreChange: $minScoreChange))
            : $this->runQueued($ids, $minScoreChange);
    }

    /**
     * Oldest scores first, so repeated limited runs sweep the whole table
     * rather than circling the same candidates.
     *
     * @return Collection<int, int>
     */
    private function select(): Collection
    {
        $companyOnly = (bool) $this->option('company-enriched');
        $epcOnly = (bool) $this->option('epc-enriched');

        return Candidate::query()
            ->unless($this->option('include-archived'), fn (Builder $query) => $query->active())
            ->when($companyOnly, fn (Builder $query) => $query->companyEnriched())
            ->when($epcOnly, fn (Builder $query) => $query->epcEnriched())
            ->when(
                $this->option('only-enriched') && ! $companyOnly && ! $epcOnly,
                fn (Builder $query) => $query->where(
                    fn (Builder $inner) => $inner->companyEnriched()->orWhere(
                        fn (Builder $or) => $or->epcEnriched()
                    )
                )
            )
            ->unless($this->option('force'), fn (Builder $query) => $query->scoredBeforeEnrichment())
            // NULLs sort first, which is what we want: never-scored candidates
            // are the most urgent.
            ->orderBy('scored_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');
    }

    private function explainEmptySelection(): void
    {
        if ($this->option('force')) {
            return;
        }

        $total = Candidate::query()->active()->count();

        $this->line(sprintf(
            '  %s active candidates were already scored after their most recent enrichment. Use --force to rescore them anyway.',
            number_format($total)
        ));
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    private function runSync(Collection $ids, CandidateRescorer $rescorer, RescoreOptions $options): int
    {
        $bar = $this->output->createProgressBar($ids->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $tally = new RescoreTally;
        $size = max(1, (int) $this->option('batch'));

        foreach ($ids->chunk($size) as $chunk) {
            $rescorer->rescore($rescorer->load($chunk->values()->all()), $options, $tally);

            $bar->advance($chunk->count());
            $bar->setMessage(sprintf('%s changed', number_format($tally->scoreChanged)));
        }

        $bar->finish();
        $this->newLine(2);

        $this->summarise($tally, $options->dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    private function runQueued(Collection $ids, int $minScoreChange): int
    {
        $size = max(1, (int) $this->option('batch'));

        $jobs = $ids->chunk($size)
            ->map(fn (Collection $chunk) => new RescoreCandidatesJob($chunk->values()->all(), $minScoreChange))
            ->all();

        $batch = Bus::batch($jobs)
            ->name('candidates:rescore')
            ->allowFailures()
            ->dispatch();

        $this->components->info(sprintf(
            'Queued %s candidates in %s job(s). Batch %s.',
            number_format($ids->count()),
            number_format(count($jobs)),
            $batch->id
        ));

        if ($this->option('no-wait')) {
            return self::SUCCESS;
        }

        return $this->followBatch($batch->id);
    }

    /**
     * Follows the batch from the terminal. Interrupting this does not
     * interrupt the run — the jobs are already queued.
     */
    private function followBatch(string $batchId): int
    {
        $bar = null;
        $polls = 0;

        while (true) {
            $batch = Bus::findBatch($batchId);

            if ($batch === null) {
                $this->components->error('The batch disappeared before it finished.');

                return self::FAILURE;
            }

            $bar ??= $this->makeBatchBar($batch);
            $bar->setProgress($batch->processedJobs());
            $bar->setMessage(sprintf('%s failed', number_format($batch->failedJobs)));

            if ($batch->finished() || $batch->cancelled()) {
                break;
            }

            // Roughly twenty seconds with nothing picked up at all.
            if (++$polls === 40 && $batch->processedJobs() === 0) {
                $this->components->warn('Nothing processed yet — is a queue worker running? `docker compose ps queue`');
            }

            usleep(500_000);
        }

        $bar?->finish();
        $this->newLine(2);

        $this->summarise(RescoreCandidatesJob::tallyFor($batchId), dryRun: false);

        return self::SUCCESS;
    }

    private function makeBatchBar(Batch $batch): ProgressBar
    {
        $bar = $this->output->createProgressBar($batch->totalJobs);
        $bar->setFormat(' %current%/%max% jobs [%bar%] %percent:3s%%  %elapsed:6s%  %message%');
        $bar->setMessage('waiting for a worker');
        $bar->start();

        return $bar;
    }

    private function summarise(RescoreTally $tally, bool $dryRun): void
    {
        $verb = $dryRun ? 'would change' : 'changed';

        $this->table(['Metric', 'Value'], [
            ['Candidates examined', number_format($tally->examined)],
            ['Scores '.$verb, number_format($tally->scoreChanged)],
            [$dryRun ? 'Would be rewritten' : 'Rows written', number_format($tally->written)],
            ['Below threshold (scored_at only)', number_format($tally->touchedOnly)],
            ['Skipped (no title)', number_format($tally->skipped)],
            ['Mean movement', $this->signed($tally->meanMovement()).' points'],
            ['Median movement', $this->signed($tally->medianMovement()).' points'],
            ['Mean absolute movement', $tally->meanAbsoluteMovement().' points'],
            ['Largest rise', $this->signed($tally->largestRise())],
            ['Largest fall', $this->signed($tally->largestFall())],
        ]);

        $this->line('  Threshold crossings:');

        foreach (RescoreTally::THRESHOLDS as $threshold) {
            $counts = $tally->crossings[$threshold] ?? ['up' => 0, 'down' => 0];

            $this->line(sprintf(
                '    %-3d  %s up, %s down',
                $threshold,
                number_format($counts['up']),
                number_format($counts['down'])
            ));
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Dry run complete. Nothing was written. Re-run without --dry-run to apply.');

            return;
        }

        $this->components->info('Pipeline stages, assignees, notes and manual estimates were not touched.');
    }

    private function signed(int|float $value): string
    {
        return $value > 0 ? '+'.$value : (string) $value;
    }
}
