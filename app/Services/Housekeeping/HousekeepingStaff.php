<?php

namespace App\Services\Housekeeping;

use App\Data\Housekeeping\HousekeepingStaffData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;

/**
 * The staff who can be assigned a room or named on a cleaning: active staff of the property who can update the board.
 */
class HousekeepingStaff
{
    /**
     * @return list<HousekeepingStaffData>
     */
    public function for(Property $property): array
    {
        $roleIds = Role::query()
            ->where('property_id', $property->id)
            ->whereHas('permissions', fn ($query) => $query->where('name', PermissionEnum::HousekeepingUpdate->value))
            ->pluck('id');

        return array_values(User::query()
            ->where('property_id', $property->id)
            ->whereNull('deactivated_at')
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->orderBy('name')
            ->get()
            ->map(fn (User $staff): HousekeepingStaffData => HousekeepingStaffData::fromModel($staff))
            ->all());
    }
}
