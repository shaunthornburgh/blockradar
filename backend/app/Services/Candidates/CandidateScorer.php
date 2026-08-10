<?php

namespace App\Services\Candidates;

use App\Models\AreaMetric;
use App\Models\Company;
use App\Models\Title;
use Carbon\CarbonImmutable;

/**
 * Turns a title into a 0-100 score using the weights in config/blockradar.php.
 *
 * Only components whose inputs are actually present are counted. The score is
 * the weighted average over *available* weight, not total weight, so a title
 * is never penalised for data Block Radar has not fetched yet — the two
 * Companies House components stay unavailable until that company has been
 * enriched. `weight_available` in the breakdown records how much of the model
 * was live when the score was taken.
 *
 * Callers should eager-load `title.company`; the score is identical either
 * way, but a lazy load costs a query per title.
 */
class CandidateScorer
{
    public function score(Title $title, ?AreaMetric $area = null): ScoreResult
    {
        $weights = (array) config('blockradar.scoring.weights', []);
        $company = $title->company;

        $components = [
            'area_yield' => $this->areaYield($area),
            'estimated_units' => $this->estimatedUnits($title),
            'title_split_upside' => $this->splitUpside($title, $area),
            'ownership_duration' => $this->ownershipDuration($title),
            'epc_refurb_potential' => $this->epcRefurbPotential($title),
            'filing_distress' => $this->filingDistress($company),
            'charges_pressure' => $this->chargesPressure($company),
        ];

        $weightTotal = 0;
        $weightAvailable = 0;
        $earned = 0.0;
        $detail = [];

        foreach ($components as $key => [$value, $note, $signals]) {
            $weight = (int) ($weights[$key] ?? 0);
            $weightTotal += $weight;
            $available = $value !== null;
            $points = 0.0;

            if ($available) {
                $value = max(0.0, min(1.0, $value));
                $points = $value * $weight;
                $weightAvailable += $weight;
                $earned += $points;
            }

            $detail[$key] = [
                'value' => $available ? round($value, 3) : null,
                'weight' => $weight,
                'points' => round($points, 2),
                'available' => $available,
                'note' => $note,
                'signals' => $signals,
            ];
        }

        $score = $weightAvailable > 0
            ? (int) round(($earned / $weightAvailable) * 100)
            : 0;

        return new ScoreResult(
            score: max(0, min(100, $score)),
            components: $detail,
            weightAvailable: $weightAvailable,
            weightTotal: $weightTotal,
        );
    }

