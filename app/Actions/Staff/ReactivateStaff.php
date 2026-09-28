<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReactivateStaff
{
    /**
     * Let a deactivated staff user sign in again with their old role.
     *
     * Returns false when the user is active, or is an Owner and the actor is not an Owner.
     */
    public function __invoke(User $actor, User $staff): bool
    {
        return DB::transaction(function () use ($actor, $staff): bool {
            $staff = User::query()->lockForUpdate()->findOrFail($staff->id);

            if ($staff->deactivated_at === null) {
                return false;
            }

            $ownerRole = Role::query()->where('property_id', $staff->property_id)->where('default_role', DefaultRoleEnum::Owner)->firstOrFail();

            if ($ownerRole->users()->whereKey($staff->id)->exists() && ! $ownerRole->users()->whereKey($actor->id)->exists()) {
                return false;
            }

            $staff->forceFill(['deactivated_at' => null])->save();

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.reactivated')
                ->withProperties(['property_id' => $staff->property_id])
                ->log('Staff reactivated');

            return true;
        });
    }
}
