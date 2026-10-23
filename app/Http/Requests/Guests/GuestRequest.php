<?php

namespace App\Http\Requests\Guests;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class GuestRequest extends FormRequest
{
    /**
     * The `can:guests.manage` route middleware authorizes this request.
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
        return [
            ...GuestProfileRules::for($this->phoneRegion()),
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50'],
            'identity_number' => ['nullable', 'string', 'max:50'],
            'identity_type' => ['nullable', 'string', 'max:20'],
            'identity_country_code' => ['nullable', 'string', 'size:2'],
            'identity_photo' => ['nullable', 'string'],
        ];
    }

    /**
     * The profile fields for the model, with the phone number in E.164.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return GuestProfileRules::attributes($this->safe()->except(['tags', 'identity_number', 'identity_type', 'identity_country_code', 'identity_photo']), $this->phoneRegion());
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return array_values($this->validated('tags') ?? []);
    }

    /**
     * Numbers typed without a country code are read as the property's country.
     */
    private function phoneRegion(): string
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property->country_code ?? 'MY';
    }
}
