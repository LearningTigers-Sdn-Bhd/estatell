<?php

namespace App\Services\Platform;

use App\Data\Platform\ViewAsStaffData;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\User;

/**
 * The staff a support user can pick on the view-as page: the property's active users, Owner first. Reads the role
 * by a join, so it needs no permission team.
 */
class ViewAsStaff
{
    /**
     * @return list<ViewAsStaffData>
     */
    public function for(Property $property): array
    {
        return array_values(User::query()
            ->leftJoin('model_has_roles', fn ($join) => $join
                ->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.property_id', $property->id)
            ->whereNull('users.deactivated_at')
            ->orderByRaw('coalesce(roles.default_role = ?, false) desc', [DefaultRoleEnum::Owner->value])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'roles.name as role_name', 'roles.default_role as default_role'])
            ->unique('id')
            ->map(fn (User $user): ViewAsStaffData => new ViewAsStaffData(
                id: $user->id,
                name: $user->name,
                role: $user->getAttribute('role_name'),
                isOwner: $user->getAttribute('default_role') === DefaultRoleEnum::Owner->value,
            ))
            ->values()
            ->all());
    }
}
