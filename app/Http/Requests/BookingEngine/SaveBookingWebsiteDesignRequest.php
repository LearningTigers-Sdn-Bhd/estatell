<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Design tab form. The route's `can:` middleware checks `booking-website.manage`. Whether the template can be
 * picked is checked by the Action, because it depends on the property.
 */
class SaveBookingWebsiteDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'site_template_id' => $this->filled('site_template_id') ? $this->input('site_template_id') : null,
            'main_color' => $this->filled('main_color') ? trim((string) $this->input('main_color')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_template_id' => ['nullable', 'integer', Rule::exists('site_templates', 'id')],
            'main_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'main_color.regex' => __('Use a colour like #0F766E.'),
        ];
    }

    public function templateId(): ?int
    {
        return $this->filled('site_template_id') ? $this->integer('site_template_id') : null;
    }

    public function mainColor(): ?string
    {
        return $this->filled('main_color') ? strtoupper($this->string('main_color')->toString()) : null;
    }
}
