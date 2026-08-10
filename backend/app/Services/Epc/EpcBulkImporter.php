<?php

namespace App\Services\Epc;

use App\Models\EpcCertificate;
use App\Models\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads a bulk EPC extract into `epc_certificates`.
 *
 * Chunked and upserted on certificate number, so re-running the same file
 * updates rather than duplicates, and a newer monthly extract layers cleanly
 * over an older one.
 */
class EpcBulkImporter
{
    private const MAX_CONSECUTIVE_CHUNK_FAILURES = 5;

    public function __construct(private readonly AddressNormaliser $normaliser) {}

    /**
     * @param  (callable(EpcImportTally): void)|null  $onProgress
     */
    public function import(string $path, bool $restrictToKnownPostcodes = true, ?callable $onProgress = null): EpcImportTally
    {
        $reader = new EpcCsvReader($path);
        $tally = new EpcImportTally;

        DB::connection()->disableQueryLog();

        // Every postcode we hold a title in. Roughly 100k titles collapse to
        // far fewer postcodes, so this is a small set to hold in memory and it
        // keeps the certificate table proportional to the portfolio instead of
        // the whole country.
        $wanted = $restrictToKnownPostcodes ? $this->knownPostcodes() : null;

        $tally->postcodeFilterSize = $wanted === null ? null : count($wanted);

        $chunkSize = max(1, (int) config('blockradar.epc.chunk_size', 1000));
        $buffer = [];
        $consecutiveFailures = 0;

        foreach ($reader->rows() as $raw) {
            $tally->rowsRead++;

            $row = EpcRow::fromArray($raw);

            if ($row === null) {
                $tally->rowsSkipped++;

                continue;
            }

            if ($wanted !== null && ($row->postcode === null || ! isset($wanted[$row->postcode]))) {
                $tally->rowsOutsidePortfolio++;

                continue;
            }

            $buffer[] = $row;

            if (count($buffer) < $chunkSize) {
                continue;
            }

            $consecutiveFailures = $this->flushChunk($buffer, $tally, $consecutiveFailures);
            $buffer = [];

            if ($onProgress !== null) {
                $onProgress($tally);
            }
        }

        if ($buffer !== []) {
            $this->flushChunk($buffer, $tally, $consecutiveFailures);
        }

        if ($onProgress !== null) {
            $onProgress($tally);
        }

        return $tally;
    }

    /** @return array<string, true> */
    private function knownPostcodes(): array
    {
        $postcodes = [];

        Title::query()
            ->whereNotNull('postcode')
            ->distinct()
            ->orderBy('postcode')
            ->pluck('postcode')
            ->each(function (string $postcode) use (&$postcodes) {
                $postcodes[strtoupper(trim($postcode))] = true;
            });

        return $postcodes;
    }

    /**
     * @param  array<int, EpcRow>  $rows
     */
    private function flushChunk(array $rows, EpcImportTally $tally, int $consecutiveFailures): int
    {
        try {
            DB::transaction(fn () => $this->upsert($rows, $tally));

            return 0;
        } catch (Throwable $e) {
            $tally->rowsFailed += count($rows);
            $consecutiveFailures++;

            Log::error('EPC chunk failed', [
                'rows' => count($rows),
                'consecutive_failures' => $consecutiveFailures,
                'exception' => $e->getMessage(),
            ]);

            if ($consecutiveFailures >= self::MAX_CONSECUTIVE_CHUNK_FAILURES) {
                throw $e;
            }

            return $consecutiveFailures;
        }
    }

    /**
     * @param  array<int, EpcRow>  $rows
     */
    private function upsert(array $rows, EpcImportTally $tally): void
    {
        $payload = [];
        $now = now();

        foreach ($rows as $row) {
            // Localities are stripped from the building key so an EPC that
            // omits the town still lines up with a CCOD address that includes
            // it.
            $localities = [$row->postTown, $row->council, $row->county];

            $normalisedAddress = $this->normaliser->normalise($row->address);
            $buildingKey = $this->normaliser->buildingKey($row->address, $localities);

            $payload[$row->certificateNumber] = [
                'certificate_number' => $row->certificateNumber,
                'uprn' => $row->uprn,
                'building_reference_number' => $this->limit($row->buildingReferenceNumber, 40),
                'address_line_1' => $this->limit($row->addressLine1, 255),
                'address_line_2' => $this->limit($row->addressLine2, 255),
                'address_line_3' => $this->limit($row->addressLine3, 255),
                'address' => $row->address,
                'address_hash' => $this->normaliser->hash($normalisedAddress),
                'building_key_hash' => $this->normaliser->hash($buildingKey),
                'postcode' => $this->limit($row->postcode, 12),
                'post_town' => $this->limit($row->postTown, 120),
                'council' => $this->limit($row->council, 120),
                'county' => $this->limit($row->county, 120),
                'current_energy_rating' => $row->currentEnergyRating,
                'current_energy_efficiency' => $row->currentEnergyEfficiency,
                'potential_energy_rating' => $row->potentialEnergyRating,
                'potential_energy_efficiency' => $row->potentialEnergyEfficiency,
                'property_type' => $this->limit($row->propertyType, 60),
                'built_form' => $this->limit($row->builtForm, 60),
                'total_floor_area' => $row->totalFloorArea,
                'number_habitable_rooms' => $row->numberHabitableRooms,
                'number_heated_rooms' => $row->numberHeatedRooms,
                'construction_age_band' => $this->limit($row->constructionAgeBand, 60),
                'main_fuel' => $this->limit($row->mainFuel, 120),
                'main_heat_description' => $row->mainHeatDescription,
                'mains_gas_flag' => $this->limit($row->mainsGasFlag, 3),
                'floor_level' => $this->limit($row->floorLevel, 30),
                'flat_storey_count' => $row->flatStoreyCount,
                'tenure' => $this->limit($row->tenure, 40),
                'transaction_type' => $this->limit($row->transactionType, 60),
                'inspection_date' => $row->inspectionDate?->toDateString(),
                'lodgement_date' => $row->lodgementDate?->toDateString(),
                'source' => 'bulk',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === []) {
            return;
        }

        $numbers = array_keys($payload);

        $existing = EpcCertificate::query()
            ->whereIn('certificate_number', $numbers)
            ->count();

        EpcCertificate::upsert(array_values($payload), ['certificate_number'], [
            'uprn', 'building_reference_number',
            'address_line_1', 'address_line_2', 'address_line_3', 'address',
            'address_hash', 'building_key_hash',
            'postcode', 'post_town', 'council', 'county',
            'current_energy_rating', 'current_energy_efficiency',
            'potential_energy_rating', 'potential_energy_efficiency',
            'property_type', 'built_form', 'total_floor_area',
            'number_habitable_rooms', 'number_heated_rooms', 'construction_age_band',
            'main_fuel', 'main_heat_description', 'mains_gas_flag',
            'floor_level', 'flat_storey_count', 'tenure', 'transaction_type',
            'inspection_date', 'lodgement_date', 'source', 'updated_at',
        ]);

        $tally->certificatesCreated += count($numbers) - $existing;
        $tally->certificatesUpdated += $existing;
        $tally->rowsImported += count($numbers);
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
