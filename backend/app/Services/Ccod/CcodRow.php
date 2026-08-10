<?php

namespace App\Services\Ccod;

use App\Enums\Tenure;
use Carbon\CarbonImmutable;

/**
 * A single parsed CCOD row: raw CSV strings turned into typed values.
 */
readonly class CcodRow
{
    /**
     * @param  array<int, CcodProprietor>  $proprietors
     * @param  array<string, string|null>  $raw
     */
    public function __construct(
        public string $titleNumber,
        public Tenure $tenure,
        public string $propertyAddress,
        public ?string $postcode,
        public ?string $district,
        public ?string $county,
        public ?string $region,
        public bool $multipleAddressIndicator,
        public bool $additionalProprietorIndicator,
        public ?int $pricePaidPence,
        public ?CarbonImmutable $dateProprietorAdded,
        public array $proprietors,
        public array $raw,
    ) {}

    /**
     * Returns null when the row carries nothing usable — no title number, or
     * no property address to key a building on.
     *
     * @param  array<string, string|null>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $titleNumber = strtoupper(trim((string) ($row['title_number'] ?? '')));
        $address = trim((string) ($row['property_address'] ?? ''));

        if ($titleNumber === '' || $address === '') {
            return null;
        }

        $proprietors = [];

        foreach (range(1, 4) as $index) {
            $proprietor = CcodProprietor::fromRow($row, $index);

            if ($proprietor !== null) {
                $proprietors[$index] = $proprietor;
            }
        }

        return new self(
            titleNumber: $titleNumber,
            tenure: Tenure::fromCcod($row['tenure'] ?? null),
            propertyAddress: $address,
            postcode: self::normalisePostcode($row['postcode'] ?? null),
            district: $row['district'] ?? null,
            county: $row['county'] ?? null,
            region: $row['region'] ?? null,
            multipleAddressIndicator: self::flag($row['multiple_address_indicator'] ?? null),
            additionalProprietorIndicator: self::flag($row['additional_proprietor_indicator'] ?? null),
            pricePaidPence: self::pricePaidToPence($row['price_paid'] ?? null),
            dateProprietorAdded: self::parseDate($row['date_proprietor_added'] ?? null),
            proprietors: $proprietors,
            raw: $row,
        );
    }

    public function primaryProprietor(): ?CcodProprietor
    {
        $index = (int) config('blockradar.ccod.primary_proprietor_index', 1);

        return $this->proprietors[$index] ?? $this->proprietors[1] ?? null;
    }

    /**
     * CCOD marks true as "Y" and false as an empty cell.
     */
    public static function flag(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtoupper(trim($value)), ['Y', 'YES', 'TRUE', '1'], true);
    }

    /**
     * Price Paid is published in pounds. Everything downstream is pence.
     */
    public static function pricePaidToPence(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        $pounds = (float) $clean;

        // Negative or absurd values are data errors, not prices.
        if ($pounds <= 0 || $pounds > 100_000_000_000.0) {
            return null;
        }

        return (int) round($pounds * 100);
    }

    /**
     * CCOD publishes dates as DD-MM-YYYY. Other separators show up in older
     * extracts, so a few formats are attempted before giving up.
     */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'Y/m/d', 'd.m.Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim($value));
            } catch (\Throwable) {
                continue;
            }

            // createFromFormat is lenient: "31-02-2020" silently rolls over.
            if ($date !== false && $date->format($format) === trim($value)) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    public static function normalisePostcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? '');

        return $clean === '' ? null : $clean;
    }

    /** The outward code, e.g. "M14" from "M14 5TP". */
    public function postcodeDistrict(): ?string
    {
        if ($this->postcode === null) {
            return null;
        }

        $outward = explode(' ', $this->postcode)[0] ?? '';

        return $outward === '' ? null : $outward;
    }
}
