<?php

namespace App\Http\Requests;

use App\Enums\PipelineStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage' => ['sometimes', Rule::enum(PipelineStage::class)],
            'assigned_to_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'next_action_at' => ['sometimes', 'nullable', 'date'],
            'estimated_units' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            'estimated_gdv' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'estimated_uplift' => ['sometimes', 'nullable', 'integer'],
            'gross_yield' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }
}
