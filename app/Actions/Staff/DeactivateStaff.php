<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\User;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeactivateStaff
{
    public function __construct(private PropertyOwners $owners) {}

    /**
     * Stop a staff user from opening the property: their membership there is deactivated. When no other membership is
     * active, they cannot sign in at all: `users.deactivated_at` is set, their open sessions end on the next request
     * (LogoutDeactivatedUsers) and "remember me" cookies stop working. See docs/03-staff/04-property-groups.md.
     *
     * Returns false for yourself, someone with no active membership here, an Owner when the actor is not an Owner
     * here, or the property's last active Owner.
     */
    public function __invoke(User $actor, Property $property, User $staff): bool
    {
        if ($actor->is($staff)) {
            return false;
        }

        return DB::transaction(function () use ($actor, $property, $staff): bool {
            Property::query()->lockForUpdate()->findOrFail($property->id);
            $membership = PropertyMembership::query()->where('property_id', $property->id)->where('user_id', $staff->id)->lockForUpdate()->first();

            if ($membership === null || $membership->deactivated_at !== null) {
                return false;
            }

            if ($this->owners->isOwner($property, $staff) && (! $this->owners->isOwner($property, $actor) || ! $this->owners->hasActiveOwnerOtherThan($property, $staff))) {
                return false;
            }

            $membership->forceFill(['deactivated_at' => now()])->save();
            $signsInElsewhere = PropertyMembership::query()->where('user_id', $staff->id)->whereNull('deactivated_at')->exists();

            if (! $signsInElsewhere) {
                User::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();
            }

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.deactivated')
                ->withProperties(['property_id' => $property->id, 'signs_in_elsewhere' => $signsInElsewhere])
                ->log('Staff deactivated');

            return true;
        });
    }
}
