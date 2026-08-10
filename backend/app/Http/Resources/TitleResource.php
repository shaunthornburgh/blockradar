<?php

namespace App\Http\Resources;

use App\Models\Title;
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
            'multiple_address_indicator' => $this->multiple_address_indicator,
            'additional_proprietor_indicator' => $this->additional_proprietor_indicator,
            'proprietor_name' => $this->proprietor_name,
            'proprietorship_category' => $this->proprietorship_category,
            'price_paid' => $this->price_paid,
            'date_proprietor_added' => $this->date_proprietor_added?->toDateString(),
            'estimated_unit_count' => $this->estimated_unit_count,
            'company' => CompanyResource::make($this->whenLoaded('company')),
        ];
    }
}
