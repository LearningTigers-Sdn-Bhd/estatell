<?php

namespace App\Http\Requests\CityLedger;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * The validation rules of a company's fields, shared by the add sheet, the company page forms and Bulk add, so they
 * cannot drift apart. A phone number typed without a country code is read as a number of the property's country.
 */
class CompanyRules
{
    /**
     * @return array<string, array<mixed>> Rules keyed by field name.
     */
    public static function for(Property $property, ?Company $current = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150', self::nameIsFree($property, $current)],
            'type' => ['required', Rule::enum(CompanyType::class)],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30', (new Phone)->country(self::phoneRegion($property))->international()],
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
    }

    /**
     * The phone number in E.164 when one is given.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalized(array $attributes, Property $property): array
    {
        if (filled($attributes['contact_phone'] ?? null)) {
            $attributes['contact_phone'] = (new PhoneNumber($attributes['contact_phone'], self::phoneRegion($property)))->formatE164();
        }

        return $attributes;
    }

    /**
     * Names are unique per property, ignoring case.
     */
    private static function nameIsFree(Property $property, ?Company $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
            $taken = Company::query()
                ->where('property_id', $property->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->when($current instanceof Company, fn (Builder $query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $fail(__('Another company already uses this name.'));
            }
        };
    }

    private static function phoneRegion(Property $property): string
    {
        return $property->country_code ?? 'MY';
    }
}
