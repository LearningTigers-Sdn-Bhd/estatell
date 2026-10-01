<?php

namespace App\Http\Requests\Guests;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class MergeGuestsRequest extends FormRequest
{
    /**
     * The `can:guests.merge` route middleware authorizes this request.
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
            'duplicate_id' => ['required', 'integer', Rule::exists('guests', 'id')->where('property_id', $property->id)],
        ];
    }
}
