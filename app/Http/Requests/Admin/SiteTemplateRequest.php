<?php

namespace App\Http\Requests\Admin;

use App\Enums\SiteTemplateType;
use App\Models\Property;
use App\Models\SiteTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a template or change one. A change keeps the key, type and property, so only a new template needs them.
 * The `can:admin` route middleware authorizes this request.
 */
class SiteTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'preview_url' => $this->filled('preview_url') ? trim((string) $this->input('preview_url')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'preview_url' => ['nullable', 'url:http,https', 'max:2048'],
            'allows_main_color' => ['boolean'],
            'slots' => ['array', 'max:20'],
            'slots.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
            'slots.*.label' => ['required', 'string', 'max:255'],
            'slots.*.required' => ['boolean'],
        ];

        if (! $this->route('siteTemplate') instanceof SiteTemplate) {
            $rules['key'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('site_templates', 'key')];
            $rules['type'] = ['required', Rule::enum(SiteTemplateType::class)];
            $rules['property_id'] = [
                Rule::requiredIf($this->input('type') === SiteTemplateType::Custom->value),
                Rule::prohibitedIf($this->input('type') === SiteTemplateType::Standard->value),
                'nullable',
                'integer',
                Rule::exists('properties', 'id'),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => __('Use lower case letters, digits and single hyphens. It must match the template folder name.'),
            'key.unique' => __('A template with this key already exists.'),
            'property_id.required' => __('Choose the property this custom design is for.'),
            'property_id.prohibited' => __('A standard template is not tied to a property.'),
            'slots.*.key.regex' => __('Use lower case letters, digits and single hyphens.'),
            'slots.*.key.distinct' => __('Each image slot needs its own key.'),
        ];
    }

    public function property(): ?Property
    {
        return $this->filled('property_id') ? Property::query()->findOrFail($this->integer('property_id')) : null;
    }

    /**
     * @return list<array{key: string, label: string, required: bool}>
     */
    public function slots(): array
    {
        /** @var array<int, array{key: string, label: string, required?: bool}> $slots */
        $slots = $this->array('slots');

        return array_values(array_map(
            fn (array $slot): array => [
                'key' => $slot['key'],
                'label' => trim($slot['label']),
                'required' => (bool) ($slot['required'] ?? false),
            ],
            $slots,
        ));
    }
}
