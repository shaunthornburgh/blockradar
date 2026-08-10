<?php

namespace App\Services\Epc;

use App\Enums\EpcMatchConfidence;
use App\Models\EpcCertificate;
use App\Models\Title;
use App\Models\TitleEpcMatch;
use Illuminate\Support\Facades\DB;

/**
 * Matches one title against the loaded certificates and writes the result:
 * the individual links in `title_epc_matches`, and the aggregates the scorer
 * and dashboard read from `titles`.
 */
class TitleEpcEnricher
{
    public function __construct(private readonly EpcMatcher $matcher) {}

    /**
     * Returns the match set so callers can report on quality. Titles below the
     * confidence floor are stamped as enriched-but-unmatched, which stops them
     * being retried every run while leaving the evidence visible.
     */
    public function enrich(Title $title, bool $force = false, ?EpcMatchConfidence $minimum = null): EpcMatchSet
    {
        $minimum ??= $this->matcher->minimumConfidence();

        $set = $this->matcher->match($title);

        DB::transaction(function () use ($title, $set, $minimum) {
            // Matches are rebuilt from scratch: a newer EPC extract can add
            // flats to a building or move a certificate between them, and a
            // stale link would silently inflate the unit count.
            $title->epcMatches()->delete();

            if ($set->isEmpty() || ! $set->meets($minimum)) {
                $this->markUnmatched($title, $set);

                return;
            }

            $this->writeMatches($title, $set);
            $this->writeAggregates($title, $set);
        });

        unset($force);

        return $set;
    }

    public function needsEnrichment(Title $title): bool
    {
        if ($title->epc_enriched_at === null) {
            return true;
        }

        $staleDays = (int) config('blockradar.epc.match.stale_after_days', 180);

        return $title->epc_enriched_at->lt(now()->subDays($staleDays));
    }

    private function writeMatches(Title $title, EpcMatchSet $set): void
    {
        $primary = $set->primary();
        $now = now();

        $rows = $set->certificates->map(fn (EpcCertificate $certificate) => [
            'title_id' => $title->id,
            'epc_certificate_id' => $certificate->id,
            'method' => $set->method?->value,
            'confidence' => $set->confidence?->value,
            'similarity' => $set->similarity,
            'is_primary' => $primary !== null && $certificate->id === $primary->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        TitleEpcMatch::insert($rows);
    }

    private function writeAggregates(Title $title, EpcMatchSet $set): void
    {
        $certificates = $set->certificates;
        $primary = $set->primary();

        // The block is only as good as its poorest flat, and that is where the
        // refurbishment upside sits.
        $worstRating = $certificates->reduce(
            fn (?string $carry, EpcCertificate $c) => EpcCertificate::worstRating($carry, $c->current_energy_rating),
            null
        );

        $efficiencies = $certificates
            ->pluck('current_energy_efficiency')
            ->filter(fn ($value) => $value !== null && $value > 0);

        $floorAreas = $certificates
            ->pluck('total_floor_area')
            ->filter(fn ($value) => $value !== null && (float) $value > 0)
            ->map(fn ($value) => (float) $value);

        $rooms = $certificates
            ->pluck('number_habitable_rooms')
            ->filter(fn ($value) => $value !== null && $value > 0);

        $attributes = [
            'epc_enriched_at' => now(),
            'epc_match_confidence' => $set->confidence?->value,
            'epc_match_method' => $set->method?->value,
            'epc_certificate_count' => $certificates->count(),
            'epc_primary_certificate_id' => $primary?->id,
            'epc_current_rating' => $worstRating,
            'epc_average_energy_efficiency' => $efficiencies->isNotEmpty()
                ? (int) round($efficiencies->avg())
                : null,
            // Summed across the building, not per dwelling.
            'epc_total_floor_area' => $floorAreas->isNotEmpty() ? round($floorAreas->sum(), 2) : null,
            'epc_habitable_rooms' => $rooms->isNotEmpty() ? (int) $rooms->sum() : null,
            'epc_property_type' => $primary?->property_type,
            'epc_built_form' => $primary?->built_form,
            'epc_construction_age_band' => $primary?->construction_age_band,
            'epc_main_heating' => $primary?->main_heat_description,
            // For a block this is one flat's UPRN, not the building's.
            'epc_uprn' => $primary?->uprn,
            'epc_latest_lodgement_date' => $certificates
                ->pluck('lodgement_date')
                ->filter()
                ->max()?->toDateString(),
        ];

        $attributes += $this->unitCountAttributes($title, $set);

        $title->forceFill($attributes)->save();
    }

    /**
     * A count of certificates in a building is a far better unit count than
     * anything parsed out of an address, so it takes over when the match is
     * trustworthy.
     *
     * @return array<string, mixed>
     */
    private function unitCountAttributes(Title $title, EpcMatchSet $set): array
    {
        $count = $set->count();

        // One certificate means one dwelling was surveyed, not that the
        // building has one flat — leave the address estimate alone.
        if ($count < 2 || ! $set->meets(EpcMatchConfidence::Medium)) {
            return [];
        }

        if ($title->estimated_unit_count === $count && $title->unit_count_source === 'epc') {
            return [];
        }

        return [
            'estimated_unit_count' => min($count, 65535),
            'unit_count_source' => 'epc',
        ];
    }

    private function markUnmatched(Title $title, EpcMatchSet $set): void
    {
        $title->forceFill([
            'epc_enriched_at' => now(),
            // A match that exists but is too weak to trust is recorded as no
            // match, so nothing downstream can accidentally rely on it.
            'epc_match_confidence' => null,
            'epc_match_method' => null,
            'epc_certificate_count' => 0,
            'epc_primary_certificate_id' => null,
            'epc_current_rating' => null,
            'epc_average_energy_efficiency' => null,
            'epc_total_floor_area' => null,
            'epc_habitable_rooms' => null,
            'epc_property_type' => null,
            'epc_built_form' => null,
            'epc_construction_age_band' => null,
            'epc_main_heating' => null,
            'epc_uprn' => null,
            'epc_latest_lodgement_date' => null,
        ])->save();

        unset($set);
    }
}
