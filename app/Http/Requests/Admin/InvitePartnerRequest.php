<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PartnerValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Invite a partner by email. The prefix and email rules are the same as adding a partner, so an email with an
 * account or an open partner invitation is refused here too.
 */
class InvitePartnerRequest extends FormRequest
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
