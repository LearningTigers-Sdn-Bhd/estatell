<?php

namespace App\Http\Requests\Folio;

use App\Actions\Folio\SplitLine;
use App\Data\Folio\SplitPartData;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Split Dialog: two or more parts, each a folio of the reservation and an amount.
 */
class SplitLineRequest extends LineManualActionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'parts' => ['required', 'array', 'min:2', 'max:'.SplitLine::MAX_PARTS],
            'parts.*.folio_id' => ['required', 'integer'],
            'parts.*.amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'parts.min' => __('Split the line into at least 2 parts.'),
            'parts.*.amount.required' => __('Enter an amount.'),
            'parts.*.amount.regex' => __('Enter an amount like 25.00.'),
        ];
    }

    /**
     * @return list<SplitPartData>
     */
    public function parts(): array
    {
        return array_values(array_map(
            fn (array $part): SplitPartData => new SplitPartData((int) $part['folio_id'], (string) $part['amount']),
            $this->validated('parts'),
        ));
    }
}
