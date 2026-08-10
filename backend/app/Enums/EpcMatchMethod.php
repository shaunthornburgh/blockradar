<?php

namespace App\Enums;

/**
 * How a title was linked to its EPC certificates, in descending order of
 * reliability.
 */
enum EpcMatchMethod: string
{
    /** The title carries a UPRN and the certificate shares it. */
    case Uprn = 'uprn';

    /** Same postcode and an identical normalised building key. */
    case ExactAddress = 'exact_address';

    /** Same postcode and a building key above the similarity threshold. */
    case FuzzyAddress = 'fuzzy_address';

    /** Same postcode only — could be the building next door. */
    case Postcode = 'postcode';

    public function label(): string
    {
        return match ($this) {
            self::Uprn => 'UPRN',
            self::ExactAddress => 'Exact address',
            self::FuzzyAddress => 'Fuzzy address',
            self::Postcode => 'Postcode only',
        };
    }

    public function confidence(): EpcMatchConfidence
    {
        return match ($this) {
            self::Uprn, self::ExactAddress => EpcMatchConfidence::High,
            self::FuzzyAddress => EpcMatchConfidence::Medium,
            self::Postcode => EpcMatchConfidence::Low,
        };
    }
}
