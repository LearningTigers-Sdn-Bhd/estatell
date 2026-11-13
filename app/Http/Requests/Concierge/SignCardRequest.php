<?php

namespace App\Http\Requests\Concierge;

use App\Enums\Locale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignCardRequest extends FormRequest
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
            'terms_version' => ['required', 'integer', 'min:1'],
            'signature_png' => ['required', 'string', 'max:700000'],
            'locale' => ['nullable', Rule::enum(Locale::class)],
        ];
    }

    public function locale(): ?Locale
    {
        return Locale::tryFrom((string) $this->input('locale'));
    }
}
