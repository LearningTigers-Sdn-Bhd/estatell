<?php

namespace App\Http\Requests\Catalogues;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class RoomRevenueTaxesRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'tax_ids' => ['present', 'array'],
            'tax_ids.*' => ['integer', 'distinct', Rule::exists('taxes', 'id')->where('property_id', $property->id)],
        ];
    }

    /** @return list<int> */
    public function taxIds(): array
    {
        return array_map('intval', array_values($this->validated('tax_ids')));
    }
}
