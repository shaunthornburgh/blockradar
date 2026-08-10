<?php

namespace App\Enums;

/**
 * The deal pipeline a candidate block moves through.
 */
enum PipelineStage: string
{
    case New = 'new';
    case TitleBought = 'title_bought';
    case Confirmed = 'confirmed';
    case Outreach = 'outreach';
    case Offer = 'offer';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::TitleBought => 'Title Bought',
            self::Confirmed => 'Confirmed',
            self::Outreach => 'Outreach',
            self::Offer => 'Offer',
        };
    }

    /**
     * Position in the funnel, used for ordering and progress display.
     */
    public function order(): int
    {
        return match ($this) {
            self::New => 0,
            self::TitleBought => 1,
            self::Confirmed => 2,
            self::Outreach => 3,
            self::Offer => 4,
        };
    }

    /**
     * The candidate column stamped when a candidate enters this stage.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::New => null,
            self::TitleBought => 'title_bought_at',
            self::Confirmed => 'confirmed_at',
            self::Outreach => 'outreach_at',
            self::Offer => 'offered_at',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
