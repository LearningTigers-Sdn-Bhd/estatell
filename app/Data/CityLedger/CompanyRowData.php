<?php

namespace App\Data\CityLedger;

use App\Data\Shared\BadgeData;
use App\Enums\CompanyType;
use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * One row of the companies list.
 */
class CompanyRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public CompanyType $type,
        public string $typeLabel,
        public ?string $contactEmail,
        public ?string $creditLimit,
        public ?int $paymentTermsDays,
        public BadgeData $status,
        public bool $overLimit = false,
    ) {}

    public static function fromModel(Company $company, bool $overLimit = false): self
    {
        return new self(
            id: $company->id,
            name: $company->name,
            type: $company->type,
            typeLabel: $company->type->label(),
            contactEmail: $company->contact_email,
            creditLimit: $company->credit_limit,
            paymentTermsDays: $company->payment_terms_days,
            status: BadgeData::fromCompanyStatus($company->status),
            overLimit: $overLimit,
        );
    }
}
