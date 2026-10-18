<?php

namespace App\Data\Portal;

use App\Data\Shared\BadgeData;
use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * The company details the company user sees and edits on the portal company page. The credit limit, terms and
 * internal notes are left out: the company sees only what it may read.
 */
class PortalCompanyData extends Data
{
    public function __construct(
        public string $name,
        public string $typeLabel,
        public BadgeData $status,
        public bool $profileManagedByCompany,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $brn,
        public ?string $tin,
        public ?string $sstRegistrationNumber,
        public ?string $billingAddressLine1,
        public ?string $billingAddressLine2,
        public ?string $billingCity,
        public ?string $billingStateCode,
        public ?string $billingPostalCode,
        public ?string $billingCountryCode,
    ) {}

    public static function fromModel(Company $company): self
    {
        return new self(
            name: $company->name,
            typeLabel: $company->type->label(),
            status: BadgeData::fromCompanyStatus($company->status),
            profileManagedByCompany: $company->profile_managed_by_company,
            contactEmail: $company->contact_email,
            contactPhone: $company->contact_phone,
            brn: $company->brn,
            tin: $company->tin,
            sstRegistrationNumber: $company->sst_registration_number,
            billingAddressLine1: $company->billing_address_line1,
            billingAddressLine2: $company->billing_address_line2,
            billingCity: $company->billing_city,
            billingStateCode: $company->billing_state_code,
            billingPostalCode: $company->billing_postal_code,
            billingCountryCode: $company->billing_country_code,
        );
    }
}
