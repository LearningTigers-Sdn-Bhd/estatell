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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
        return $this->nightsForRoomTypes([$roomType], $arrival, $departure)[$roomType->id];
    }

    /**
     * The nights of many room types of one property, read in a fixed number of queries: active rooms, active beds
     * (only when a Bed room type is in the list), open blocks and booked unit nights are one query each. Every room
     * type gets a key, even with no units. A stay with no nights gives an empty list for each room type.
     *
     * @param  iterable<RoomType>  $roomTypes  Room types of one property
     * @return array<int, list<NightAvailability>> Nights by room type id
     */
    public function nightsForRoomTypes(iterable $roomTypes, CarbonInterface $arrival, CarbonInterface $departure): array
    {
        $roomTypes = collect($roomTypes)->keyBy('id');
        $first = $arrival->toImmutable()->startOfDay();
        $last = $departure->toImmutable()->startOfDay()->subDay();

        if ($roomTypes->isEmpty()) {
            return [];
        }

        if ($last->lt($first)) {
            return $roomTypes->map(fn (): array => [])->all();
        }

        $stay = Period::make($first, $last, Precision::DAY(), Boundaries::EXCLUDE_NONE());
        $units = $this->unitsByRoomForTypes($roomTypes);
        $blocks = $this->openBlocks($roomTypes->first()->property_id, array_merge(...array_map('array_keys', $units)), $stay);
        $booked = $this->bookedUnits($roomTypes->first()->property_id, $roomTypes->keys()->all(), $stay);
        $result = [];

        foreach ($roomTypes as $roomType) {
            $typeUnits = $units[$roomType->id];
            $blocked = $this->blockedFromBlocks($roomType, $typeUnits, $blocks, $stay);
            $total = array_sum(array_map('count', $typeUnits));
            $nights = [];

            for ($date = $first; $date->lte($last); $date = $date->addDay()) {
                $key = $date->toDateString();
                $nights[] = new NightAvailability($date, $total, count($blocked[$key] ?? []), $booked[$roomType->id][$key] ?? 0);
            }

            $result[$roomType->id] = $nights;
        }

        return $result;
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
        return $this->unitsByRoomForTypes(collect([$roomType->id => $roomType]))[$roomType->id];
    }

    /**
     * The blocked unit ids of each night of the stay.
     *
     * @param  array<int, list<int>>  $units
     * @return array<string, array<int, true>> Blocked unit ids by date
     */
    public function blockedUnits(RoomType $roomType, array $units, Period $stay): array
    {
        return $this->blockedFromBlocks($roomType, $units, $this->openBlocks($roomType->property_id, array_keys($units), $stay), $stay);
    }

    /**
     * The sellable unit ids of each active room, for each room type: two queries at most, however many room types.
     *
     * @param  Collection<int, RoomType>  $roomTypes  Keyed by room type id
     * @return array<int, array<int, list<int>>> Unit ids by room id, by room type id
     */
    private function unitsByRoomForTypes(Collection $roomTypes): array
    {
        $rooms = Room::query()
            ->where('property_id', $roomTypes->first()->property_id)
            ->whereIn('room_type_id', $roomTypes->keys()->all())
            ->active()
            ->get(['id', 'room_type_id']);
        $units = $roomTypes->map(fn (): array => [])->all();
        $roomTypeByRoom = [];

        foreach ($rooms as $room) {
            $roomTypeByRoom[$room->id] = $room->room_type_id;

            if ($roomTypes[$room->room_type_id]->sell_unit === SellUnit::Room) {
                $units[$room->room_type_id][$room->id] = [$room->id];
            } else {
                $units[$room->room_type_id][$room->id] = [];
            }
        }

        $bedRoomIds = array_keys(array_filter($roomTypeByRoom, fn (int $typeId): bool => $roomTypes[$typeId]->sell_unit === SellUnit::Bed));

        if ($bedRoomIds !== []) {
            foreach (Bed::query()->active()->whereIn('room_id', $bedRoomIds)->get(['id', 'room_id']) as $bed) {
                $units[$roomTypeByRoom[$bed->room_id]][$bed->room_id][] = $bed->id;
            }
        }

        return $units;
    }

    /**
     * The open blocks of the rooms that touch the stay.
     *
     * @param  list<int>  $roomIds
     * @return EloquentCollection<int, RoomBlock>
     */
    private function openBlocks(int $propertyId, array $roomIds, Period $stay): EloquentCollection
    {
        return RoomBlock::query()
            ->where('property_id', $propertyId)
            ->whereIn('room_id', $roomIds)
            ->open()
            ->overlapping($stay->includedStart()->format('Y-m-d'), $stay->includedEnd()->format('Y-m-d'))
            ->get();
    }

    /**
     * The blocked unit ids of each night, from blocks that may cover rooms of other room types.
     *
     * @param  array<int, list<int>>  $units
     * @param  EloquentCollection<int, RoomBlock>  $blocks
     * @return array<string, array<int, true>> Blocked unit ids by date
     */
    private function blockedFromBlocks(RoomType $roomType, array $units, EloquentCollection $blocks, Period $stay): array
    {
        $blocked = [];

        foreach ($blocks as $block) {
            if (! isset($units[$block->room_id])) {
                continue;
            }

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
     * @param  list<int>  $roomTypeIds
     * @return array<int, array<string, int>> Booked units by date, by room type id
     */
    private function bookedUnits(int $propertyId, array $roomTypeIds, Period $stay): array
    {
        $booked = [];

        $rows = UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $propertyId)
            ->whereIn('reservation_units.room_type_id', $roomTypeIds)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereBetween('unit_nights.date', [$stay->includedStart()->format('Y-m-d'), $stay->includedEnd()->format('Y-m-d')])
            ->groupBy('reservation_units.room_type_id', 'unit_nights.date')
            ->selectRaw('reservation_units.room_type_id as room_type_id, unit_nights.date as night, count(*) as booked')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $booked[(int) $row->room_type_id][(string) $row->night] = (int) $row->booked;
        }

        return $booked;
    }
}
