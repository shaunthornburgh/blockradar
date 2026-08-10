<?php

namespace App\Services\Ccod;

use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Streams a CCOD CSV a row at a time.
 *
 * The file is never held in memory: the monthly FULL extract is well over a
 * gigabyte and around 3.5 million rows.
 *
 * Two quirks of the published format are handled here:
 *
 *  - Columns are addressed by header name, not position. HMLR has changed
 *    column order between releases, and the CHANGE_ONLY variant carries two
 *    extra columns the FULL file does not.
 *  - The final line is a `Row Count: N` trailer rather than data. It is both
 *    skipped and used as a free source of the total for progress reporting.
 */
class CcodCsvReader
{
    /**
     * Header text (normalised) => canonical field name.
     *
     * Anything not listed still reaches the row under its normalised header,
     * so unexpected columns are preserved rather than dropped.
     */
    private const HEADER_MAP = [
        'titlenumber' => 'title_number',
        'tenure' => 'tenure',
        'propertyaddress' => 'property_address',
        'district' => 'district',
        'county' => 'county',
        'region' => 'region',
        'postcode' => 'postcode',
        'multipleaddressindicator' => 'multiple_address_indicator',
        'pricepaid' => 'price_paid',
        'dateproprietoradded' => 'date_proprietor_added',
        'additionalproprietorindicator' => 'additional_proprietor_indicator',
        'changeindicator' => 'change_indicator',
        'changedate' => 'change_date',
    ];

    public function __construct(private readonly string $path)
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("CCOD file is not readable: {$this->path}");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The row total published in the file's own trailer, if present.
     *
     * Reading the last kilobyte avoids a full pass over the file just to draw
     * a progress bar.
     */
    public function totalFromTrailer(): ?int
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $size = filesize($this->path) ?: 0;
            $window = (int) min($size, 1024);

            if ($window === 0) {
                return null;
            }

            fseek($handle, -$window, SEEK_END);
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }

        if (preg_match('/Row\s*Count\s*:\s*([0-9,]+)/i', $tail, $matches) !== 1) {
            return null;
        }

        return (int) str_replace(',', '', $matches[1]);
    }

    /**
     * Counts data rows by streaming the file. Only used when the trailer is
     * missing, since it costs a full read.
     */
    public function countDataRows(): int
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        try {
            while (! feof($handle)) {
                $buffer = fread($handle, 1024 * 1024);

                if ($buffer === false) {
                    break;
                }

                $lines += substr_count($buffer, "\n");
            }
        } finally {
            fclose($handle);
        }

        // Discount the header. Quoted addresses can contain newlines, so treat
        // this as the estimate it is.
        return max(0, $lines - 1);
    }

    /**
     * Yields each data row as an associative array keyed by canonical field
     * name, with the header row consumed and the trailer skipped.
     *
     * @return LazyCollection<int, array<string, string|null>>
     */
    public function rows(): LazyCollection
    {
        return LazyCollection::make(function (): iterable {
            $handle = fopen($this->path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Could not open CCOD file: {$this->path}");
            }

            try {
                $header = $this->readCsvLine($handle);

                if ($header === null) {
                    return;
                }

                $keys = $this->mapHeader($header);

                while (($row = $this->readCsvLine($handle)) !== null) {
                    if ($this->isTrailer($row) || $this->isBlank($row)) {
                        continue;
                    }

                    yield $this->combine($keys, $row);
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * @param  resource  $handle
     * @return array<int, string|null>|null
     */
    private function readCsvLine($handle): ?array
    {
        // Escape is disabled so the parser follows RFC 4180 rather than PHP's
        // proprietary backslash handling, which mangles addresses.
        $row = fgetcsv($handle, 0, ',', '"', '');

        if ($row === false || $row === null) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<int, string>
     */
    private function mapHeader(array $header): array
    {
        $keys = [];

        foreach ($header as $index => $column) {
            // Strip a UTF-8 BOM from the very first header cell.
            if ($index === 0 && is_string($column)) {
                $column = preg_replace('/^\x{FEFF}/u', '', $column) ?? $column;
            }

            $normalised = $this->normalise((string) $column);

            $keys[$index] = self::HEADER_MAP[$normalised]
                ?? $this->mapProprietorHeader($normalised)
                ?? ($normalised !== '' ? $normalised : "column_{$index}");
        }

        return $keys;
    }

    /**
     * CCOD repeats a seven-column block for each of up to four proprietors:
     * "Proprietor Name (2)", "Company Registration No. (2)", and so on.
     */
    private function mapProprietorHeader(string $normalised): ?string
    {
        $patterns = [
            '/^proprietorname([1-4])$/' => 'proprietor_%d_name',
            '/^companyregistrationno([1-4])$/' => 'proprietor_%d_company_number',
            '/^proprietorshipcategory([1-4])$/' => 'proprietor_%d_category',
            '/^countryincorporated([1-4])$/' => 'proprietor_%d_country',
            '/^proprietor([1-4])address1$/' => 'proprietor_%d_address_1',
            '/^proprietor([1-4])address2$/' => 'proprietor_%d_address_2',
            '/^proprietor([1-4])address3$/' => 'proprietor_%d_address_3',
        ];

        foreach ($patterns as $pattern => $template) {
            if (preg_match($pattern, $normalised, $matches) === 1) {
                return sprintf($template, (int) $matches[1]);
            }
        }

        return null;
    }

    /**
     * Reduces a header cell to comparable form: "Company Registration No. (1)"
     * becomes "companyregistrationno1".
     */
    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim($value)));
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string|null>  $row
     * @return array<string, string|null>
     */
    private function combine(array $keys, array $row): array
    {
        $out = [];

        foreach ($keys as $index => $key) {
            $value = $row[$index] ?? null;

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /** @param array<int, string|null> $row */
    private function isTrailer(array $row): bool
    {
        $first = trim((string) ($row[0] ?? ''));

        return stripos($first, 'row count') === 0;
    }

    /** @param array<int, string|null> $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
