<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * The owner and the property a sign-up creates. The password is set on the owner and must never be logged or passed
 * to an activity log.
 */
class NewPropertyOwnerData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $propertyName,
    ) {}
}
