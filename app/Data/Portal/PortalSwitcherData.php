<?php

namespace App\Data\Portal;

use App\Models\CompanyUser;
use Spatie\LaravelData\Data;

/**
 * One property in the portal sidebar's property switcher.
 */
class PortalSwitcherData extends Data
{
    public function __construct(
        public string $propertyName,
        public string $propertyCode,
        public string $companyName,
    ) {}

    public static function fromLink(CompanyUser $link): self
    {
        return new self(
            propertyName: $link->property->name,
            propertyCode: $link->property->code,
            companyName: $link->company->name,
        );
    }
}
