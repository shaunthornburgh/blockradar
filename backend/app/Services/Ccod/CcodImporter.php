<?php

namespace App\Services\Ccod;

use App\Enums\ImportStatus;
use App\Enums\PipelineStage;
use App\Models\AreaMetric;
use App\Models\Candidate;
use App\Models\CcodImport;
use App\Models\Company;
use App\Models\Title;
use App\Services\Candidates\CandidateFilter;
use App\Services\Candidates\CandidateScorer;
use App\Services\Candidates\UnitCountEstimator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Streams a CCOD extract into titles, companies and candidates.
 *
 * Everything is chunked and upserted, so the import is safe to re-run: a
 * second pass over the same file updates the rows it already created rather
 * than duplicating them, and never disturbs a candidate that is already in
 * the pipeline.
 */
class CcodImporter
{
    /**
     * A run is abandoned after this many consecutive chunk failures. One bad
     * chunk is a data problem worth skipping; five in a row means the database
     * is unhealthy and continuing would just churn.
     */
    private const MAX_CONSECUTIVE_CHUNK_FAILURES = 5;

    public function __construct(
        private readonly CandidateFilter $filter,
        private readonly CandidateScorer $scorer,
        private readonly UnitCountEstimator $units,
    ) {}

    /**
     * @param  (callable(CcodImport, ImportTally): void)|null  $onProgress
     */
    public function import(CcodImport $import, ?callable $onProgress = null): ImportTally
    {
        $reader = new CcodCsvReader($this->resolvePath($import));
        $tally = new ImportTally;

        // The query log grows unbounded over millions of statements.
        DB::connection()->disableQueryLog();

        $import->forceFill([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
            'rows_total' => $reader->totalFromTrailer() ?? $reader->countDataRows(),
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'titles_created' => 0,
            'titles_updated' => 0,
        ])->save();

        $chunkSize = max(1, (int) config('blockradar.ccod.chunk_size', 1000));
        $buffer = [];
        $consecutiveFailures = 0;

        try {
            foreach ($reader->rows() as $rawRow) {
                $buffer[] = $rawRow;

                if (count($buffer) < $chunkSize) {
                    continue;
                }

                $consecutiveFailures = $this->runChunk(
                    $buffer, $import, $tally, $consecutiveFailures
                );

                $buffer = [];
                $this->flush($import, $tally, $onProgress);
            }

            if ($buffer !== []) {
                $this->runChunk($buffer, $import, $tally, $consecutiveFailures);
                $this->flush($import, $tally, $onProgress);
            }

            $import->forceFill([
                'status' => ImportStatus::Completed,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $import->forceFill([
                'status' => ImportStatus::Failed,
                'finished_at' => now(),
                'error' => $this->summariseError($e),
            ])->save();

            throw $e;
        }

        return $tally;
    }

    /**
     * Runs one chunk, tolerating an isolated failure but giving up if the
     * database keeps rejecting work.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @return int The updated consecutive-failure count.
     */
    private function runChunk(array $rows, CcodImport $import, ImportTally $tally, int $consecutiveFailures): int
    {
        try {
            DB::transaction(fn () => $this->processChunk($rows, $import, $tally));

            return 0;
        } catch (Throwable $e) {
            $tally->rowsFailed += count($rows);
            $consecutiveFailures++;

            Log::error('CCOD chunk failed', [
                'import_id' => $import->id,
                'rows' => count($rows),
                'consecutive_failures' => $consecutiveFailures,
                'first_title_number' => $rows[0]['title_number'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            if ($consecutiveFailures >= self::MAX_CONSECUTIVE_CHUNK_FAILURES) {
                throw $e;
            }

            return $consecutiveFailures;
        }
    }

    /**
     * @param  array<int, array<string, string|null>>  $rawRows
     */
    private function processChunk(array $rawRows, CcodImport $import, ImportTally $tally): void
    {
        /** @var array<string, CcodRow> $rows keyed by title number */
        $rows = [];

        foreach ($rawRows as $rawRow) {
            $row = CcodRow::fromArray($rawRow);

            if ($row === null) {
                $tally->rowsSkipped++;

                continue;
            }

            // A title number appearing twice in one chunk would otherwise be
            // upserted twice; the later row wins, matching a full re-import.
            $rows[$row->titleNumber] = $row;
        }

        if ($rows === []) {
            return;
        }

        $companyIds = $this->upsertCompanies($rows, $tally);
        $this->upsertTitles($rows, $companyIds, $import, $tally);
        $this->createCandidates($rows, $tally);

        $tally->rowsImported += count($rows);
    }

    /**
     * @param  array<string, CcodRow>  $rows
     * @return array<string, int> Company number => id.
     */
    private function upsertCompanies(array $rows, ImportTally $tally): array
    {
        $payload = [];

        foreach ($rows as $row) {
            $proprietor = $row->primaryProprietor();

            if ($proprietor === null || ! $proprietor->hasCompany()) {
                continue;
            }

            $payload[$proprietor->companyNumber] = [
                'company_number' => $proprietor->companyNumber,
                'name' => $this->truncate($proprietor->name ?? $proprietor->companyNumber, 255),
                'jurisdiction' => $this->truncate($proprietor->country, 60),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($payload === []) {
            return [];
        }

        $numbers = array_keys($payload);

        $existing = Company::query()
            ->whereIn('company_number', $numbers)
            ->pluck('id', 'company_number');

        $tally->companiesCreated += count($numbers) - $existing->count();

        // Only the timestamp is updated on conflict. CCOD must not overwrite
        // Companies House enrichment, and the name exactly as printed on the
        // title is kept on the title itself.
        Company::upsert(array_values($payload), ['company_number'], ['updated_at']);

        return Company::query()
            ->whereIn('company_number', $numbers)
            ->pluck('id', 'company_number')
            ->all();
    }

    /**
     * @param  array<string, CcodRow>  $rows
     * @param  array<string, int>  $companyIds
     */
    private function upsertTitles(array $rows, array $companyIds, CcodImport $import, ImportTally $tally): void
    {
        $titleNumbers = array_keys($rows);

        $existing = Title::query()
            ->whereIn('title_number', $titleNumbers)
            ->pluck('title_number')
            ->flip();

        $payload = [];
        $now = now();

        foreach ($rows as $titleNumber => $row) {
            $proprietor = $row->primaryProprietor();

            $payload[] = [
                'title_number' => $titleNumber,
                'company_id' => $proprietor?->companyNumber !== null
                    ? ($companyIds[$proprietor->companyNumber] ?? null)
                    : null,
                'ccod_import_id' => $import->id,
                'tenure' => $row->tenure->value,
                'property_address' => $row->propertyAddress,
                'property_address_hash' => Title::hashAddress($row->propertyAddress),
                'postcode' => $this->truncate($row->postcode, 12),
                'district' => $this->truncate($row->district, 120),
                'county' => $this->truncate($row->county, 120),
                'region' => $this->truncate($row->region, 120),
                'multiple_address_indicator' => $row->multipleAddressIndicator,
                'additional_proprietor_indicator' => $row->additionalProprietorIndicator,
                'proprietor_name' => $this->truncate($proprietor?->name, 255),
                'proprietorship_category' => $this->truncate($proprietor?->category, 120),
                'price_paid' => $row->pricePaidPence,
                'date_proprietor_added' => $row->dateProprietorAdded?->toDateString(),
                'estimated_unit_count' => $this->units->estimate($row->propertyAddress),
                'unit_count_source' => 'address',
                // upsert() bypasses Eloquent casts, so JSON is encoded here.
                'raw' => $this->rawPayload($row),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // first_seen_at and created_at are omitted so a re-import cannot
        // rewrite when a title was first observed.
        Title::upsert($payload, ['title_number'], [
            'company_id',
            'ccod_import_id',
            'tenure',
            'property_address',
            'property_address_hash',
            'postcode',
            'district',
            'county',
            'region',
            'multiple_address_indicator',
            'additional_proprietor_indicator',
            'proprietor_name',
            'proprietorship_category',
            'price_paid',
            'date_proprietor_added',
            'raw',
            'last_seen_at',
            'updated_at',
        ]);
        // estimated_unit_count and unit_count_source are insert-only, like
        // first_seen_at. EPC enrichment replaces the address-derived guess
        // with a count of real certificates, and a monthly re-import must not
        // quietly overwrite that with the weaker signal again.

        $tally->titlesCreated += count($titleNumbers) - $existing->count();
        $tally->titlesUpdated += $existing->count();
    }

    /**
     * Only the parts of the CCOD row not already promoted to columns are
     * kept: the second to fourth proprietors, and any column HMLR adds that
     * this importer does not yet know about. Storing every raw field for 3.5
     * million titles would cost gigabytes for no extra information.
     */
    private function rawPayload(CcodRow $row): ?string
    {
        $extraProprietors = [];

        foreach ($row->proprietors as $index => $proprietor) {
            if ($index === 1) {
                continue;
            }

            $extraProprietors[$index] = array_filter([
                'name' => $proprietor->name,
                'company_number' => $proprietor->companyNumber,
                'category' => $proprietor->category,
                'country' => $proprietor->country,
                'address' => $proprietor->address,
            ], fn ($value) => $value !== null);
        }

        $known = [
            'title_number', 'tenure', 'property_address', 'district', 'county',
            'region', 'postcode', 'multiple_address_indicator', 'price_paid',
            'date_proprietor_added', 'additional_proprietor_indicator',
        ];

        $unmapped = [];

        foreach ($row->raw as $key => $value) {
            if ($value === null || in_array($key, $known, true) || str_starts_with($key, 'proprietor_')) {
                continue;
            }

            $unmapped[$key] = $value;
        }

        $payload = array_filter([
            'proprietors' => $extraProprietors,
            'unmapped' => $unmapped,
        ]);

        return $payload === [] ? null : json_encode($payload);
    }

    /**
     * Promotes qualifying titles into the pipeline.
     *
     * Existing candidates are left completely alone — their stage, assignee
     * and notes are user-owned data that a monthly re-import must not touch.
     *
     * @param  array<string, CcodRow>  $rows
     */
    private function createCandidates(array $rows, ImportTally $tally): void
    {
        // company is eager-loaded because the scorer reads it for the
        // Companies House components; without this it is a query per title.
        /** @var Collection<int, Title> $titles */
        $titles = Title::query()
            ->with('company')
            ->whereIn('title_number', array_keys($rows))
            ->get();

        $alreadyCandidates = Candidate::query()
            ->whereIn('title_id', $titles->pluck('id'))
            ->pluck('title_id')
            ->flip();

        $eligible = $titles->reject(function (Title $title) use ($alreadyCandidates, $tally) {
            if ($alreadyCandidates->has($title->id)) {
                return true;
            }

            $reason = $this->filter->rejectionReason($title);

            if ($reason !== null) {
                $tally->reject($reason);

                return true;
            }

            return false;
        });

        if ($eligible->isEmpty()) {
            return;
        }

        $areas = $this->areaMetricsFor($eligible);
        $payload = [];
        $now = now();

        foreach ($eligible as $title) {
            $result = $this->scorer->score($title, $areas[$title->postcodeDistrict()] ?? null);

            $payload[] = [
                'title_id' => $title->id,
                'stage' => PipelineStage::New->value,
                'score' => $result->score,
                // insert() bypasses Eloquent casts, so JSON is encoded here.
                'score_breakdown' => json_encode($result->toArray()),
                'scored_at' => $now,
                'estimated_units' => $title->estimated_unit_count,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Candidate::insert($payload);

        $tally->candidatesCreated += count($payload);
    }

    /**
     * @param  Collection<int, Title>  $titles
     * @return array<string, AreaMetric>
     */
    private function areaMetricsFor(Collection $titles): array
    {
        $districts = $titles
            ->map(fn (Title $title) => $title->postcodeDistrict())
            ->filter()
            ->unique()
            ->values();

        if ($districts->isEmpty()) {
            return [];
        }

        return AreaMetric::query()
            ->whereIn('postcode_district', $districts)
            ->get()
            ->keyBy('postcode_district')
            ->all();
    }

    /**
     * @param  (callable(CcodImport, ImportTally): void)|null  $onProgress
     */
    private function flush(CcodImport $import, ImportTally $tally, ?callable $onProgress): void
    {
        $import->forceFill([
            'rows_imported' => $tally->rowsImported,
            'rows_skipped' => $tally->rowsSkipped,
            'rows_failed' => $tally->rowsFailed,
            'titles_created' => $tally->titlesCreated,
            'titles_updated' => $tally->titlesUpdated,
            // Merged, not replaced: meta also carries the source path the
            // importer resolves on retry.
            'meta' => array_merge($import->meta ?? [], $tally->toMeta()),
        ])->save();

        if ($onProgress !== null) {
            $onProgress($import, $tally);
        }
    }

    private function resolvePath(CcodImport $import): string
    {
        $path = $import->meta['path'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException("Import {$import->id} has no source path recorded.");
        }

        return $path;
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    private function summariseError(Throwable $e): string
    {
        return mb_substr(sprintf(
            '%s: %s (%s:%d)',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ), 0, 2000);
    }
}
