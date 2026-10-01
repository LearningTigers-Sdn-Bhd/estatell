<?php

namespace App\Http\Requests\Guests;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

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
        $countries = app(CountryOptions::class)->codes();

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'nationality_code' => ['nullable', Rule::in($countries)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'preferred_locale' => ['nullable', Rule::enum(Locale::class)],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', Rule::in($countries)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * The profile fields for the model, with the phone number in E.164.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $attributes = $this->safe()->except('tags');

        if (filled($attributes['phone'] ?? null)) {
            $attributes['phone'] = (new PhoneNumber($attributes['phone'], $this->phoneRegion()))->formatE164();
        }

        return $attributes;
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
