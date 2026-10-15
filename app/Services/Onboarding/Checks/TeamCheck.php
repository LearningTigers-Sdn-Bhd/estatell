<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\DefaultRoleEnum;
use App\Enums\StepState;
use App\Models\Property;
use App\Models\User;

/**
 * Done when the property has at least one active staff user who is not an Owner. The step is optional: a skip is
 * turned into Done by OnboardingProgress before this check runs, and a missing team never blocks go-live.
 */
class TeamCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $hasStaff = $property->users()
            ->whereNull('deactivated_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->where('roles.default_role', DefaultRoleEnum::Owner->value))
            ->exists();

        return $hasStaff ? StepState::Done : StepState::NotStarted;
    }
}
