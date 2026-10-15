<?php

namespace App\Data\Onboarding;

use App\Models\Role;
use Spatie\LaravelData\Data;

/**
 * A role of the Team table: the option in the Role select and the line in the Roles card.
 */
class OnboardingRoleData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self(
            value: (string) $role->id,
            label: $role->name,
            description: $role->default_role->description(),
        );
    }
}
