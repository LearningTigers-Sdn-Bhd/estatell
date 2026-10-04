<?php

namespace App\Data\CityLedger;

use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * A company to pick on the booking form or the Change company Dialog.
 */
class CompanyOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(Company $company): self
    {
        return new self($company->id, $company->name);
    }
}
