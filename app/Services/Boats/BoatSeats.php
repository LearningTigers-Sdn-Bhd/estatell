<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\UnitStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Counts the seats taken on departures for one date. Every guest takes a seat, children and infants included. A unit
 * takes seats while it holds inventory: Confirmed, CheckedIn and CheckedOut. Reads only.
 */
class BoatSeats
{
    /**
     * Seats taken on each departure for one date. A departure with no trip on that date has 0.
     *
     * @param  list<int>  $departureIds
     * @return array<int, int> Seats by departure id.
     */
    public function forDate(BoatDirection $direction, CarbonInterface $date, array $departureIds): array
    {
        if ($departureIds === []) {
            return [];
        }

        $taken = [];

        $rows = DB::table('unit_boat_trips')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
            ->whereIn('unit_boat_trips.boat_departure_id', $departureIds)
            ->where('unit_boat_trips.direction', $direction->value)
            ->where("reservation_units.{$direction->tripDateColumn()}", $date->toDateString())
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut]))
            ->groupBy('unit_boat_trips.boat_departure_id')
            ->selectRaw('unit_boat_trips.boat_departure_id, sum(reservation_units.adults + reservation_units.children) as seats')
            ->get();

        foreach ($rows as $row) {
            $taken[(int) $row->boat_departure_id] = (int) $row->seats;
        }

        return collect($departureIds)
            ->mapWithKeys(fn (int $id): array => [$id => $taken[$id] ?? 0])
            ->all();
    }
}
