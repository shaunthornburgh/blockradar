<?php

namespace App\Services\Epc;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * One EPC record, normalised out of either the bulk CSV or the developer API.
 *
 * The two sources name their fields differently — the CSV uses SCREAMING_SNAKE
 * (LMK_KEY, TOTAL_FLOOR_AREA) and the API camelCase (certificateNumber,
 * currentEnergyEfficiencyBand) — so both spellings are accepted and the
 * differences stop here.
 */
readonly class EpcRow
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $certificateNumber,
        public ?string $uprn,
        public ?string $buildingReferenceNumber,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $addressLine3,
        public string $address,
        public ?string $postcode,
        public ?string $postTown,
        public ?string $council,
        public ?string $county,
        public ?string $currentEnergyRating,
        public ?int $currentEnergyEfficiency,
        public ?string $potentialEnergyRating,
        public ?int $potentialEnergyEfficiency,
        public ?string $propertyType,
        public ?string $builtForm,
        public ?float $totalFloorArea,
        public ?int $numberHabitableRooms,
        public ?int $numberHeatedRooms,
        public ?string $constructionAgeBand,
        public ?string $mainFuel,
        public ?string $mainHeatDescription,
        public ?string $mainsGasFlag,
        public ?string $floorLevel,
        public ?int $flatStoreyCount,
        public ?string $tenure,
        public ?string $transactionType,
        public ?CarbonImmutable $inspectionDate,
        public ?CarbonImmutable $lodgementDate,
        public array $raw,
    ) {}

    /**
     * Returns null when the row has no certificate number or nothing that can
     * serve as an address — either makes it unmatchable.
     *
     * @param  array<string, mixed>  $row  Keyed by normalised header.
     */
    public static function fromArray(array $row): ?self
    {
        $certificateNumber = self::string($row, ['lmkkey', 'certificatenumber', 'certificate_number']);

        if ($certificateNumber === null) {
            return null;
        }

        $line1 = self::string($row, ['address1', 'addressline1']);
        $line2 = self::string($row, ['address2', 'addressline2']);
        $line3 = self::string($row, ['address3', 'addressline3']);
        $line4 = self::string($row, ['address4', 'addressline4']);

        $address = self::string($row, ['address'])
            ?? collect([$line1, $line2, $line3, $line4])->filter()->implode(', ');

        if ($address === '') {
            return null;
        }

        return new self(
            certificateNumber: $certificateNumber,
            uprn: self::uprn(self::string($row, ['uprn'])),
            buildingReferenceNumber: self::string($row, ['buildingreferencenumber']),
            addressLine1: $line1,
            addressLine2: $line2,
            addressLine3: $line3,
            address: $address,
            postcode: self::postcode(self::string($row, ['postcode'])),
            postTown: self::string($row, ['posttown']),
            council: self::string($row, ['localauthoritylabel', 'localauthority', 'council']),
            county: self::string($row, ['county']),
            currentEnergyRating: self::rating(self::string($row, [
                'currentenergyrating', 'currentenergyefficiencyband',
            ])),
            currentEnergyEfficiency: self::int($row, ['currentenergyefficiency']),
            potentialEnergyRating: self::rating(self::string($row, [
                'potentialenergyrating', 'potentialenergyefficiencyband',
            ])),
            potentialEnergyEfficiency: self::int($row, ['potentialenergyefficiency']),
            propertyType: self::string($row, ['propertytype']),
            builtForm: self::string($row, ['builtform']),
            totalFloorArea: self::float($row, ['totalfloorarea']),
            numberHabitableRooms: self::int($row, ['numberhabitablerooms']),
            numberHeatedRooms: self::int($row, ['numberheatedrooms']),
            constructionAgeBand: self::string($row, ['constructionageband']),
            mainFuel: self::string($row, ['mainfuel']),
            mainHeatDescription: self::string($row, ['mainheatdescription', 'mainheatdesc']),
            mainsGasFlag: self::string($row, ['mainsgasflag']),
            floorLevel: self::string($row, ['floorlevel']),
            flatStoreyCount: self::int($row, ['flatstoreycount']),
            tenure: self::string($row, ['tenure']),
            transactionType: self::string($row, ['transactiontype']),
            inspectionDate: self::date($row, ['inspectiondate']),
            lodgementDate: self::date($row, ['lodgementdate', 'registrationdate', 'lodgementdatetime']),
            raw: $row,
        );
    }

    /** @param array<int, string> $keys */
    private static function string(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);

            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);

                if ($value !== '' && strcasecmp($value, 'NO DATA!') !== 0 && strcasecmp($value, 'NA') !== 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<int, string> $keys */
    private static function int(array $row, array $keys): ?int
    {
        $value = self::string($row, $keys);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $int = (int) round((float) $value);

        return $int >= 0 ? $int : null;
    }

    /** @param array<int, string> $keys */
    private static function float(array $row, array $keys): ?float
    {
        $value = self::string($row, $keys);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        // Floor areas are square metres; anything outside this is a data error.
        return $float > 0 && $float < 100_000 ? round($float, 2) : null;
    }

    /** @param array<int, string> $keys */
    private static function date(array $row, array $keys): ?CarbonImmutable
    {
        $value = self::string($row, $keys);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private static function rating(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $rating = strtoupper(trim($value));

        return in_array($rating, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true) ? $rating : null;
    }

    /** UPRNs are 12 digits, left-padded with zeroes. */
    private static function uprn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '' || (int) $digits === 0 || strlen($digits) > 12) {
            return null;
        }

        return str_pad($digits, 12, '0', STR_PAD_LEFT);
    }

    private static function postcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/\s+/', ' ', trim($value)));

        return $clean === '' ? null : $clean;
    }
}
