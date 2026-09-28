<?php

namespace App\Data\Onboarding;

use App\Models\User;
use Spatie\LaravelData\Data;

class OwnerSetupData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $propertyName,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self(
            name: $owner->name,
            email: $owner->email,
            propertyName: $owner->property->name,
        );
    }
}
