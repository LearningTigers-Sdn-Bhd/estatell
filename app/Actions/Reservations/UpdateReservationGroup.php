<?php

namespace App\Actions\Reservations;

use App\Enums\BillingMode;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateReservationGroup
{
    /**
     * Change the group name and billing mode. A blank name clears it. Nothing is written or logged when nothing changed.
     */
    public function __invoke(User $actor, Reservation $reservation, ?string $groupName, BillingMode $billingMode): Reservation
    {
        return DB::transaction(function () use ($actor, $reservation, $groupName, $billingMode): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $groupName = $groupName === null || trim($groupName) === '' ? null : trim($groupName);

            if ($locked->group_name !== $groupName || $locked->billing_mode !== $billingMode) {
                $old = ['group_name' => $locked->group_name, 'billing_mode' => $locked->billing_mode->value];

                $locked->group_name = $groupName;
                $locked->billing_mode = $billingMode;
                $locked->save();

                activity()
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('reservation.group_updated')
                    ->withProperties([
                        'property_id' => $locked->property_id,
                        'old' => $old,
                        'new' => ['group_name' => $groupName, 'billing_mode' => $billingMode->value],
                    ])
                    ->log('Reservation group updated');
            }

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $reservation;
        });
    }
}
