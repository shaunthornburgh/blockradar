<?php

namespace App\Services\Candidates;

use App\Enums\EpcMatchConfidence;
use App\Models\Candidate;
use App\Models\Title;

/**
 * How likely a candidate is to be an actual block of flats.
 *
 * The candidate population is "freehold + multiple address indicator", which
 * is the widest net HM Land Registry lets us cast. It also catches terraces
 * held as one title, land parcels, parades of shops and houses with an
 * outbuilding. This turns the evidence already on the title into a 0-100
 * confidence that the thing is a MUFB, so the list can be sorted and filtered
 * towards real blocks.
 *
 * Distinct from CandidateScorer, which answers "is this deal worth doing?".
 * This one answers "is this even a block?" — and it is derived at query time,
 * so retuning the weights in config/blockradar.php takes effect on the next
 * request with no rescore and no write to live data.
 *
 * Every signal exists twice: once as SQL, for filtering and sorting inside the
 * database, and once in PHP, for the badge on the list row. The two are held
 * to the same answer by MufbSignalsTest.
 */
class MufbSignals
{
    /** Minimum certificates before EPCs evidence more than one dwelling. */
    private const MULTI_CERTIFICATE_MINIMUM = 2;

    /** EPC match confidences trusted enough to count as evidence. */
    private const TRUSTED_CONFIDENCES = [
        EpcMatchConfidence::Medium->value,
        EpcMatchConfidence::High->value,
    ];

    /**
     * The confidence, band and human-readable signals for one candidate.
     *
     * Expects `title` to be loaded; without it nothing can be evidenced and
     * the candidate scores zero.
     *
     * @return array{confidence: int, level: string, signals: array<int, string>}
     */
    public function forCandidate(Candidate $candidate): array
    {
        $title = $candidate->title;
        $weights = $this->weights();
        $confidence = 0;
        $signals = [];

        if ($title === null) {
            return ['confidence' => 0, 'level' => $this->level(0), 'signals' => []];
        }

        $certificates = (int) $title->epc_certificate_count;

        if ($this->hasTrustedEpc($title) && $certificates >= self::MULTI_CERTIFICATE_MINIMUM) {
            $confidence += $weights['multiple_epc_certificates'];
            $signals[] = $certificates.' matched EPCs';
        }

        if ($this->hasFlatPropertyType($title)) {
            $confidence += $weights['epc_flat_property_type'];
            $signals[] = 'EPC type '.strtolower((string) $title->epc_property_type);
        }

        $units = $this->unitsFor($candidate);

        if ($units !== null && $units >= $this->minimumUnits()) {
            $confidence += $weights['meets_minimum_units'];
            $signals[] = $units.' units';
        }

        if ($this->hasFlatAddressKeyword($title)) {
            $confidence += $weights['flat_address_keyword'];
            $signals[] = 'address names flats';
        }

        return [
            'confidence' => $confidence,
            'level' => $this->level($confidence),
            'signals' => $signals,
        ];
    }

    /**
     * The unit count to trust for this candidate.
     *
     * A count derived from matched EPC certificates is a survey of the
     * building and beats everything else. Otherwise the candidate's own
     * figure wins, because that is the one the API lets a user override, and
     * it falls back to the address-derived estimate on the title.
     */
    public function unitsFor(Candidate $candidate): ?int
    {
        $title = $candidate->title;

        if ($title?->unit_count_source === 'epc' && $title->estimated_unit_count !== null) {
            return (int) $title->estimated_unit_count;
        }

        return $candidate->estimated_units ?? $title?->estimated_unit_count;
    }

    /** Where the figure from unitsFor() came from. */
    public function unitSourceFor(Candidate $candidate): ?string
    {
        if ($this->unitsFor($candidate) === null) {
            return null;
        }

        return $candidate->title?->unit_count_source === 'epc' ? 'epc' : 'estimate';
    }

    // ------------------------------------------------------------------ SQL

    /**
     * The unitsFor() rule as SQL, for filtering and sorting in the database.
     *
     * Assumes `candidates` is joined to `titles`. Carries no bindings, so it
     * can be dropped into a fragment anywhere without disturbing the order of
     * the surrounding ones.
     */
    public function unitsSql(): string
    {
        return "(case when titles.unit_count_source = 'epc' and titles.estimated_unit_count is not null"
            .' then titles.estimated_unit_count'
            .' else coalesce(candidates.estimated_units, titles.estimated_unit_count) end)';
    }

