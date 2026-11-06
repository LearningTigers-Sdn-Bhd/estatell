<?php

namespace App\Http\Requests\BookingEngine;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The General tab form. The route's `can:` middleware checks `booking-website.manage`. A published site keeps its slug.
 */
class SaveBookingWebsiteGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            'slug' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('properties', 'slug')->ignore($property)],
            'google_maps_url' => ['nullable', 'url:http,https', 'max:2048'],
            'about_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function aboutText(): ?string
    {
        return $this->filled('about_text') ? trim($this->string('about_text')->toString()) : null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $property = $this->route('property');

            if ($property instanceof Property && $property->bookingSite?->isPublished() && $this->input('slug') !== $property->slug) {
                $validator->errors()->add('slug', __('The web address of a published site can only be changed by support.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lower case letters, digits and single hyphens only.'),
        ];
    }
}
