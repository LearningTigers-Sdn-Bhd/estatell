<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\Onboarding\SavePropertyBasicsRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyProfileRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The registered name, registration number and TIN are on the Business licence tab. Type and country are not here: they seeded the currency, taxes and presets at Basics.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...SavePropertyBasicsRequest::identityRules(),
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
