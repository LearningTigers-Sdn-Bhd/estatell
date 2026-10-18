<?php

namespace App\Data\Onboarding;

use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * One saved company as a row of the onboarding Companies table. A suspended company shows as a read-only row: it is
 * reactivated in settings. A company that something refers to, or any company after go-live, cannot be removed.
 */
class OnboardingCompanyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public CompanyType $type,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $creditLimit,
        public ?int $paymentTermsDays,
        public bool $directBillEnabled,
        public ?string $brn,
        public ?string $tin,
        public ?string $sstRegistrationNumber,
        public ?string $portalEmail,
        public ?int $portalCancelDays,
        public bool $isEditable,
        public bool $isRemovable,
        public string $statusLabel,
    ) {}

    public static function fromModel(Company $company, bool $isRemovable): self
    {
        return new self(
            id: $company->id,
            name: $company->name,
            type: $company->type,
            contactEmail: $company->contact_email,
            contactPhone: $company->contact_phone,
            creditLimit: $company->credit_limit,
            paymentTermsDays: $company->payment_terms_days,
            directBillEnabled: $company->direct_bill_enabled,
            brn: $company->brn,
            tin: $company->tin,
            sstRegistrationNumber: $company->sst_registration_number,
            portalEmail: $company->portalLink?->user->email,
            portalCancelDays: $company->portal_cancel_cutoff_days,
            isEditable: $company->status === CompanyStatus::Active,
            isRemovable: $isRemovable,
            statusLabel: $company->status->label(),
        );
    }
}
