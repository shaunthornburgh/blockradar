<?php

namespace App\Services\Epc;

/**
 * Reduces addresses to comparable form so a CCOD title can be lined up against
 * EPC certificates.
 *
 * The hard part is that the two sides describe different things. CCOD gives
 * the *building*:
 *
 *     "Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester"
 *
 * while each EPC describes one *dwelling* inside it:
 *
 *     "Flat 3, Hawthorn House, 23 Bury New Road"
 *
 * buildingKey() strips the unit designator from both and drops the locality
 * words that only one side tends to carry, leaving
 * "hawthorn house 23 bury new road" for each.
 */
class AddressNormaliser
{
    /**
     * Street-type abbreviations folded to a single spelling. EPC and CCOD are
     * inconsistent about which they use.
     */
    private const ABBREVIATIONS = [
        'road' => 'rd',
        'street' => 'st',
        'avenue' => 'ave',
        'drive' => 'dr',
        'lane' => 'ln',
        'close' => 'cl',
        'court' => 'ct',
        'crescent' => 'cres',
        'gardens' => 'gdns',
        'garden' => 'gdn',
        'place' => 'pl',
        'square' => 'sq',
        'terrace' => 'terr',
        'buildings' => 'bldgs',
        'building' => 'bldg',
        'apartments' => 'apts',
        'apartment' => 'apt',
        'mansions' => 'mans',
        'saint' => 'st',
        'north' => 'n',
        'south' => 's',
        'east' => 'e',
        'west' => 'w',
        'great' => 'gt',
        'upper' => 'up',
        'lower' => 'lwr',
    ];

    /**
     * Leading unit designators. Ordered longest-first so "ground floor flat"
     * is consumed before the bare "flat".
     */
    private const UNIT_PATTERNS = [
        // "ground floor flat", "second floor flat 2", "top floor maisonette"
        '/^(?:the\s+)?(?:ground|first|second|third|fourth|fifth|sixth|top|lower|upper|basement|attic|garden)\s+floor\s+(?:flat|apartment|maisonette|unit)?\s*\d*[a-z]?\b/',
        // "flats 1-8", "flat 3a", "apartments 1 to 12", "unit 4"
        '/^(?:the\s+)?(?:flats?|apartments?|apts?|units?|dwellings?|maisonettes?|studios?)\s*\d+[a-z]?(?:\s*(?:-|–|—|to|and|&)\s*\d+[a-z]?)*\b/',
        // Bare "flat" / "apartment" with no number.
        '/^(?:the\s+)?(?:flats?|apartments?|maisonettes?)\b/',
        // "1a," style prefixes only when followed by a building name.
        '/^\d+[a-z]\s*(?=[a-z]{3,})/',
    ];

    /** Words that carry no discriminating power inside a single postcode. */
    private const NOISE = ['being', 'land', 'and', 'the', 'at', 'of', 'rear', 'to'];

    /**
     * Lowercase, de-punctuate, fold abbreviations and collapse whitespace.
     * Any embedded postcode is removed: matching is already scoped by it.
     */
    public function normalise(?string $address): string
    {
        return $this->fold($this->preClean($address));
    }

    /**
     * Lowercase and drop any embedded postcode, but keep punctuation.
     *
     * Unit designators have to be stripped while the hyphen in "Flats 1-8" is
     * still there — once punctuation is folded to spaces it reads as two
     * separate numbers and the range is lost.
     */
    private function preClean(?string $address): string
    {
        if ($address === null) {
            return '';
        }

        $text = mb_strtolower(trim($address));

        return (string) preg_replace(
            '/\b[a-z]{1,2}\d[a-z\d]?\s*\d[a-z]{2}\b/i',
            ' ',
            $text
        );
    }

    /** De-punctuate, fold abbreviations, drop noise words, collapse spaces. */
    private function fold(string $text): string
    {
        $text = str_replace('&', ' and ', $text);
        $text = (string) preg_replace('/(\d+)(st|nd|rd|th)\b/', '$1', $text);

        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return '';
        }

        $words = array_filter(
            explode(' ', $text),
            static fn (string $word) => $word !== '' && ! in_array($word, self::NOISE, true)
        );

        $words = array_map(
            static fn (string $word) => self::ABBREVIATIONS[$word] ?? $word,
            $words
        );

        return implode(' ', $words);
    }

    /**
     * The building a dwelling sits in, with unit designators and the supplied
     * locality words removed.
     *
     * @param  array<int, string|null>  $localities  Town, district, county — whichever
     *                                               side happens to include them.
     */
    public function buildingKey(?string $address, array $localities = []): string
    {
        $text = $this->preClean($address);

        if (trim($text) === '') {
            return '';
        }

        // Run while punctuation survives, so ranges like "1-8" are seen whole.
        // Designators can nest: "flat 3 ground floor flat".
        foreach (self::UNIT_PATTERNS as $pattern) {
            $previous = null;

            while ($previous !== $text) {
                $previous = $text;
                $text = ltrim(trim((string) preg_replace($pattern, '', $text)), ' ,.-');
            }
        }

        $text = $this->stripLocalities($this->fold($text), $localities);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function hash(string $normalised): ?string
    {
        return $normalised === '' ? null : sha1($normalised);
    }

    /**
     * Percentage similarity of two building keys, 0-100.
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        return round($percent, 2);
    }

    /**
     * Removes trailing town/district/county words. CCOD usually appends the
     * town and EPC usually does not, so leaving them in would sink every
     * comparison.
     *
     * @param  array<int, string|null>  $localities
     */
    private function stripLocalities(string $text, array $localities): string
    {
        foreach ($localities as $locality) {
            $normalised = $this->normalise($locality);

            if ($normalised === '') {
                continue;
            }

            // Only from the end: "manchester road" in the middle of an address
            // is a street name, not a locality.
            $text = (string) preg_replace(
                '/\s*'.preg_quote($normalised, '/').'\s*$/',
                '',
                $text
            );
        }

        return trim($text);
    }
}
