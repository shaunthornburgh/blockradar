<?php

namespace App\Services\Candidates;

use App\Models\Title;

/**
 * Works out how many dwellings a title covers.
 *
 * Two sources, in order of trust:
 *
 *  1. EPC certificates matched to the building. Each certificate is one
 *     surveyed dwelling, so counting them is close to measuring rather than
 *     guessing.
 *  2. The CCOD property address, which is all that exists before EPC
 *     enrichment has run.
 *
 * Both are conservative: null means "unknown", never "one".
 */
class UnitCountEstimator
{
    /** Anything above this is a data artefact rather than a block. */
    private const MAX_PLAUSIBLE_UNITS = 200;

    /** Below two units it is not a multi-unit block at all. */
    private const MIN_PLAUSIBLE_UNITS = 2;

    /**
     * The best available count for a title, preferring EPC evidence.
     */
    public function estimateForTitle(Title $title): ?int
    {
        return $this->fromEpc($title) ?? $this->estimate($title->property_address);
    }

    /**
     * Counts the certificates matched to the building.
     *
     * Requires at least two: a single certificate means one dwelling happened
     * to be surveyed, not that the building holds one. Requires medium
     * confidence or better, because a postcode-only match would be counting
     * the neighbours' flats.
     */
    public function fromEpc(Title $title): ?int
    {
        if (! $title->hasUsableEpc()) {
            return null;
        }

        $count = (int) $title->epc_certificate_count;

        if ($count < self::MIN_PLAUSIBLE_UNITS) {
            return null;
        }

        // Deliberately not clamped to MAX_PLAUSIBLE_UNITS: a genuine 250-flat
        // block with 250 certificates is counted, not discarded. That cap
        // exists to reject nonsense parsed out of address text.
        return $count;
    }

    /**
     * Reads a count out of the address text alone.
     */
    public function estimate(?string $address): ?int
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $text = strtolower($address);

        foreach ([
            fn () => $this->fromLabelledRange($text),
            fn () => $this->fromExplicitCount($text),
            fn () => $this->fromLeadingRange($text),
            fn () => $this->fromRepeatedFlats($text),
        ] as $strategy) {
            $units = $strategy();

            if ($units !== null) {
                return $units;
            }
        }

        return null;
    }

    /**
     * "Flats 1-12 Acacia House", "apartments 1 to 8".
     */
    private function fromLabelledRange(string $text): ?int
    {
        $pattern = '/\b(?:flats?|apartments?|units?|dwellings?)\s+'
            .'(\d{1,4})\s*(?:-|–|—|to)\s*(\d{1,4})\b/';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        return $this->rangeSize((int) $m[1], (int) $m[2]);
    }

    /**
     * "Being 14 flats at ...", "12 apartments".
     */
    private function fromExplicitCount(string $text): ?int
    {
        $pattern = '/\b(\d{1,4})\s+(?:flats|apartments|units|dwellings|bedsits)\b/';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        return $this->clamp((int) $m[1]);
    }

    /**
     * A street-number range at the start of the address: "23-25 Joshua Drive".
     *
     * Only matched at the start, so postcodes, years and phone-like digits
     * elsewhere in the string cannot be mistaken for a range.
     */
    private function fromLeadingRange(string $text): ?int
    {
        if (preg_match('/^\s*(\d{1,4})\s*(?:-|–|—)\s*(\d{1,4})\b/', $text, $m) !== 1) {
            return null;
        }

        $start = (int) $m[1];
        $end = (int) $m[2];

        // "23-25" on a terrace usually means 23, 25 — odds only. Where both
        // ends share parity, count every other number.
        if ($start % 2 === $end % 2 && ($end - $start) >= 2) {
            return $this->clamp((int) (($end - $start) / 2) + 1);
        }

        return $this->rangeSize($start, $end);
    }

    /**
     * "Flat 1, Flat 2, Flat 3, ..." — count the mentions.
     */
    private function fromRepeatedFlats(string $text): ?int
    {
        $count = preg_match_all('/\bflat\s+\d{1,4}\b/', $text);

        if ($count === false || $count < self::MIN_PLAUSIBLE_UNITS) {
            return null;
        }

        return $this->clamp($count);
    }

    private function rangeSize(int $start, int $end): ?int
    {
        if ($end <= $start) {
            return null;
        }

        return $this->clamp($end - $start + 1);
    }

    private function clamp(int $units): ?int
    {
        if ($units < self::MIN_PLAUSIBLE_UNITS || $units > self::MAX_PLAUSIBLE_UNITS) {
            return null;
        }

        return $units;
    }
}
