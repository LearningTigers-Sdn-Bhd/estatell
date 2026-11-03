<?php

namespace App\Http\Requests\Admin;

use App\Enums\AmenityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an amenity or change one. The type is only sent when adding, because it never changes. The `can:admin` route
 * middleware authorizes this request.
 */
class AmenityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'category' => trim((string) $this->input('category')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/[A-Za-z0-9]/'],
            'category' => ['required', 'string', 'max:60'],
            'type' => [$this->route('amenity') === null ? 'required' : 'prohibited', Rule::enum(AmenityType::class)],
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
