<?php

namespace App\Http\Requests\Admin;

use App\Models\Amenity;
use App\Models\AmenityCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an amenity or change one. A new amenity takes its type from the category; a changed one keeps its type, so its
 * category must be of that type. The `can:admin` route middleware authorizes this request.
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
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $amenity = $this->route('amenity');
        $category = Rule::exists('amenity_categories', 'id');

        if ($amenity instanceof Amenity) {
            $category->where('type', $amenity->type->value);
        }

        return [
            'name' => ['required', 'string', 'max:255', 'regex:/[A-Za-z0-9]/'],
            'amenity_category_id' => ['required', 'integer', $category],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use at least one letter or number.'),
            'amenity_category_id.required' => __('Choose a category.'),
            'amenity_category_id.exists' => __('Choose a category of the same type.'),
        ];
    }

    public function category(): AmenityCategory
    {
        return AmenityCategory::query()->findOrFail($this->integer('amenity_category_id'));
    }
}
