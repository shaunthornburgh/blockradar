<?php

namespace App\Console\Commands;

use App\Enums\EpcMatchConfidence;
use App\Jobs\EnrichTitlesEpcJob;
use App\Models\EpcCertificate;
use App\Models\Title;
use App\Services\Epc\EpcEnrichmentTally;
use App\Services\Epc\TitleEpcEnricher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class EnrichEpcCommand extends Command
{
    protected $signature = 'epc:enrich
        {--limit=500 : Maximum number of titles to process}
        {--force : Re-match even if the title was matched recently}
        {--only-candidates : Only titles that are already in the pipeline}
        {--sync : Match in this process instead of dispatching to the queue}
        {--min-confidence= : high, medium or low. Defaults to config (medium)}
        {--batch=200 : Titles per queued job}';

    protected $description = 'Match titles to EPC certificates and write the resulting property data';

    public function handle(TitleEpcEnricher $enricher): int
    {
        $minimum = $this->minimumConfidence();

        if ($minimum === null) {
            $this->components->error('--min-confidence must be one of: '.implode(', ', EpcMatchConfidence::values()));

            return self::FAILURE;
        }

        if (EpcCertificate::query()->count() === 0) {
            $this->components->error('No EPC certificates are loaded.');
            $this->line('  Run: php artisan epc:import');

            return self::FAILURE;
        }

        $titles = $this->select();

        if ($titles->isEmpty()) {
            $this->components->info('Nothing to match.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%s titles selected. Minimum confidence: %s.',
            number_format($titles->count()),
            $minimum->value
        ));

        if ($minimum === EpcMatchConfidence::Low) {
            $this->components->warn(
                'Low confidence accepts postcode-only matches, which sweep in neighbouring buildings. '.
                'The certificate count is then no longer a reliable unit count.'
            );
        }

        return $this->option('sync')
            ? $this->runSync($titles, $enricher, $minimum)
            : $this->runQueued($titles, $minimum);
    }

    /**
     * Candidate-linked titles first, best scoring first — the same
     * prioritisation the Companies House enricher uses.
     *
     * @return Collection<int, Title>
     */
    private function select(): Collection
    {
        $staleDays = (int) config('blockradar.epc.match.stale_after_days', 180);

        return Title::query()
            ->when(
                $this->option('only-candidates'),
                fn (Builder $query) => $query->whereHas('candidate')
            )
            ->when(
                ! $this->option('force'),
                fn (Builder $query) => $query->where(function (Builder $inner) use ($staleDays) {
                    $inner->whereNull('epc_enriched_at')
                        ->orWhere('epc_enriched_at', '<', now()->subDays($staleDays));
                })
            )
            // Matching needs a postcode to narrow on.
            ->whereNotNull('postcode')
            ->withMax('candidate', 'score')
            ->orderByDesc('candidate_max_score')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    /**
     * @param  Collection<int, Title>  $titles
     */
    private function runQueued(Collection $titles, EpcMatchConfidence $minimum): int
    {
        $size = max(1, (int) $this->option('batch'));
        $batches = $titles->pluck('id')->chunk($size);

        foreach ($batches as $batch) {
            EnrichTitlesEpcJob::dispatch(
                $batch->values()->all(),
                (bool) $this->option('force'),
                $minimum->value
            );
        }

        $this->components->info(sprintf(
            'Queued %s titles in %s job(s) of up to %d.',
            number_format($titles->count()),
            number_format($batches->count()),
            $size
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Title>  $titles
     */
    private function runSync(Collection $titles, TitleEpcEnricher $enricher, EpcMatchConfidence $minimum): int
    {
        $bar = $this->output->createProgressBar($titles->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $tally = new EpcEnrichmentTally;
        $force = (bool) $this->option('force');

        foreach ($titles as $title) {
            $tally->processed++;

            if (! $force && ! $enricher->needsEnrichment($title)) {
                $tally->skipped++;
                $bar->advance();

                continue;
            }

            try {
                $set = $enricher->enrich($title, $force, $minimum);
            } catch (Throwable $e) {
                // One bad title must not stop the run.
                $tally->unmatched++;
                $bar->setMessage('error on '.$title->title_number);
                $bar->advance();

                report($e);

                continue;
            }

            $tally->record($set);

            if ($set->isEmpty()) {
                $tally->unmatched++;
            } elseif ($set->meets($minimum)) {
                $tally->matched++;
                $tally->certificatesLinked += $set->count();

                if ($title->refresh()->unit_count_source === 'epc') {
                    $tally->unitCountsImproved++;
                }
            } else {
                $tally->belowThreshold++;
                $tally->unmatched++;
            }

            $bar->setMessage(sprintf(
                '%s (%s)',
                $title->title_number,
                $set->confidence?->value ?? 'no match'
            ));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->summarise($tally);

        return self::SUCCESS;
    }

    private function summarise(EpcEnrichmentTally $tally): void
    {
        $this->table(['Metric', 'Count'], [
            ['Titles processed', number_format($tally->processed)],
            ['Matched and written', number_format($tally->matched)],
            ['Unmatched', number_format($tally->unmatched)],
            ['Matched but below threshold', number_format($tally->belowThreshold)],
            ['Skipped (already matched)', number_format($tally->skipped)],
            ['Certificates linked', number_format($tally->certificatesLinked)],
            ['Unit counts improved by EPC', number_format($tally->unitCountsImproved)],
            ['Match rate', $tally->matchRate().'%'],
        ]);

        $this->line('  Match quality:');

        foreach ($tally->byMethod as $method => $count) {
            $this->line(sprintf('    %-18s %s', str_replace('_', ' ', $method), number_format($count)));
        }

        if ($tally->byMethod === []) {
            $this->line('    nothing matched');
        }

        $this->newLine();
        $this->components->warn(
            'Existing candidate scores are unchanged. Run `php artisan candidates:rescore` to apply this '.
            'EPC data to candidates that already exist.'
        );
    }

    private function minimumConfidence(): ?EpcMatchConfidence
    {
        $value = $this->option('min-confidence');

        if ($value === null || $value === '') {
            return EpcMatchConfidence::tryFrom(
                (string) config('blockradar.epc.match.min_confidence', 'medium')
            ) ?? EpcMatchConfidence::Medium;
        }

        return EpcMatchConfidence::tryFrom(strtolower(trim((string) $value)));
    }
}
