<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationGuestData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Models\Reservation;
use App\Models\UnitGuest;
use Illuminate\Support\Collection;

/**
 * The rows of the Guests tab with two queries' worth of data: the booker when they do not stay, one row for each guest
 * on a unit, and one row for each unit with no guest.
 */
class ReservationGuestRows
{
    /**
     * @param  list<ReservationUnitOptionData>  $units
     * @return list<ReservationGuestData>
     */
    public function rows(Reservation $reservation, array $units, bool $canViewGuest): array
    {
        $options = collect($units)->keyBy('id');
        $occupants = UnitGuest::query()
            ->whereIn('reservation_unit_id', array_keys($options->all()))
            ->with('guest')
            ->orderBy('reservation_unit_id')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('reservation_unit_id');
        $booker = $reservation->booker;
        $bookerStays = $occupants->flatten()->contains(fn (UnitGuest $unitGuest): bool => $unitGuest->guest_id === $booker->id);
        $rows = [];

        if (! $bookerStays) {
            $rows[] = new ReservationGuestData($canViewGuest ? $booker->id : null, $booker->full_name, $canViewGuest ? $booker->phone : null, null, null, null, [__('Booker')]);
        }

        foreach ($options as $unitId => $unit) {
            /** @var Collection<int, UnitGuest> $guests */
            $guests = $occupants->get($unitId, collect());

            if ($guests->isEmpty()) {
                $rows[] = new ReservationGuestData(null, null, null, $unitId, $unit->label, $unit->status, []);

                continue;
            }

            foreach ($guests as $unitGuest) {
                $rows[] = new ReservationGuestData(
                    guestId: $canViewGuest ? $unitGuest->guest_id : null,
                    name: $unitGuest->guest->full_name,
                    phone: $canViewGuest ? $unitGuest->guest->phone : null,
                    unitId: $unitId,
                    unitLabel: $unit->label,
                    unitStatus: $unit->status,
                    roles: array_values(array_filter([
                        $unitGuest->guest_id === $booker->id ? __('Booker') : null,
                        $unitGuest->is_primary ? __('Primary') : null,
                    ])),
                );
            }
        }

        return $rows;
    }
}
