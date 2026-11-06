<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SEO tab form. The route's `can:` middleware checks `booking-website.manage`.
 */
class SaveBookingWebsiteSeoRequest extends FormRequest
{
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
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function title(): ?string
    {
        return $this->filled('seo_title') ? trim($this->string('seo_title')->toString()) : null;
    }

    public function description(): ?string
    {
        return $this->filled('seo_description') ? trim($this->string('seo_description')->toString()) : null;
    }
}
