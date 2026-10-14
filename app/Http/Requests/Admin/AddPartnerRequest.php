<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PartnerValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Add a partner and its one login with a temp password. The prefix must be free in the property code counters,
 * which also covers ES and every other partner.
 */
class AddPartnerRequest extends FormRequest
{
    use PartnerValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code_prefix' => Str::upper(trim((string) $this->input('code_prefix'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partner_name' => ['required', 'string', 'max:255'],
            'code_prefix' => $this->partnerPrefixRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->partnerEmailRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->partnerPrefixMessages('code_prefix');
    }
}
