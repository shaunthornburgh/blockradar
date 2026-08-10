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
            'incorporated_on' => $this->incorporated_on?->toDateString(),
            'sic_codes' => $this->sic_codes ?? [],
            'registered_office_postcode' => $this->registered_office_postcode,
            'officer_count' => $this->officer_count,
            'has_charges' => $this->has_charges,
            'charges_count' => $this->charges_count,
            'enriched_at' => $this->enriched_at?->toIso8601String(),
        ];
    }
}
