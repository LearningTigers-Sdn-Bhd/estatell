<?php

namespace App\Http\Requests\Portal;

use App\Actions\CityLedger\UpdatePortalCompanyDetails;
use App\Http\Requests\CityLedger\CompanyRules;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The company details form on the portal. The `portal` middleware is the gate, so `authorize()` allows the request.
 * The rules are the shared `CompanyRules` for these fields, so the portal and the staff forms cannot drift apart.
 */
class UpdatePortalCompanyRequest extends FormRequest
{
    /**
     * The company's own name and type are read-only, so they have no rules here.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'profile_managed_by_company' => $this->boolean('profile_managed_by_company'),
            'contact_email' => filled($this->input('contact_email')) ? mb_strtolower(trim((string) $this->input('contact_email'))) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            array_intersect_key(CompanyRules::for($this->property()), array_flip(UpdatePortalCompanyDetails::FIELDS)),
            ['profile_managed_by_company' => ['required', 'boolean']],
        );
    }

    /**
     * The validated fields for the Action, with the phone number in E.164. A field left empty is saved as null.
     *
     * @return array<string, mixed>
     */
    public function detailAttributes(): array
    {
        $attributes = [];

        foreach (UpdatePortalCompanyDetails::FIELDS as $field) {
            $attributes[$field] = $this->validated($field);
        }

        return CompanyRules::normalized($attributes, $this->property());
    }

    private function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }
}