    /**
     * The forCandidate() confidence as SQL. Same weights, same thresholds.
     *
     * Returns the fragment and its bindings rather than an Expression,
     * because callers have to place the bindings themselves — the position
     * differs between a select, a where and an order by.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function confidenceExpression(): array
    {
        $weights = $this->weights();
        $bindings = [];

        $sql = sprintf(
            '(case when titles.epc_match_confidence in (%s) and titles.epc_certificate_count >= %d then %d else 0 end)',
            $this->placeholders(self::TRUSTED_CONFIDENCES, $bindings),
            self::MULTI_CERTIFICATE_MINIMUM,
            $weights['multiple_epc_certificates'],
        );

        $sql .= sprintf(
            ' + (case when lower(titles.epc_property_type) in (%s) then %d else 0 end)',
            $this->placeholders($this->flatPropertyTypes(), $bindings),
            $weights['epc_flat_property_type'],
        );

        $sql .= sprintf(
            ' + (case when %s >= %d then %d else 0 end)',
            $this->unitsSql(),
            $this->minimumUnits(),
            $weights['meets_minimum_units'],
        );

        $keywords = $this->flatAddressKeywordExpression($bindings);

        $sql .= sprintf(
            ' + (case when %s then %d else 0 end)',
            // No keywords configured: a clause that is always false, so the
            // component simply never fires.
            $keywords === '' ? '1 = 0' : $keywords,
            $weights['flat_address_keyword'],
        );

        // Deliberately not clamped to 100. Clamping portably would mean
        // repeating the whole fragment, and the only way past 100 is weights
        // configured to sum above it — in which case PHP and SQL agreeing
        // matters more than the ceiling.
        return ['('.$sql.')', $bindings];
    }

    /**
     * The score at which a candidate enters the given band, for turning
     * `min_mufb=high` into a number.
     */
    public function thresholdFor(string $level): ?int
    {
        $levels = (array) config('blockradar.mufb.levels', []);

        return isset($levels[$level]) ? (int) $levels[$level] : null;
    }

    public function level(int $confidence): string
    {
        $high = (int) config('blockradar.mufb.levels.high', 65);
        $medium = (int) config('blockradar.mufb.levels.medium', 35);

        if ($confidence >= $high) {
            return 'high';
        }

        return $confidence >= $medium ? 'medium' : 'low';
    }

    // -------------------------------------------------------------- internals

    private function hasTrustedEpc(Title $title): bool
    {
        return $title->epc_match_confidence !== null
            && in_array($title->epc_match_confidence->value, self::TRUSTED_CONFIDENCES, true);
    }

    private function hasFlatPropertyType(Title $title): bool
    {
        if ($title->epc_property_type === null) {
            return false;
        }

        return in_array(strtolower($title->epc_property_type), $this->flatPropertyTypes(), true);
    }

    private function hasFlatAddressKeyword(Title $title): bool
    {
        $address = strtolower((string) $title->property_address);

        foreach ($this->flatAddressKeywords() as $keyword) {
            if ($keyword !== '' && str_contains($address, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `lower(address) like '%flat%' or ...`, with the keywords bound.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function flatAddressKeywordExpression(array &$bindings): string
    {
        $clauses = [];

        foreach ($this->flatAddressKeywords() as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $clauses[] = 'lower(titles.property_address) like ?';
            $bindings[] = '%'.$keyword.'%';
        }

        return implode(' or ', $clauses);
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, mixed>  $bindings
     */
    private function placeholders(array $values, array &$bindings): string
    {
        foreach ($values as $value) {
            $bindings[] = $value;
        }

        return implode(', ', array_fill(0, count($values), '?')) ?: 'null';
    }

    /** @return array<string, int> */
    private function weights(): array
    {
        $weights = (array) config('blockradar.mufb.weights', []);

        return [
            'multiple_epc_certificates' => (int) ($weights['multiple_epc_certificates'] ?? 0),
            'epc_flat_property_type' => (int) ($weights['epc_flat_property_type'] ?? 0),
            'meets_minimum_units' => (int) ($weights['meets_minimum_units'] ?? 0),
            'flat_address_keyword' => (int) ($weights['flat_address_keyword'] ?? 0),
        ];
    }

    /** @return array<int, string> */
    private function flatPropertyTypes(): array
    {
        return array_values(array_filter(array_map(
            fn ($type) => strtolower(trim((string) $type)),
            (array) config('blockradar.mufb.flat_property_types', [])
        )));
    }

    /** @return array<int, string> */
    private function flatAddressKeywords(): array
    {
        return array_values(array_filter(array_map(
            fn ($keyword) => strtolower(trim((string) $keyword)),
            (array) config('blockradar.mufb.flat_address_keywords', [])
        )));
    }

    private function minimumUnits(): int
    {
        return (int) config('blockradar.scoring.minimum_units', 4);
    }
}
