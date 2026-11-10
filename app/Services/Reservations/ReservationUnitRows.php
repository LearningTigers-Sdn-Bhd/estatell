<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationUnitOptionData;
use App\Data\Reservations\ReservationUnitRowData;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The rows of the All view on the Units tab, in three queries however many units there are (the rate plans, the
 * nights and the day use blocks), and two more for the boat trips and their departures when `$boats` is on. It builds on the unit list of `ReservationOverview`, so the room and the primary
 * guest are not read again. See docs/12-reservations/06-reservation-page.md#all-and-grouped-views.
 */
class ReservationUnitRows
{
    /**
     * @param  EloquentCollection<int, ReservationUnit>  $units  With `roomType` loaded
     * @param  list<ReservationUnitOptionData>  $options  The unit list of the same units
     * @param  bool  $boats  Whether the Boats feature is on, so the rows carry the boat times
     * @return list<ReservationUnitRowData>
     */
    public function rows(EloquentCollection $units, array $options, bool $boats = false): array
    {
        $units->load(['ratePlan', 'nights', 'dayUse', ...($boats ? ['boatTrips.boatDeparture'] : [])]);
        $byId = collect($options)->keyBy('id');

        return array_values($units
            ->filter(fn (ReservationUnit $unit): bool => $byId->has($unit->id))
            ->map(fn (ReservationUnit $unit): ReservationUnitRowData => ReservationUnitRowData::fromModel($unit, $byId[$unit->id]))
            ->all());
    }
}
