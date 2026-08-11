<?php

namespace App\Http\Resources;

use App\Models\EpcCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EpcCertificate */
class EpcCertificateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'address' => $this->address,
            'postcode' => $this->postcode,
            'uprn' => $this->uprn,
            'current_energy_rating' => $this->current_energy_rating,
            'current_energy_efficiency' => $this->current_energy_efficiency,
            'potential_energy_rating' => $this->potential_energy_rating,
            'property_type' => $this->property_type,
            'built_form' => $this->built_form,
            'total_floor_area' => $this->total_floor_area !== null ? (float) $this->total_floor_area : null,
            'number_habitable_rooms' => $this->number_habitable_rooms,
            'floor_level' => $this->floor_level,
            'lodgement_date' => $this->lodgement_date?->toDateString(),

            // How this certificate came to be linked to the title.
            'match' => $this->when($this->pivot !== null, fn () => [
                'method' => $this->pivot->method,
                'confidence' => $this->pivot->confidence,
                'similarity' => $this->pivot->similarity !== null ? (float) $this->pivot->similarity : null,
                'is_primary' => (bool) $this->pivot->is_primary,
            ]),
        ];
    }
}
