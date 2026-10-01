<?php

namespace App\Actions\Reservations;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\UnitGuest;
use Illuminate\Support\Facades\DB;

class RepointGuestReservations
{
    /**
     * Point a merged guest's reservations at the survivor: the booker of a reservation and the occupant of a unit.
     * When both guests occupy the same unit, the merged guest's row is removed and the survivor's row takes
     * over the primary flag if the merged guest held it.
     *
     * Called by `MergeGuests`, inside its transaction.
     *
     * @return array{reservations: int, unit_guests: int, duplicates_removed: int}
     */
    public function __invoke(Guest $survivor, Guest $merged): array
    {
        return DB::transaction(function () use ($survivor, $merged): array {
            $reservations = Reservation::query()
                ->where('booker_guest_id', $merged->id)
                ->update(['booker_guest_id' => $survivor->id]);

            $sharedUnitIds = UnitGuest::query()
                ->where('guest_id', $survivor->id)
                ->whereIn('reservation_unit_id', UnitGuest::query()->select('reservation_unit_id')->where('guest_id', $merged->id))
                ->pluck('reservation_unit_id')
                ->all();

            $primaryUnitIds = UnitGuest::query()
                ->where('guest_id', $merged->id)
                ->where('is_primary', true)
                ->whereIn('reservation_unit_id', $sharedUnitIds)
                ->pluck('reservation_unit_id')
                ->all();

            $duplicates = UnitGuest::query()
                ->where('guest_id', $merged->id)
                ->whereIn('reservation_unit_id', $sharedUnitIds)
                ->delete();

            UnitGuest::query()
                ->where('guest_id', $survivor->id)
                ->whereIn('reservation_unit_id', $primaryUnitIds)
                ->update(['is_primary' => true]);

            $unitGuests = UnitGuest::query()
                ->where('guest_id', $merged->id)
                ->update(['guest_id' => $survivor->id]);

            return [
                'reservations' => $reservations,
                'unit_guests' => $unitGuests,
                'duplicates_removed' => $duplicates,
            ];
        });
    }
}
