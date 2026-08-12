<?php

namespace App\Http\Requests;

use App\Enums\PipelineStage;
use App\Http\Controllers\Api\CandidateController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for the candidates list.
 *
 * The booleans — archived, has_epc, has_charges, company_distressed,
 * include_unknown_units — are deliberately absent from the rules and read
 * with $request->boolean() in the controller instead. Laravel's `boolean`
 * rule rejects the "true"/"false" strings a JavaScript client puts in a query
 * string, and this list is driven entirely from the URL.
 */
class IndexCandidateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage' => ['sometimes', Rule::enum(PipelineStage::class)],

            'min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            // gte only bites when there is actually a floor to compare
            // against; on its own, `gte:min_score` fails an absent min_score.
            'max_score' => [
                'sometimes', 'integer', 'min:0', 'max:100',
                Rule::when($this->has('min_score'), ['gte:min_score']),
            ],

            'region' => ['sometimes', 'string', 'max:120'],
            // Postcode areas are one or two letters: M, LS, SW.
            'postcode_area' => ['sometimes', 'string', 'regex:/^[A-Za-z]{1,2}$/'],

            'search' => ['sometimes', 'string', 'max:120'],

            'min_units' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'min_epc_certificates' => ['sometimes', 'integer', 'min:0', 'max:2000'],

            // A number, or one of the band names from config.
            'min_mufb' => ['sometimes', 'string', 'regex:/^(high|medium|low|\d{1,3})$/'],

            'sort' => ['sometimes', Rule::in(array_keys(CandidateController::SORTS))],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'postcode_area.regex' => 'The postcode area must be the letters at the front of the outward code, e.g. M or LS.',
            'min_mufb.regex' => 'The minimum MUFB confidence must be 0-100, or one of: high, medium, low.',
        ];
    }
}
