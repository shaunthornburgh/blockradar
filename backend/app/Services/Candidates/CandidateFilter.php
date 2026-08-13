<?php

namespace App\Services\Candidates;

use App\Enums\Tenure;
use App\Models\Title;

/**
 * Decides whether a title belongs in the deal pipeline.
 *
 * The core filter — Freehold plus the multiple address indicator — is fixed,
 * because it is the definition of the population Block Radar exists to find.
 * Everything after it is configuration in config/blockradar.php.
 */
class CandidateFilter
{
    /**
     * What each rejection reason means, for the title detail page.
     *
     * Kept beside the checks that produce them so the two cannot drift. Every
     * one is a statement about data we actually hold — nothing here guesses at
     * why a building is or is not a block.
     */
    public const REASON_LABELS = [
        'not_freehold' => 'The title is not freehold, so its flats cannot be sold off on new leases.',
        'single_address' => 'CCOD does not flag this title as covering multiple addresses.',
        'excluded_address' => 'The address contains a keyword that is almost never a splittable residential block — a car park, garage, land parcel or commercial unit.',
        'outside_target_region' => 'The title is outside the regions currently being sourced.',
        'outside_target_postcode_area' => 'The title is outside the postcode areas currently being sourced.',
        'below_minimum_units' => 'The estimated unit count is below the minimum worth pursuing.',
        'epc_single_dwelling' => 'A trustworthy EPC match describes one whole house rather than flats, so the multiple-address flag is something else — an outbuilding or a re-numbered plot.',
    ];

    /**
     * Returns null when the title qualifies, or a short machine-readable
     * reason when it does not. Reasons are aggregated into the import summary
     * so it is obvious why a run produced few candidates.
     */
    public function rejectionReason(Title $title): ?string
    {
        if ($title->tenure !== Tenure::Freehold) {
            return 'not_freehold';
        }

        if (! $title->multiple_address_indicator) {
            return 'single_address';
        }

        if ($this->isExcludedAddress($title->property_address)) {
            return 'excluded_address';
        }

        if (! $this->inTargetRegion($title)) {
            return 'outside_target_region';
        }

        if (! $this->inTargetPostcodeArea($title)) {
            return 'outside_target_postcode_area';
        }

        if (! $this->meetsMinimumUnits($title)) {
            return 'below_minimum_units';
        }

        if ($this->isEpcSingleDwelling($title)) {
            return 'epc_single_dwelling';
        }

        return null;
    }

    public function passes(Title $title): bool
    {
        return $this->rejectionReason($title) === null;
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason]
            // A reason added to rejectionReason() without a label here. Better
            // to show the raw key than to say nothing.
            ?? 'Filtered out of the pipeline: '.str_replace('_', ' ', $reason).'.';
    }

    private function isExcludedAddress(?string $address): bool
    {
        if ($address === null) {
            return false;
        }

        $haystack = strtolower($address);

        /** @var array<int, string> $keywords */
        $keywords = config('blockradar.candidate_filters.exclude_address_keywords', []);

        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim((string) $keyword));

            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function inTargetRegion(Title $title): bool
    {
        /** @var array<int, string> $regions */
        $regions = config('blockradar.candidate_filters.regions', []);

        if ($regions === []) {
            return true;
        }

        if ($title->region === null) {
            return false;
        }

        foreach ($regions as $region) {
            if (strcasecmp(trim((string) $region), trim($title->region)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function inTargetPostcodeArea(Title $title): bool
    {
        /** @var array<int, string> $areas */
        $areas = config('blockradar.candidate_filters.postcode_areas', []);

        if ($areas === []) {
            return true;
        }

        $district = $title->postcodeDistrict();

        if ($district === null) {
            return false;
        }

        // "M14" => "M", "LS6" => "LS".
        $area = strtoupper((string) preg_replace('/[0-9].*$/', '', $district));

        return $area !== '' && in_array($area, $areas, true);
    }

    /**
     * EPC evidence that the building is one house, despite CCOD flagging
     * multiple addresses — which it also does for a house with an outbuilding
     * or a re-numbered plot.
     *
     * Only fires on a trustworthy match, and only when the single certificate
     * describes a whole house rather than a flat.
     */
    private function isEpcSingleDwelling(Title $title): bool
    {
        if (! config('blockradar.candidate_filters.exclude_epc_single_dwellings', true)) {
            return false;
        }

        if (! $title->hasUsableEpc() || $title->epc_certificate_count !== 1) {
            return false;
        }

        return in_array($title->epc_property_type, ['House', 'Bungalow', 'Park home'], true);
    }

    private function meetsMinimumUnits(Title $title): bool
    {
        $minimum = config('blockradar.candidate_filters.minimum_estimated_units')
            ?? config('blockradar.scoring.minimum_units', 4);

        $minimum = (int) $minimum;

        if ($minimum <= 0) {
            return true;
        }

        if ($title->estimated_unit_count === null) {
            return (bool) config('blockradar.candidate_filters.allow_unknown_unit_count', true);
        }

        return $title->estimated_unit_count >= $minimum;
    }
}
