<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_number' => $this->company_number,
            'name' => $this->name,
            'status' => $this->status,
            'type' => $this->type,
            'jurisdiction' => $this->jurisdiction,
            'incorporated_on' => $this->incorporated_on?->toDateString(),
            'dissolved_on' => $this->dissolved_on?->toDateString(),
            'sic_codes' => $this->sic_codes ?? [],
            'registered_office_address' => $this->registered_office_address,
            'registered_office_postcode' => $this->registered_office_postcode,
            'officer_count' => $this->officer_count,

            // Distress and motivation signals, the same ones the scorer reads.
            'accounts_last_made_up_to' => $this->accounts_last_made_up_to?->toDateString(),
            'accounts_next_due' => $this->accounts_next_due?->toDateString(),
            'accounts_overdue' => $this->accounts_overdue,
            'confirmation_statement_overdue' => $this->confirmation_statement_overdue,
            'confirmation_statement_next_due' => $this->confirmation_statement_next_due?->toDateString(),
            'has_charges' => $this->has_charges,
            'charges_count' => $this->charges_count,
            'has_insolvency_history' => $this->has_insolvency_history,
            'is_distressed' => $this->isDistressed(),
            'is_dissolved' => $this->isDissolved(),

            // Every distress signal in one list, matching what
            // `company_distressed=true` selects on. Empty for a company that
            // is either clean or not yet enriched — `is_enriched` separates
            // those two.
            'distress_signals' => $this->distressSignals(),
            'has_distress_signals' => $this->hasDistressSignals(),

            'is_enriched' => $this->isEnriched(),
            'enriched_at' => $this->enriched_at?->toIso8601String(),
            'enrichment_status' => $this->enrichment_status?->value,
            'titles_count' => $this->whenCounted('titles'),
        ];
    }
}
