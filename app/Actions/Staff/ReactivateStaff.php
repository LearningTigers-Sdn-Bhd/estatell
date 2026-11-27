<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\User;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class ReactivateStaff
{
    public function __construct(private PropertyOwners $owners) {}

    /**
     * Let a deactivated staff user open the property again with their old role there, and sign in again.
     * See docs/03-staff/04-property-groups.md.
     *
     * Returns false when their membership here is active or missing, or they are an Owner here and the actor is not.
     */
    public function __invoke(User $actor, Property $property, User $staff): bool
    {
        return DB::transaction(function () use ($actor, $property, $staff): bool {
            $membership = PropertyMembership::query()->where('property_id', $property->id)->where('user_id', $staff->id)->lockForUpdate()->first();

            if ($membership === null || $membership->deactivated_at === null) {
                return false;
            }

            if ($this->owners->isOwner($property, $staff) && ! $this->owners->isOwner($property, $actor)) {
                return false;
            }

            $membership->forceFill(['deactivated_at' => null])->save();
            User::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail()->forceFill(['deactivated_at' => null])->save();

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.reactivated')
                ->withProperties(['property_id' => $property->id])
                ->log('Staff reactivated');

            return true;
        });
    }
}
