<?php

namespace App\Services\Availability;

use App\Data\Reservations\FreeUnitData;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitNight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;

/**
 * The rooms or beds of a room type that are free on every night of a stay, for the Room / Bed picker and for the
 * check inside `AssignRoom` and `AssignBed`. A unit is not free on a night when a room block covers it, or when a
 * unit that holds inventory already has it. It reads only and stores nothing.
 */
class FreeUnits
{
    public function __construct(private AvailabilityService $availability) {}

    /**
     * Rooms for a Room room type, beds for a Bed room type, in the order of the property's room list.
     *
     * @param  int|null  $exceptUnitId  A reservation unit whose own assignment does not count, so it can keep or change its pick.
     * @return list<FreeUnitData>
     */
    public function forStay(RoomType $roomType, CarbonInterface $arrival, CarbonInterface $departure, ?int $exceptUnitId = null): array
    {
        $stay = $this->stay($arrival, $departure);

        if ($stay === null) {
            return [];
        }

        $unitsByRoom = $this->availability->unitsByRoom($roomType);
        $taken = $this->takenNights($roomType, $unitsByRoom, $stay, $exceptUnitId);
        $isBed = $roomType->sell_unit === SellUnit::Bed;
        $rooms = Room::query()->whereIn('id', array_keys($unitsByRoom))->orderBy('position')->orderBy('id')->get();
        $beds = $isBed ? Bed::query()->whereIn('room_id', array_keys($unitsByRoom))->get()->keyBy('id') : collect();
        $free = [];

        foreach ($rooms as $room) {
            foreach ($unitsByRoom[$room->id] as $unitId) {
                if (! isset($taken[$unitId])) {
                    $free[] = new FreeUnitData($unitId, $isBed ? $room->number.' · '.$beds[$unitId]->label : $room->number);
                }
            }
        }

        return $free;
    }

    /**
     * Whether the room or bed is one of the room type's active units.
     */
    public function isSellable(RoomType $roomType, int $unitId): bool
    {
        return array_any($this->availability->unitsByRoom($roomType), fn (array $ids): bool => in_array($unitId, $ids, true));
    }

    /**
     * The first night of the stay on which the room or bed is not free, or null when it is free on every night.
     */
    public function firstTakenNight(RoomType $roomType, int $unitId, CarbonInterface $arrival, CarbonInterface $departure, ?int $exceptUnitId = null): ?CarbonImmutable
    {
        $stay = $this->stay($arrival, $departure);

        if ($stay === null) {
            return null;
        }

        $dates = $this->takenNights($roomType, $this->availability->unitsByRoom($roomType), $stay, $exceptUnitId)[$unitId] ?? [];

        return $dates === [] ? null : CarbonImmutable::parse(min($dates));
    }

    private function stay(CarbonInterface $arrival, CarbonInterface $departure): ?Period
    {
        $first = $arrival->toImmutable()->startOfDay();
        $last = $departure->toImmutable()->startOfDay()->subDay();

        return $last->lt($first) ? null : Period::make($first, $last, Precision::DAY(), Boundaries::EXCLUDE_NONE());
    }

    /**
     * The nights of the stay on which each room or bed is blocked or already assigned, by unit id.
     *
     * @param  array<int, list<int>>  $unitsByRoom
     * @return array<int, list<string>>
     */
    private function takenNights(RoomType $roomType, array $unitsByRoom, Period $stay, ?int $exceptUnitId): array
    {
        $taken = [];

        foreach ($this->availability->blockedUnits($roomType, $unitsByRoom, $stay) as $date => $ids) {
            foreach (array_keys($ids) as $id) {
                $taken[$id][] = $date;
            }
        }

        $assigned = UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $roomType->property_id)
            ->where('reservation_units.room_type_id', $roomType->id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereBetween('unit_nights.date', [$stay->includedStart()->format('Y-m-d'), $stay->includedEnd()->format('Y-m-d')])
            ->when($exceptUnitId !== null, fn ($query) => $query->where('reservation_units.id', '!=', $exceptUnitId))
            ->where(fn ($query) => $query->whereNotNull('unit_nights.room_id')->orWhereNotNull('unit_nights.bed_id'))
            ->get(['unit_nights.room_id', 'unit_nights.bed_id', 'unit_nights.date']);

        foreach ($assigned as $night) {
            $taken[$night->bed_id ?? $night->room_id][] = $night->date->toDateString();
        }

        return $taken;
    }
}
