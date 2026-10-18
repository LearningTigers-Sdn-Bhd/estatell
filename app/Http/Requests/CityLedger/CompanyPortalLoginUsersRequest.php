<?php

namespace App\Http\Requests\CityLedger;

use App\Enums\CompanyUserStatus;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Company logins chosen for a copy message or a temp password file. Every id must be a company user with an Active link
 * at this property. The `can:company.manage` route middleware authorizes it.
 */
class CompanyPortalLoginUsersRequest extends FormRequest
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
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => [
                'integer', 'distinct',
                Rule::exists('company_users', 'user_id')
                    ->where('property_id', $property->id)
                    ->where('status', CompanyUserStatus::Active->value),
            ],
        ];
    }
}
