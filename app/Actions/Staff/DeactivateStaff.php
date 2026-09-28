<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeactivateStaff
{
    /**
     * Stop a staff user from signing in. Their open sessions end on the next request (LogoutDeactivatedUsers)
     * and "remember me" cookies stop working.
     *
     * Returns false for yourself, an already deactivated user, an Owner when the actor is not an Owner,
     * or the property's last active Owner.
     */
    public function __invoke(User $actor, User $staff): bool
    {
        if ($actor->is($staff)) {
            return false;
        }

        return DB::transaction(function () use ($actor, $staff): bool {
            Property::query()->lockForUpdate()->findOrFail($staff->property_id);
            $staff = User::query()->lockForUpdate()->findOrFail($staff->id);

            if ($staff->deactivated_at !== null) {
                return false;
            }

            $ownerRole = Role::query()->where('property_id', $staff->property_id)->where('default_role', DefaultRoleEnum::Owner)->firstOrFail();

            if ($ownerRole->users()->whereKey($staff->id)->exists()) {
                $actorIsOwner = $ownerRole->users()->whereKey($actor->id)->exists();
                $otherActiveOwner = $ownerRole->users()->whereKeyNot($staff->id)->whereNull('deactivated_at')->exists();

                if (! $actorIsOwner || ! $otherActiveOwner) {
                    return false;
                }
            }

            $staff->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.deactivated')
                ->withProperties(['property_id' => $staff->property_id])
                ->log('Staff deactivated');

            return true;
        });
    }
}
