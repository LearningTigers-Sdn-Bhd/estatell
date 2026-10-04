<?php

namespace App\Http\Requests\CityLedger;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

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
        $property = $this->property();
        $current = $this->route('company');
        $current = $current instanceof Company ? $current : null;

        $rules = [
            'name' => [
                'required', 'string', 'max:150',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = Company::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof Company, fn (Builder $query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another company already uses this name.'));
                    }
                },
            ],
            'type' => ['required', Rule::enum(CompanyType::class)],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'direct_bill_enabled' => ['required', 'boolean'],
            'brn' => ['nullable', 'string', 'max:30'],
            'tin' => ['nullable', 'string', 'max:30'],
            'sst_registration_number' => ['nullable', 'string', 'max:30'],
            'billing_address_line1' => ['nullable', 'string', 'max:150'],
            'billing_address_line2' => ['nullable', 'string', 'max:150'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'billing_state_code' => ['nullable', 'string', 'max:100'],
            'billing_postal_code' => ['nullable', 'string', 'max:20'],
            'billing_country_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
        ];

        return array_intersect_key($rules, array_flip(self::FIELDS[$this->section()]));
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

        if (filled($attributes['contact_phone'] ?? null)) {
            $attributes['contact_phone'] = (new PhoneNumber($attributes['contact_phone'], $this->phoneRegion()))->formatE164();
        }

        return $attributes;
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

    /**
     * Numbers typed without a country code are read as the property's country.
     */
    private function phoneRegion(): string
    {
        return $this->property()->country_code ?? 'MY';
    }
}
