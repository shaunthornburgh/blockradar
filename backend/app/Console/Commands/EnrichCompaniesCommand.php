<?php

namespace App\Console\Commands;

use App\Enums\EnrichmentStatus;
use App\Jobs\EnrichCompaniesJob;
use App\Models\Company;
use App\Services\CompaniesHouse\CompaniesHouseService;
use App\Services\CompaniesHouse\CompanyEnricher;
use App\Services\CompaniesHouse\EnrichmentOutcome;
use App\Services\CompaniesHouse\Exceptions\InvalidApiKeyException;
use App\Services\CompaniesHouse\Exceptions\RateLimitExceededException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EnrichCompaniesCommand extends Command
{
    protected $signature = 'companies:enrich
        {company? : A single company registration number to enrich}
        {--limit=100 : Maximum number of companies to process}
        {--force : Re-enrich even if the data is still fresh}
        {--only-candidates : Only companies with at least one candidate}
        {--sync : Enrich in this process instead of dispatching to the queue}
        {--batch=25 : Companies per queued job}';

    protected $description = 'Enrich company records from the Companies House API';

    public function handle(CompaniesHouseService $api, CompanyEnricher $enricher): int
    {
        if (! $api->isConfigured()) {
            $this->components->error('COMPANIES_HOUSE_API_KEY is not set.');
            $this->line('  Get a free key at https://developer.company-information.service.gov.uk');
            $this->line('  then add it to backend/.env and run: docker compose restart php queue');

            return self::FAILURE;
        }

        $companies = $this->select();

        if ($companies->isEmpty()) {
            $this->components->info('Nothing to enrich.');
            $this->explainEmptySelection();

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%s companies selected. Rate limit budget: %s of %s requests left in this window.',
            number_format($companies->count()),
            number_format($api->throttle()->remaining()),
            number_format($api->throttle()->limit())
        ));

        return $this->option('sync')
            ? $this->runSync($companies, $enricher)
            : $this->runQueued($companies);
    }

    /**
     * Companies linked to candidates come first, highest scoring first, so a
     * limited run spends its budget where it changes decisions.
     *
     * @return Collection<int, Company>
     */
    private function select(): Collection
    {
        $number = $this->argument('company');

        if ($number !== null) {
            return Company::query()
                ->where('company_number', strtoupper(trim($number)))
                ->get();
        }

        return Company::query()
            ->when(
                $this->option('only-candidates'),
                fn (Builder $query) => $query->whereHas('candidates')
            )
            ->when(
                ! $this->option('force'),
                fn (Builder $query) => $query->needsEnrichment()
            )
            ->withCount('candidates')
            ->withMax('candidates', 'score')
            ->orderByDesc('candidates_count')
            ->orderByDesc('candidates_max_score')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    private function explainEmptySelection(): void
    {
        $skipped = Company::query()
            ->where('enrichment_status', EnrichmentStatus::NotFound)
            ->count();

        $exhausted = Company::query()
            ->where('enrichment_attempts', '>=', (int) config('blockradar.companies_house.max_enrichment_attempts', 5))
            ->count();

        if ($skipped > 0 || $exhausted > 0) {
            $this->line(sprintf(
                '  %s not found at Companies House and %s past the attempt limit are permanently skipped. Use --force to retry them.',
                number_format($skipped),
                number_format($exhausted)
            ));
        }
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private function runQueued(Collection $companies): int
    {
        $size = max(1, (int) $this->option('batch'));
        $batches = $companies->pluck('id')->chunk($size);

        foreach ($batches as $batch) {
            EnrichCompaniesJob::dispatch($batch->values()->all(), (bool) $this->option('force'));
        }

        $this->components->info(sprintf(
            'Queued %s companies in %s job(s) of up to %d.',
            number_format($companies->count()),
            number_format($batches->count()),
            $size
        ));
        $this->line('  A batch that hits the rate limit requeues only what is left, so nothing is repeated.');
        $this->line('  Watch it with: php artisan companies:enrich-status');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private function runSync(Collection $companies, CompanyEnricher $enricher): int
    {
        $bar = $this->output->createProgressBar($companies->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $tally = [
            EnrichmentOutcome::Enriched->value => 0,
            EnrichmentOutcome::Skipped->value => 0,
            EnrichmentOutcome::NotFound->value => 0,
            EnrichmentOutcome::Failed->value => 0,
        ];

        $force = (bool) $this->option('force');

        foreach ($companies as $company) {
            try {
                $outcome = $enricher->enrich($company, $force);
            } catch (RateLimitExceededException $e) {
                // In-process there is nothing else to do but wait it out.
                $bar->setMessage("rate limited, waiting {$e->retryAfterSeconds}s");
                sleep($e->retryAfterSeconds);

                try {
                    $outcome = $enricher->enrich($company, $force);
                } catch (RateLimitExceededException) {
                    $bar->clear();
                    $this->newLine();
                    $this->components->warn('Still rate limited after waiting. Stopping; re-run to continue.');
                    break;
                }
            } catch (InvalidApiKeyException $e) {
                $bar->clear();
                $this->newLine();
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $tally[$outcome->value]++;

            $bar->setMessage(sprintf(
                '%s (%s)',
                $company->company_number,
                str_replace('_', ' ', $outcome->value)
            ));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->summarise($tally);

        return self::SUCCESS;
    }

    /** @param array<string, int> $tally */
    private function summarise(array $tally): void
    {
        $this->table(['Outcome', 'Companies'], [
            ['Enriched', number_format($tally[EnrichmentOutcome::Enriched->value])],
            ['Skipped (still fresh)', number_format($tally[EnrichmentOutcome::Skipped->value])],
            ['Not found', number_format($tally[EnrichmentOutcome::NotFound->value])],
            ['Failed', number_format($tally[EnrichmentOutcome::Failed->value])],
        ]);

        if ($tally[EnrichmentOutcome::Failed->value] > 0) {
            $this->line('  Failures are recorded on each company in enrichment_error.');
        }

        $this->components->warn(
            'Existing candidate scores are unchanged. Run `php artisan candidates:rescore` to apply this '.
            'enrichment to candidates that already exist.'
        );
    }
}
