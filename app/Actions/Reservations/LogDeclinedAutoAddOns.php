<?php

namespace App\Actions\Reservations;

use App\Models\ChargeItem;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;

class LogDeclinedAutoAddOns
{
    /**
     * Log each auto-add charge item that staff removed from a room on the booking form. Only active auto-add items
     * of the reservation's property are logged, so a stale or foreign id writes nothing. Call it inside the
     * transaction of the unit it belongs to.
     *
     * @param  list<int>  $chargeItemIds
     */
    public function __invoke(User $actor, Reservation $reservation, ReservationUnit $unit, array $chargeItemIds): void
    {
        if ($chargeItemIds === []) {
            return;
        }

        $items = ChargeItem::query()
            ->where('property_id', $reservation->property_id)
            ->active()
            ->where('auto_add', true)
            ->whereKey($chargeItemIds)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            activity()
                ->performedOn($reservation)
                ->causedBy($actor)
                ->event('reservation.auto_add_on_declined')
                ->withProperties([
                    'property_id' => $reservation->property_id,
                    'reservation_unit_id' => $unit->id,
                    'charge_item_id' => $item->id,
                ])
                ->log('Automatic add-on removed on the booking form');
        }
    }
}
