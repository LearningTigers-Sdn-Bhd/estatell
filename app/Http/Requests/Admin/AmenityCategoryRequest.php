<?php

namespace App\Http\Requests\Admin;

use App\Enums\AmenityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an amenity category or rename one. The type is only sent when adding, because it never changes. The `can:admin`
 * route middleware authorizes this request.
 */
class AmenityCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/[A-Za-z0-9]/'],
            'type' => [$this->route('amenityCategory') === null ? 'required' : 'prohibited', Rule::enum(AmenityType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use at least one letter or number.'),
        ];
    }
}
