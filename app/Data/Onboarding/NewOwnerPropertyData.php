<?php

namespace App\Data\Onboarding;

use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyType;
use Spatie\LaravelData\Data;

/**
 * A property an admin or partner creates for an owner, with how the owner sets up the account. `password` is used only
 * with the InitialPassword setup method. `verifyOwnerEmail` is honored only with that method too. The password must
 * never be logged or passed to an activity log.
 */
class NewOwnerPropertyData extends Data
{
    public function __construct(
        public string $propertyName,
        public PropertyType $type,
        public string $ownerName,
        public string $ownerEmail,
        public OwnerSetupMethod $setupMethod,
        public ?string $password = null,
        public bool $verifyOwnerEmail = false,
    ) {}
}
