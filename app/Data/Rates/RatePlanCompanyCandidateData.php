<?php

namespace App\Data\Rates;

use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * An Active company that can still be added to a rate plan, for the Add companies Dialog.
 */
class RatePlanCompanyCandidateData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
    ) {}

    public static function fromModel(Company $company): self
    {
        return new self($company->id, $company->name, $company->type->label());
    }
}
