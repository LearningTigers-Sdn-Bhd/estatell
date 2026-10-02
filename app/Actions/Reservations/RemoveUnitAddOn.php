<?php

namespace App\Actions\Reservations;

use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveUnitAddOn
{
    /**
     * Delete one add-on row and log it. Returns false, writing and logging nothing, when the row is already gone or
     * the unit is not Confirmed or CheckedIn: add-ons of other units stay as history.
     * See docs/12-reservations/04-add-ons.md.
     */
    public function __invoke(User $actor, UnitAddOn $addOn): bool
    {
        return DB::transaction(function () use ($actor, $addOn): bool {
            $unit = ReservationUnit::query()->lockForUpdate()->find($addOn->reservation_unit_id);
            $locked = $unit === null ? null : $unit->addOns()->lockForUpdate()->find($addOn->id);

            if ($unit === null || $locked === null || ! in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return false;
            }

            $locked->delete();

            activity()
                ->performedOn($unit->reservation)
                ->causedBy($actor)
                ->event('reservation.add_on_removed')
                ->withProperties([
                    'property_id' => $unit->property_id,
                    'reservation_unit_id' => $unit->id,
                    'charge_item_id' => $locked->charge_item_id,
                    'quantity' => $locked->quantity,
                    'unit_amount' => $locked->unit_amount,
                    'service_date' => $locked->service_date?->toDateString(),
                ])
                ->log('Add-on removed from unit');

            return true;
        });
    }
}
