<?php

namespace App\Services\Epc;

use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Streams a bulk EPC domestic extract.
 *
 * The full England & Wales file runs to tens of millions of certificates, so
 * nothing is held in memory. Columns are addressed by header name because
 * MHCLG's export has changed shape between releases and the per-local-authority
 * downloads do not always carry the same set.
 */
class EpcCsvReader
{
    public function __construct(private readonly string $path)
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("EPC file is not readable: {$this->path}");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Rough row count, used only to size a progress bar.
     */
    public function estimateRows(): int
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

        return max(0, $lines - 1);
    }

    /**
     * @return LazyCollection<int, array<string, string|null>>
     */
    public function rows(): LazyCollection
    {
        return LazyCollection::make(function (): iterable {
            $handle = fopen($this->path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Could not open EPC file: {$this->path}");
            }

            try {
                $header = $this->readLine($handle);

                if ($header === null) {
                    return;
                }

                $keys = $this->mapHeader($header);

                while (($row = $this->readLine($handle)) !== null) {
                    if ($this->isBlank($row)) {
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
    private function readLine($handle): ?array
    {
        // Escape disabled so parsing follows RFC 4180 rather than PHP's
        // proprietary backslash handling.
        $row = fgetcsv($handle, 0, ',', '"', '');

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Header cells are reduced to lowercase alphanumerics, so LMK_KEY,
     * "LMK Key" and lmkKey all arrive as "lmkkey".
     *
     * @param  array<int, string|null>  $header
     * @return array<int, string>
     */
    private function mapHeader(array $header): array
    {
        $keys = [];

        foreach ($header as $index => $column) {
            if ($index === 0 && is_string($column)) {
                $column = preg_replace('/^\x{FEFF}/u', '', $column) ?? $column;
            }

            $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim((string) $column)));

            $keys[$index] = $normalised !== '' ? $normalised : "column_{$index}";
        }

        return $keys;
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
