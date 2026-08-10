<?php

namespace App\Services\Ccod;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Finds the CCOD extract to import and works out which month it covers.
 */
class CcodFileLocator
{
    /**
     * Resolves an explicit path, or falls back to the newest CSV in the
     * configured drop directory.
     *
     * An explicit path is tried as given, then relative to the current working
     * directory, then relative to the drop directory — so both
     * `ccod:import /data/CCOD_FULL_2026_07.csv` and
     * `ccod:import CCOD_FULL_2026_07.csv` work.
     */
    public function resolve(?string $path = null): string
    {
        if ($path === null || trim($path) === '') {
            return $this->latestInStorage();
        }

        foreach ($this->candidatePaths(trim($path)) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return (string) (realpath($candidate) ?: $candidate);
            }
        }

        throw new RuntimeException(
            "No readable CCOD file at [{$path}]. Looked in the working directory and ".$this->directory().'.'
        );
    }

    /** @return array<int, string> */
    private function candidatePaths(string $path): array
    {
        if (str_starts_with($path, '/')) {
            return [$path];
        }

        return [
            $path,
            getcwd().DIRECTORY_SEPARATOR.$path,
            $this->directory().DIRECTORY_SEPARATOR.$path,
        ];
    }

    /**
     * The newest extract in the drop directory. CCOD filenames embed the
     * period (CCOD_FULL_2026_07.csv), so a reverse name sort is chronological;
     * modification time settles anything unconventional.
     */
    public function latestInStorage(): string
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            throw new RuntimeException(
                "The CCOD directory does not exist: {$directory}. Create it and drop the monthly CSV in."
            );
        }

        $files = array_values(array_filter(
            (array) glob($directory.DIRECTORY_SEPARATOR.'*.[cC][sS][vV]'),
            static fn ($file) => is_string($file) && is_readable($file)
        ));

        if ($files === []) {
            throw new RuntimeException(
                "No CSV files found in {$directory}. Download the CCOD extract from ".
                'https://use-land-property-data.service.gov.uk and place it there.'
            );
        }

        usort($files, static function (string $a, string $b): int {
            return [basename($b), filemtime($b) ?: 0] <=> [basename($a), filemtime($a) ?: 0];
        });

        return (string) (realpath($files[0]) ?: $files[0]);
    }

    public function directory(): string
    {
        return storage_path('app/'.trim((string) config('blockradar.ccod.storage_path', 'ccod'), '/'));
    }

    /**
     * The month the extract covers, taken from the filename where possible
     * and from the file's modification date otherwise.
     */
    public function periodFor(string $path): CarbonImmutable
    {
        if (preg_match('/(20\d{2})[_\-.]?(0[1-9]|1[0-2])/', basename($path), $m) === 1) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        }

        $mtime = filemtime($path);

        return ($mtime !== false
            ? CarbonImmutable::createFromTimestamp($mtime)
            : CarbonImmutable::now())->startOfMonth();
    }
}
