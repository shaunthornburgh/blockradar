<?php

namespace App\Services\Ccod;

/**
 * Running counters for one import. Flushed to the `ccod_imports` row after
 * every chunk so the Artisan command and the dashboard can follow progress.
 */
class ImportTally
{
    public int $rowsImported = 0;

    public int $rowsSkipped = 0;

    public int $rowsFailed = 0;

    public int $titlesCreated = 0;

    public int $titlesUpdated = 0;

    public int $companiesCreated = 0;

    public int $candidatesCreated = 0;

    /** @var array<string, int> Rejection reason => count. */
    public array $rejections = [];

    public function reject(string $reason): void
    {
        $this->rejections[$reason] = ($this->rejections[$reason] ?? 0) + 1;
    }

    public function processed(): int
    {
        return $this->rowsImported + $this->rowsSkipped + $this->rowsFailed;
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        arsort($this->rejections);

        return [
            'companies_created' => $this->companiesCreated,
            'candidates_created' => $this->candidatesCreated,
            'rejections' => $this->rejections,
        ];
    }
}