    /**
     * Gross yield in the postcode district, relative to the configured floor.
     * At the floor this scores 0; at double the floor it scores 1.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function areaYield(?AreaMetric $area): array
    {
        $yield = $area?->gross_yield !== null ? (float) $area->gross_yield : null;

        if ($yield === null) {
            return [null, 'No area metrics for this postcode district', []];
        }

        $floor = (float) config('blockradar.scoring.minimum_gross_yield', 6.0);

        if ($floor <= 0.0) {
            return [null, 'minimum_gross_yield is not configured', []];
        }

        return [
            ($yield - $floor) / $floor,
            sprintf('Area gross yield %.2f%% against a %.2f%% floor', $yield, $floor),
            [],
        ];
    }

    /**
     * More units means more titles to sell on and better economics per legal
     * fee. Scales from the configured minimum up to 20 units.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function estimatedUnits(Title $title): array
    {
        $units = $title->estimated_unit_count;

        if ($units === null) {
            return [null, 'Unit count could not be estimated from the address', []];
        }

        $minimum = (int) config('blockradar.scoring.minimum_units', 4);
        $ceiling = max($minimum + 1, 20);

        return [
            ($units - $minimum) / ($ceiling - $minimum),
            sprintf('%d estimated units', $units),
            [],
        ];
    }

    /**
     * A low price per unit relative to local values is the split upside.
     *
     * Compared against the area median where it is known, and against a fixed
     * band otherwise. This is a proxy: real GDV modelling is a later step.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function splitUpside(Title $title, ?AreaMetric $area): array
    {
        $price = $title->price_paid;

        if ($price === null || $price <= 0) {
            return [null, 'No price paid recorded on the title', []];
        }

        // Floor area is a direct measurement of the asset, so it beats any
        // count of units when EPC data is available.
        $floorArea = $title->hasUsableEpc() && $title->epc_total_floor_area !== null
            ? (float) $title->epc_total_floor_area
            : null;

        if ($floorArea !== null && $floorArea > 0) {
            $perSquareMetre = $price / $floorArea;

            // Pence per m². £1,200 or below is strong, £4,000 leaves nothing.
            $best = 1_200_00;
            $worst = 4_000_00;

            return [
                ($worst - $perSquareMetre) / ($worst - $best),
                sprintf(
                    '£%s per m² across %s m² of EPC floor area',
                    number_format($perSquareMetre / 100),
                    number_format($floorArea)
                ),
                ['floor area from EPC'],
            ];
        }

        $units = $title->estimated_unit_count;

        if ($units === null || $units < 1) {
            return [null, 'Needs an EPC floor area or an estimated unit count', []];
        }

        $perUnit = (int) round($price / $units);

        if ($area?->median_price !== null && (int) $area->median_price > 0) {
            $ratio = $perUnit / (int) $area->median_price;

            // Half the local median or less is excellent; at or above the
            // median there is no split margin left.
            return [
                (1.0 - $ratio) / 0.5,
                sprintf(
                    '£%s per unit against an area median of £%s',
                    number_format($perUnit / 100),
                    number_format((int) $area->median_price / 100)
                ),
                [],
            ];
        }

        // Fallback band, in pence: £60k/unit or less scores 1, £200k scores 0.
        $best = 60_000_00;
        $worst = 200_000_00;

        return [
            ($worst - $perUnit) / ($worst - $best),
            sprintf('£%s per unit (no area median available)', number_format($perUnit / 100)),
            [],
        ];
    }

    /**
     * Long-held stock is likelier to be tired, under-rented and sellable.
     * Nothing at 0 years, full marks at 15.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function ownershipDuration(Title $title): array
    {
        $since = $title->date_proprietor_added;

        if ($since === null) {
            return [null, 'No proprietor-added date in the CCOD row', []];
        }

        $years = CarbonImmutable::parse($since)->diffInYears(CarbonImmutable::now());

        if ($years < 0) {
            return [null, 'Proprietor-added date is in the future', []];
        }

        return [
            $years / 15,
            sprintf('Held for %d years', (int) $years),
            [],
        ];
    }

    /**
     * A poor EPC is upside, not a defect.
     *
     * Cheap to fix, it lifts rent and value; and under the Minimum Energy
     * Efficiency Standard anything below E cannot be let on a new tenancy,
     * which is a direct reason for a landlord to sell rather than spend.
     *
     * Scored from the average SAP efficiency across the building: 80 (a solid
     * C) scores nothing, 30 (deep F territory) scores full marks. Falls back
     * to the worst band when numeric efficiency is missing.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function epcRefurbPotential(Title $title): array
    {
        if (! $title->hasUsableEpc()) {
            return [null, 'No EPC matched to this title at sufficient confidence', []];
        }

        $efficiency = $title->epc_average_energy_efficiency;
        $rating = $title->epc_current_rating;

        if ($efficiency === null && $rating === null) {
            return [null, 'Matched EPCs carry no rating', []];
        }

        $signals = [];

        if ($efficiency === null) {
            // Mid-point of each SAP band, so a band alone still scores.
            $efficiency = match (strtoupper($rating)) {
                'A' => 96, 'B' => 86, 'C' => 74, 'D' => 61,
                'E' => 46, 'F' => 29, 'G' => 10,
                default => null,
            };

            if ($efficiency === null) {
                return [null, 'Unrecognised EPC band: '.$rating, []];
            }

            $signals[] = 'estimated from band '.strtoupper($rating);
        }

        if ($rating !== null && in_array(strtoupper($rating), ['F', 'G'], true)) {
            $signals[] = 'below MEES minimum — cannot be re-let without works';
        }

        if ($title->epc_certificate_count >= 2) {
            $signals[] = $title->epc_certificate_count.' certificates confirm multiple dwellings';
        }

        return [
            (80 - $efficiency) / 50,
            sprintf(
                'Average SAP %d, worst band %s across %d certificate%s',
                $efficiency,
                $rating ?? '?',
                $title->epc_certificate_count,
                $title->epc_certificate_count === 1 ? '' : 's'
            ),
            $signals,
        ];
    }

    /**
     * Companies House filing behaviour as a proxy for owner motivation.
     *
     * An active, up-to-date company scores zero — that is the neutral baseline,
     * not a penalty. Points accumulate for the distress signals, capped at the
     * 100 used as the denominator.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function filingDistress(?Company $company): array
    {
        if ($company === null) {
            return [null, 'Title has no matched company', []];
        }

        if (! $company->isEnriched()) {
            return [null, 'Company not yet enriched from Companies House', []];
        }

        $points = 0;
        $signals = [];

        if ($company->accounts_overdue === true) {
            $points += 30;
            $signals[] = 'accounts overdue';
        }

        if ($company->confirmation_statement_overdue === true) {
            $points += 25;
            $signals[] = 'confirmation statement overdue';
        }

        if ($company->isDistressed()) {
            $points += 30;
            $signals[] = 'in '.str_replace('-', ' ', (string) $company->status);
        } elseif ($company->has_insolvency_history === true) {
            $points += 10;
            $signals[] = 'past insolvency history';
        }

        if ($company->isDissolved()) {
            // Motivation is high but so is the risk: a dissolved company
            // cannot sell, and the asset may have passed to the Crown as bona
            // vacantia. Deliberately capped well below the live signals.
            $points += 10;
            $signals[] = str_replace('-', ' ', (string) $company->status).' (high risk: possible bona vacantia)';
        }

        $lastAccounts = $company->accounts_last_made_up_to;

        if ($lastAccounts !== null && $lastAccounts->lt(now()->subMonths(18))) {
            $points += 15;
            $signals[] = 'last accounts made up to '.$lastAccounts->format('M Y');

            if ($company->incorporated_on?->lt(now()->subYears(15))) {
                $points += 5;
                $signals[] = 'long-established company with stale filings';
            }
        }

        if ($signals === []) {
            $signals[] = 'active and filings up to date';
        }

        return [
            $points / 100,
            implode('; ', $signals),
            $signals,
        ];
    }

    /**
     * Registered charges read as financial pressure on the owner.
     *
     * The same charges mean a lender has to consent before a title can be
     * split, which is friction — the small weight reflects that tension.
     *
     * @return array{0: float|null, 1: string, 2: array<int, string>}
     */
    private function chargesPressure(?Company $company): array
    {
        if ($company === null) {
            return [null, 'Title has no matched company', []];
        }

        if (! $company->isEnriched()) {
            return [null, 'Company not yet enriched from Companies House', []];
        }

        if ($company->has_charges !== true) {
            return [0.0, 'No registered charges', []];
        }

        $count = $company->charges_count;

        return [
            1.0,
            $count !== null && $count > 0
                ? sprintf('%d registered charge%s', $count, $count === 1 ? '' : 's')
                : 'Has registered charges',
            ['secured borrowing'],
        ];
    }
}
