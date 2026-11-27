<?php

namespace App\Services\Staff;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The active staff of a property who hold a permission there, for notifications: members whose membership at the
 * property is active (staff given access from another property of the group included), checked against their role at
 * that property. The permission team is restored afterwards. See docs/03-staff/04-property-groups.md.
 */
class PropertyStaff
{
    /**
     * @return Collection<int, User>
     */
    public function withPermission(int $propertyId, string $permission): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($propertyId);

        try {
            return User::query()
                ->activeMemberOf($propertyId)
                ->where('is_admin', false)
                ->permission($permission)
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
