<?php

namespace App\Services\Ccod;

/**
 * One of the up-to-four proprietors listed against a CCOD title.
 */
readonly class CcodProprietor
{
    public function __construct(
        public ?string $name,
        public ?string $companyNumber,
        public ?string $category,
        public ?string $country,
        public ?string $address,
    ) {}

    /**
     * @param  array<string, string|null>  $row
     */
    public static function fromRow(array $row, int $index): ?self
    {
        $name = $row["proprietor_{$index}_name"] ?? null;
        $companyNumber = self::normaliseCompanyNumber($row["proprietor_{$index}_company_number"] ?? null);

        if ($name === null && $companyNumber === null) {
            return null;
        }

        $address = collect([
            $row["proprietor_{$index}_address_1"] ?? null,
            $row["proprietor_{$index}_address_2"] ?? null,
            $row["proprietor_{$index}_address_3"] ?? null,
        ])->filter()->implode(', ');

        return new self(
            name: $name,
            companyNumber: $companyNumber,
            category: $row["proprietor_{$index}_category"] ?? null,
            country: $row["proprietor_{$index}_country"] ?? null,
            address: $address === '' ? null : $address,
        );
    }

    /**
     * Companies House numbers are eight characters, either all digits or a
     * two-letter prefix plus six digits. CCOD also carries overseas registration
     * numbers in this column, so anything plausibly identifier-shaped is kept
     * and anything that is clearly a placeholder is discarded.
     */
    public static function normaliseCompanyNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');

        if ($clean === '') {
            return null;
        }

        if (in_array($clean, ['NA', 'N/A', 'NONE', 'UNKNOWN', '0', '00000000'], true)) {
            return null;
        }

        if (strlen($clean) > 20) {
            return null;
        }

        // Pad bare numeric registrations to the canonical eight characters so
        // "123456" and "00123456" resolve to the same company.
        if (ctype_digit($clean) && strlen($clean) < 8) {
            $clean = str_pad($clean, 8, '0', STR_PAD_LEFT);
        }

        return $clean;
    }

    public function hasCompany(): bool
    {
        return $this->companyNumber !== null;
    }
}
