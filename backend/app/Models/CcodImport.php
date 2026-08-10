<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the monthly CCOD file through the importer.
 *
 * Written by CcodImporter, and the record a queued import reports progress
 * through so `ccod:status` can follow it.
 */
#[Fillable([
    'filename',
    'period',
    'checksum',
    'status',
    'rows_total',
    'rows_imported',
    'rows_skipped',
    'rows_failed',
    'titles_created',
    'titles_updated',
    'started_at',
    'finished_at',
    'error',
    'meta',
])]
class CcodImport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'period' => 'date',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
            'titles_created' => 'integer',
            'titles_updated' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return HasMany<Title, $this> */
    public function titles(): HasMany
    {
        return $this->hasMany(Title::class);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at);
    }
}
