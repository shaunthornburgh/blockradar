<?php

namespace App\Console\Commands;

use App\Enums\EnrichmentStatus;
use App\Models\Company;
use App\Services\CompaniesHouse\CompaniesHouseService;
use Illuminate\Console\Command;

/**
 * How far Companies House enrichment has got, and how much rate-limit budget
 * is left — useful while a queued batch is running.
 */
class EnrichmentStatusCommand extends Command
{
    protected $signature = 'companies:enrich-status {--watch : Refresh every few seconds}';

    protected $description = 'Show Companies House enrichment coverage and rate-limit budget';

    public function handle(CompaniesHouseService $api): int
    {
        do {
            if ($this->option('watch')) {
                $this->output->write("\033[2J\033[H");
            }

            $total = Company::query()->count();
            $withCandidates = Company::query()->whereHas('candidates')->count();
            $enriched = Company::query()->whereNotNull('enriched_at')->count();
            $pending = Company::query()->needsEnrichment()->count();

            $this->table(['Metric', 'Count'], [
                ['Companies', number_format($total)],
                ['Linked to a candidate', number_format($withCandidates)],
                ['Enriched', sprintf('%s (%s%%)', number_format($enriched), $total > 0 ? round($enriched / $total * 100) : 0)],
                ['Awaiting enrichment', number_format($pending)],
                ['Not found', number_format(Company::query()->where('enrichment_status', EnrichmentStatus::NotFound)->count())],
                ['Failed', number_format(Company::query()->where('enrichment_status', EnrichmentStatus::Failed)->count())],
                ['Accounts overdue', number_format(Company::query()->where('accounts_overdue', true)->count())],
                ['With charges', number_format(Company::query()->where('has_charges', true)->count())],
                ['Distressed or dissolved', number_format(Company::query()->whereIn('status', [
                    'liquidation', 'receivership', 'administration',
                    'voluntary-arrangement', 'insolvency-proceedings', 'dissolved',
                ])->count())],
                ['Rate limit remaining', sprintf(
                    '%s / %s%s',
                    number_format($api->throttle()->remaining()),
                    number_format($api->throttle()->limit()),
                    $api->throttle()->remaining() === 0 ? ' (resets in '.$api->throttle()->availableIn().'s)' : ''
                )],
            ]);

            if (! $this->option('watch') || $pending === 0) {
                return self::SUCCESS;
            }

            sleep(3);
        } while (true);
    }
}
