<?php

namespace App\Http\Requests\Partner;

use App\Concerns\PasswordValidationRules;
use App\Data\Platform\AcceptPartnerInvitationData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptPartnerInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * The invitation token in the URL is the authorization.
     */
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
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }

    public function toAcceptPartnerInvitationData(): AcceptPartnerInvitationData
    {
        return new AcceptPartnerInvitationData(
            name: $this->string('name')->toString(),
            password: $this->string('password')->toString(),
        );
    }
}
