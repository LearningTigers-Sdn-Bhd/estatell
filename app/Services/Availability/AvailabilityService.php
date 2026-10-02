<?php

namespace App\Services\Availability;

use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitNight;
use Carbon\CarbonInterface;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;

/**
 * How many units of a room type can still be sold on a night, calculated from the source data. It reads only and
 * stores nothing.
 *
 * - A Room room type counts its active rooms. A Bed room type counts the active beds of its active rooms.
 * - An open room block takes its nights off. A whole-room block removes the room, or every active bed of it. A bed
 *   block removes that bed. A unit blocked twice on one night counts once.
 * - A booked unit takes its room or bed off sale on each of its nights while it is Confirmed, CheckedIn or CheckedOut.
 *   One unit is one room or one bed, so each night row counts once for both sell units. Cancelled and no-show units
 *   release their nights.
 *
 * Stop-sell and the other selling restrictions belong to a plan, so `RestrictionCheck` reports them.
 */
class AvailabilityService
{
    /**
     * One row for each night from arrival up to, not including, departure.
     *
     * @return list<NightAvailability>
     */
    public function nights(RoomType $roomType, CarbonInterface $arrival, CarbonInterface $departure): array
    {
        $first = $arrival->toImmutable()->startOfDay();
        $last = $departure->toImmutable()->startOfDay()->subDay();

        if ($last->lt($first)) {
            return [];
        }

        $stay = Period::make($first, $last, Precision::DAY(), Boundaries::EXCLUDE_NONE());
        $units = $this->unitsByRoom($roomType);
        $blocked = $this->blockedUnits($roomType, $units, $stay);
        $booked = $this->bookedUnits($roomType, $stay);
        $total = array_sum(array_map('count', $units));
        $nights = [];

        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $key = $date->toDateString();
            $nights[] = new NightAvailability($date, $total, count($blocked[$key] ?? []), $booked[$key] ?? 0);
        }

        return $nights;
    }

    /**
     * The fewest units free on any night of the stay. Zero for a stay with no nights.
     */
    public function availableForStay(RoomType $roomType, CarbonInterface $arrival, CarbonInterface $departure): int
    {
        $nights = $this->nights($roomType, $arrival, $departure);

        return $nights === [] ? 0 : min(array_map(fn (NightAvailability $night): int => $night->available, $nights));
    }

    /**
     * The sellable unit ids of each active room of the room type: the room itself, or its active beds.
     *
     * @return array<int, list<int>> Unit ids by room id
     */
    public function unitsByRoom(RoomType $roomType): array
    {
        $roomIds = Room::query()
            ->where('property_id', $roomType->property_id)
            ->where('room_type_id', $roomType->id)
            ->active()
            ->pluck('id')
            ->all();

        if ($roomType->sell_unit === SellUnit::Room) {
            return array_combine($roomIds, array_map(fn (int $id): array => [$id], $roomIds)) ?: [];
        }

        $units = array_fill_keys($roomIds, []);

        foreach (Bed::query()->active()->whereIn('room_id', $roomIds)->get(['id', 'room_id']) as $bed) {
            $units[$bed->room_id][] = $bed->id;
        }

        return $units;
    }

    /**
     * The blocked unit ids of each night of the stay.
     *
     * @param  array<int, list<int>>  $units
     * @return array<string, array<int, true>> Blocked unit ids by date
     */
    public function blockedUnits(RoomType $roomType, array $units, Period $stay): array
    {
        $blocks = RoomBlock::query()
            ->where('property_id', $roomType->property_id)
            ->whereIn('room_id', array_keys($units))
            ->open()
            ->overlapping($stay->includedStart()->format('Y-m-d'), $stay->includedEnd()->format('Y-m-d'))
            ->get();

        $blocked = [];

        foreach ($blocks as $block) {
            $overlap = Period::make($block->starts_on->toImmutable(), $block->ends_on->toImmutable(), Precision::DAY(), Boundaries::EXCLUDE_NONE())->overlap($stay);

            if ($overlap === null) {
                continue;
            }

            $blockedIds = $block->bed_id === null ? $units[$block->room_id] : array_intersect([$block->bed_id], $units[$block->room_id]);

            if ($roomType->sell_unit === SellUnit::Room && $block->bed_id !== null) {
                continue;
            }

            foreach ($overlap as $date) {
                foreach ($blockedIds as $id) {
                    $blocked[$date->format('Y-m-d')][$id] = true;
                }
            }
        }

        return $blocked;
    }

    /**
     * The units booked on each night of the stay: one per `unit_nights` row of a unit that holds inventory.
     *
     * @return array<string, int> Booked units by date
     */
    private function bookedUnits(RoomType $roomType, Period $stay): array
    {
        return UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $roomType->property_id)
            ->where('reservation_units.room_type_id', $roomType->id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereBetween('unit_nights.date', [$stay->includedStart()->format('Y-m-d'), $stay->includedEnd()->format('Y-m-d')])
            ->groupBy('unit_nights.date')
            ->selectRaw('unit_nights.date as night, count(*) as booked')
            ->pluck('booked', 'night')
            ->map(fn (mixed $booked): int => (int) $booked)
            ->all();
    }
}
