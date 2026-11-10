<?php

namespace App\Actions\Boats;

use App\Enums\BoatDirection;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Builder;

class SetSailingUnitTimes
{
    /**
     * Set the unit time that goes with a sailing's property-side time on the units of some trips: `arrival_time` for Boat
     * in, `departure_time` for Boat out. Only units that still take a trip in this direction are changed, so a guest
     * already checked in keeps their arrival time. Run it inside the caller's transaction; it locks the units in id
     * order. Returns the ids of the units that changed.
     *
     * @param  Builder<ReservationUnit>  $units  The units of the trips.
     * @param  string  $time  `HH:MM`.
     * @return list<int>
     */
    public function __invoke(Builder $units, BoatDirection $direction, string $time): array
    {
        $column = $direction === BoatDirection::In ? 'arrival_time' : 'departure_time';
        $changed = $units
            ->whereIn('status', array_map(fn ($status): string => $status->value, $direction->unitStatuses()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (ReservationUnit $unit): bool => substr((string) $unit->{$column}, 0, 5) !== $time);

        if ($changed->isNotEmpty()) {
            ReservationUnit::query()->whereKey($changed->modelKeys())->update([$column => $time.':00', 'updated_at' => now()]);
        }

        return $changed->modelKeys();
    }
}
