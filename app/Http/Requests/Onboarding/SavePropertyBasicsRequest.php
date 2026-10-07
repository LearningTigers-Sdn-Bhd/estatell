<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\PropertyType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePropertyBasicsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            'type' => ['required', Rule::enum(PropertyType::class)],
            ...self::identityRules(),
        ];
    }

    /**
     * The name and address rules, shared with the Profile tab of the Details page.
     *
     * @return array<string, array<int, string>>
     */
    public static function identityRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state_code' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
