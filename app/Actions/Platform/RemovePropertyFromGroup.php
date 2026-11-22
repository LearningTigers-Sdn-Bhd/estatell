<?php

namespace App\Actions\Platform;

use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RemovePropertyFromGroup
{
    /**
     * Take a property out of its group. Returns false while anyone crosses the boundary: staff of another property
     * with access here, or staff of this property with access elsewhere. Their access is deactivated in Settings ›
     * Staff first, so removing a property never cuts someone off without notice. See docs/03-staff/04-property-groups.md.
     */
    public function __invoke(User $admin, Property $property): bool
    {
        return DB::transaction(function () use ($admin, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $groupId = $property->property_group_id;

            if ($groupId === null || $this->hasSharedStaff($property)) {
                return false;
            }

            $property->forceFill(['property_group_id' => null])->save();

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property-group.property-removed')
                ->withProperties(['property_id' => $property->id, 'property_group_id' => $groupId])
                ->log('Property removed from group');

            return true;
        });
    }

    /**
     * Whether an active membership joins this property with a staff user of another home property, either way.
     */
    private function hasSharedStaff(Property $property): bool
    {
        return PropertyMembership::query()
            ->whereNull('deactivated_at')
            ->whereHas('user', fn (Builder $user) => $user->whereColumn('users.property_id', '<>', 'property_memberships.property_id'))
            ->where(fn (Builder $query) => $query
                ->where('property_id', $property->id)
                ->orWhereHas('user', fn (Builder $user) => $user->where('users.property_id', $property->id)))
            ->exists();
    }
}
