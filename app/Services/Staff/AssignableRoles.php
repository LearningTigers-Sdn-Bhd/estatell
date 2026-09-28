<?php

namespace App\Services\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which roles a staff user may give to others. Only Owners may give the Owner role.
 * Reads the user's roles for the current permission team, which EnsurePropertyAccess sets.
 */
class AssignableRoles
{
    /**
     * @return Collection<int, Role>
     */
    public function for(Property $property, User $user): Collection
    {
        return Role::query()
            ->where('property_id', $property->id)
            ->when(! $this->isOwner($user), fn ($query) => $query->where(
                fn ($query) => $query->whereNull('default_role')->orWhere('default_role', '!=', DefaultRoleEnum::Owner),
            ))
            ->orderByRaw('default_role IS NULL')
            ->orderBy('id')
            ->get();
    }

    public function isOwner(User $user): bool
    {
        return $user->roles->contains(fn (Role $role): bool => $role->default_role === DefaultRoleEnum::Owner);
    }
}
