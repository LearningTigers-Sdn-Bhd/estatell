<?php

namespace App\Services\Onboarding;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

/**
 * The roles the Team table of onboarding offers: the seeded roles except Owner, in the order of `DefaultRoleEnum`.
 */
class TeamRoles
{
    /**
     * @return Collection<int, Role>
     */
    public function for(Property $property): Collection
    {
        $order = array_flip(array_map(fn (DefaultRoleEnum $role): string => $role->value, DefaultRoleEnum::cases()));

        return Role::query()
            ->where('property_id', $property->id)
            ->whereIn('default_role', array_filter(DefaultRoleEnum::cases(), fn (DefaultRoleEnum $role): bool => $role !== DefaultRoleEnum::Owner))
            ->get()
            ->sortBy(fn (Role $role): int => $order[$role->default_role->value])
            ->values();
    }
}
