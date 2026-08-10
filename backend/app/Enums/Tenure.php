<?php

namespace App\Enums;

/**
 * Tenure as published in the HM Land Registry CCOD dataset.
 */
enum Tenure: string
{
    case Freehold = 'freehold';
    case Leasehold = 'leasehold';
    case Unknown = 'unknown';

    /**
     * CCOD prints tenure as "Freehold" / "Leasehold", with blanks in older rows.
     */
    public static function fromCcod(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'freehold' => self::Freehold,
            'leasehold' => self::Leasehold,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
