<?php

namespace App\Http\Requests\Admin;

use App\Enums\WebhookEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a webhook endpoint or change one. The URL must be `https`. The `can:admin` route middleware authorizes this
 * request.
 */
class WebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2000', 'url:https'],
            'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(WebhookEvent::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => __('Enter a full https:// address.'),
            'events.required' => __('Choose at least one event.'),
            'events.min' => __('Choose at least one event.'),
        ];
    }
}
