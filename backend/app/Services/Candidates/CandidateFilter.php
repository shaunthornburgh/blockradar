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
