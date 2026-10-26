<?php

namespace App\Actions\Platform;

use App\Models\Property;
use App\Models\User;
use App\Notifications\SupportAccessUsed;
use App\Services\Platform\SupportMoneyCorrections;
use App\Services\Staff\PropertyOwners;
use Spatie\Activitylog\Models\Activity;

class NotifySupportMoneyCorrection
{
    public function __construct(private PropertyOwners $owners, private SupportMoneyCorrections $moneyCorrections) {}

    /**
     * Tell the property's owner, in the app, about a money correction support made while viewing the property as its
     * staff. Runs for every new activity log entry and does nothing unless the entry is a money event logged during a
     * support session (it then carries `impersonated_user_id`). See docs/01-platform/04-impersonation.md#money-corrections.
     */
    public function __invoke(Activity $activity): void
    {
        $correction = $this->moneyCorrections->fromActivity($activity);
        $supportUser = $activity->causer;

        if ($correction === null || ! $activity->properties->has('impersonated_user_id') || ! $supportUser instanceof User) {
            return;
        }

        $propertyId = $activity->properties->get('property_id');
        $property = is_int($propertyId) ? Property::query()->find($propertyId) : null;
        $owner = $property === null ? null : $this->owners->firstFor($property);

        if ($owner === null || $owner->deactivated_at !== null) {
            return;
        }

        $owner->notify(new SupportAccessUsed(
            supportUserName: $supportUser->name,
            partnerName: $supportUser->partner?->name,
            label: $correction['label'],
            amount: $correction['amount'],
            reference: $correction['reference'],
        ));
    }
}
