<?php

namespace App\Actions\Onboarding;

use App\Models\Property;
use App\Models\User;
use App\Notifications\OwnerSetupNotification;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class SendOwnerSetupLink
{
    public function __construct(private PropertyOwners $propertyOwners) {}

    public function __invoke(User $admin, Property $property): bool
    {
        return DB::transaction(function () use ($admin, $property): bool {
            $owner = $this->propertyOwners->firstFor($property);

            if ($owner === null) {
                return false;
            }

            $owner = User::query()->lockForUpdate()->findOrFail($owner->id);

            if ($owner->hasVerifiedEmail()) {
                return false;
            }

            $owner->notify(new OwnerSetupNotification);

            activity()
                ->performedOn($owner)
                ->causedBy($admin)
                ->event('owner.setup-link-sent')
                ->withProperties(['property_id' => $property->id, 'email' => $owner->email])
                ->log('Owner setup link sent');

            return true;
        });
    }
}
