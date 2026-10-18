<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The company user's own edit of the company's contact, tax numbers, billing address and the "we manage them" tick.
 * Saves through `UpdateCompany`, so the change is logged as `company.updated` with the company user as causer. It does
 * not use `UpdateCompanyBilling`, which refuses a company that manages its own details. Name, type, status and any
 * other key are ignored. See docs/23-company-portal/01-company-portal.md#company-details.
 */
class UpdatePortalCompanyDetails
{
    /**
     * The fields the company may change.
     */
    public const array FIELDS = [
        'profile_managed_by_company', 'contact_email', 'contact_phone', 'brn', 'tin', 'sst_registration_number',
        'billing_address_line1', 'billing_address_line2', 'billing_city', 'billing_state_code', 'billing_postal_code',
        'billing_country_code',
    ];

    public function __construct(private UpdateCompany $updateCompany) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated portal fields. Keys outside FIELDS are dropped.
     */
    public function __invoke(User $companyUser, Company $company, array $attributes): void
    {
        ($this->updateCompany)($companyUser, $company, Arr::only($attributes, self::FIELDS));
    }
}
