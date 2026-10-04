<?php

namespace App\Data\CityLedger;

use App\Data\Shared\BadgeData;
use App\Enums\CompanyType;
use App\Models\Company;
use App\Services\Guests\CountryOptions;
use Spatie\LaravelData\Data;

/**
 * A company for the company page. The tax numbers and billing address are editable by hotel staff until the company
 * manages them itself in the company portal (`profileManagedByCompany`).
 */
class CompanyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public CompanyType $type,
        public string $typeLabel,
        public ?string $brn,
        public ?string $tin,
        public ?string $sstRegistrationNumber,
        public ?string $billingAddressLine1,
        public ?string $billingAddressLine2,
        public ?string $billingCity,
        public ?string $billingStateCode,
        public ?string $billingPostalCode,
        public ?string $billingCountryCode,
        public ?string $billingAddress,
        public bool $profileManagedByCompany,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $creditLimit,
        public ?int $paymentTermsDays,
        public bool $directBillEnabled,
        public BadgeData $status,
        public string $createdAt,
        public ?CreditExposureData $credit = null,
    ) {}

    public static function fromModel(Company $company, ?CreditExposureData $credit = null): self
    {
        return new self(
            id: $company->id,
            name: $company->name,
            type: $company->type,
            typeLabel: $company->type->label(),
            brn: $company->brn,
            tin: $company->tin,
            sstRegistrationNumber: $company->sst_registration_number,
            billingAddressLine1: $company->billing_address_line1,
            billingAddressLine2: $company->billing_address_line2,
            billingCity: $company->billing_city,
            billingStateCode: $company->billing_state_code,
            billingPostalCode: $company->billing_postal_code,
            billingCountryCode: $company->billing_country_code,
            billingAddress: self::billingAddress($company),
            profileManagedByCompany: $company->profile_managed_by_company,
            contactEmail: $company->contact_email,
            contactPhone: $company->contact_phone,
            creditLimit: $company->credit_limit,
            paymentTermsDays: $company->payment_terms_days,
            directBillEnabled: $company->direct_bill_enabled,
            status: BadgeData::fromCompanyStatus($company->status),
            createdAt: $company->created_at->toDateString(),
            credit: $credit,
        );
    }

    /**
     * The billing address as lines: street lines, then postcode and city, then state and country. Null when empty.
     */
    private static function billingAddress(Company $company): ?string
    {
        $lines = array_filter([
            $company->billing_address_line1,
            $company->billing_address_line2,
            trim(implode(' ', array_filter([$company->billing_postal_code, $company->billing_city]))),
            implode(', ', array_filter([$company->billing_state_code, app(CountryOptions::class)->name($company->billing_country_code)])),
        ], fn (?string $line): bool => filled($line));

        return $lines === [] ? null : implode("\n", $lines);
    }
}
