<?php

namespace App\Http\Requests\CityLedger;

use App\Models\Company;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The add company sheet and the company page forms, each saved on its own. Only name and type are required. The tax
 * numbers and billing address have their own form, which the company can lock from the portal.
 * See docs/15-city-ledger/01-companies.md.
 */
class CompanyRequest extends FormRequest
{
    /**
     * The company page tabs that save with `PUT .../companies/{company}/{section}`.
     *
     * @var list<string>
     */
    public const array SECTIONS = ['details', 'credit'];

    /**
     * The fields each form sends. `add` is the add company sheet, which has no section.
     *
     * @var array<string, list<string>>
     */
    private const array FIELDS = [
        'add' => ['name', 'type', 'contact_email', 'contact_phone', 'credit_limit', 'payment_terms_days', 'direct_bill_enabled'],
        'details' => ['name', 'type', 'contact_email', 'contact_phone'],
        'credit' => ['credit_limit', 'payment_terms_days', 'direct_bill_enabled'],
        'billing' => [
            'brn', 'tin', 'sst_registration_number', 'billing_address_line1', 'billing_address_line2',
            'billing_city', 'billing_state_code', 'billing_postal_code', 'billing_country_code',
        ],
    ];

    /**
     * The `can:company.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->section(), ['add', 'credit'], true)) {
            $this->merge(['direct_bill_enabled' => $this->boolean('direct_bill_enabled')]);
        }

        if ($this->section() === 'credit') {
            return;
        }

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'contact_email' => filled($this->input('contact_email')) ? mb_strtolower(trim((string) $this->input('contact_email'))) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $current = $this->route('company');

        return array_intersect_key(
            CompanyRules::for($this->property(), $current instanceof Company ? $current : null),
            array_flip(self::FIELDS[$this->section()]),
        );
    }

    /**
     * The fields of this form for the model, with the phone number in E.164. A field left empty is saved as null.
     *
     * @return array<string, mixed>
     */
    public function companyAttributes(): array
    {
        $attributes = [];

        foreach (self::FIELDS[$this->section()] as $field) {
            $attributes[$field] = $this->validated($field);
        }

        return CompanyRules::normalized($attributes, $this->property());
    }

    /**
     * @return 'add'|'details'|'credit'|'billing'
     */
    private function section(): string
    {
        $section = $this->route('section');

        if (in_array($section, self::SECTIONS, true)) {
            return $section;
        }

        return $this->routeIs('finance.city-ledger.companies.billing.update') ? 'billing' : 'add';
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
