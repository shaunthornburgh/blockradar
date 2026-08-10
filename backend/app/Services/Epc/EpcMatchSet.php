<?php

namespace App\Services\Epc;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Models\EpcCertificate;
use Illuminate\Support\Collection;

/**
 * The certificates believed to belong to one title, and the evidence.
 */
readonly class EpcMatchSet
{
    /**
     * @param  Collection<int, EpcCertificate>  $certificates
     */
    public function __construct(
        public Collection $certificates,
        public ?EpcMatchMethod $method,
        public ?EpcMatchConfidence $confidence,
        public ?float $similarity,
    ) {}

    public static function none(): self
    {
        return new self(collect(), null, null, null);
    }

    public function isEmpty(): bool
    {
        return $this->certificates->isEmpty();
    }

    public function count(): int
    {
        return $this->certificates->count();
    }

    public function meets(EpcMatchConfidence $minimum): bool
    {
        return $this->confidence !== null && $this->confidence->atLeast($minimum);
    }

    /**
     * The representative certificate: the most recently lodged, since that is
     * the freshest view of the building.
     */
    public function primary(): ?EpcCertificate
    {
        return $this->certificates
            ->sortByDesc(fn (EpcCertificate $c) => $c->lodgement_date?->timestamp ?? 0)
            ->first();
    }
}
