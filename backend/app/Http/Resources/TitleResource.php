<?php

namespace App\Http\Resources;

use App\Models\Title;
use App\Services\Candidates\CandidateFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Title */
class TitleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title_number' => $this->title_number,
            'tenure' => $this->tenure->value,
            'tenure_label' => $this->tenure->label(),
            'property_address' => $this->property_address,
            'postcode' => $this->postcode,
            'postcode_district' => $this->postcodeDistrict(),
            'district' => $this->district,
            'county' => $this->county,
            'region' => $this->region,
            'uprn' => $this->uprn,
            'multiple_address_indicator' => $this->multiple_address_indicator,
            'additional_proprietor_indicator' => $this->additional_proprietor_indicator,
            'proprietor_name' => $this->proprietor_name,
            'proprietorship_category' => $this->proprietorship_category,
            'price_paid' => $this->price_paid,
            'date_proprietor_added' => $this->date_proprietor_added?->toDateString(),
            'estimated_unit_count' => $this->estimated_unit_count,
            'unit_count_source' => $this->unit_count_source,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            'epc' => [
                'enriched_at' => $this->epc_enriched_at?->toIso8601String(),
                'match_confidence' => $this->epc_match_confidence?->value,
                'match_method' => $this->epc_match_method?->value,
                'match_method_label' => $this->epc_match_method?->label(),
                'is_usable' => $this->hasUsableEpc(),
                'certificate_count' => (int) $this->epc_certificate_count,
                'current_rating' => $this->epc_current_rating,
                'average_energy_efficiency' => $this->epc_average_energy_efficiency,
                'total_floor_area' => $this->epc_total_floor_area !== null
                    ? (float) $this->epc_total_floor_area
                    : null,
                'habitable_rooms' => $this->epc_habitable_rooms,
                'property_type' => $this->epc_property_type,
                'built_form' => $this->epc_built_form,
                'construction_age_band' => $this->epc_construction_age_band,
                'main_heating' => $this->epc_main_heating,
                'uprn' => $this->epc_uprn,
                'latest_lodgement_date' => $this->epc_latest_lodgement_date?->toDateString(),
            ],

            // Set by withExists('candidate') on the list endpoint, so a row can
            // show that it is already in the pipeline without loading it.
            'is_candidate' => $this->when(
                $this->resource->candidate_exists !== null,
                fn () => (bool) $this->resource->candidate_exists
            ),

            'company' => CompanyResource::make($this->whenLoaded('company')),
            'epc_certificates' => EpcCertificateResource::collection($this->whenLoaded('epcCertificates')),

            // Only on the detail endpoint, which is the one that loads the
            // relation. Keyed off relationLoaded rather than whenLoaded so
            // that "no candidate" is an explicit null the page can act on,
            // rather than an absent key indistinguishable from "not asked".
            $this->mergeWhen($this->relationLoaded('candidate'), fn () => [
                'candidate' => $this->candidate
                    ? CandidateSummaryResource::make($this->candidate)
                    : null,
                'pipeline' => $this->pipelineStatus(),
            ]),
        ];
    }

    /**
     * Why this title is, or is not, in the MUFB pipeline.
     *
     * `qualifies_now` re-runs the current filter, which is not necessarily the
     * one that ran at import — the config may have moved since. The two
     * together are what let the page explain a mismatch instead of guessing at
     * one.
     *
     * @return array<string, mixed>
     */
    private function pipelineStatus(): array
    {
        $reason = app(CandidateFilter::class)->rejectionReason($this->resource);

        return [
            'is_candidate' => $this->candidate !== null,
            'qualifies_now' => $reason === null,
            'reason' => $reason,
            'reason_label' => $reason === null ? null : CandidateFilter::reasonLabel($reason),
        ];
    }
}
