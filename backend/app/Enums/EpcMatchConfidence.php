<?php

namespace App\Enums;

/**
 * How much to trust the link between a title and an EPC certificate.
 */
enum EpcMatchConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Higher is better. Used for threshold comparisons and sorting. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
