<?php

namespace App\Services\Availability;

use App\Data\Reservations\FreeUnitData;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\PropertySetting;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;

/**
 * The rooms or beds of a room type that are free on every night of a stay, for the Room / Bed picker and for the
 * check inside `AssignRoom` and `AssignBed`. A unit is not free on a night when a room block covers it, or when a
 * unit that holds inventory already has it. A live day use also takes its room (see `takenNights()`). It reads only and
 * stores nothing.
 *
 * `$arrivalTime` and `$departureTime` (`H:i` or `H:i:s`) are the unit's own times, which decide whether a day use on
 * the arrival or departure date overlaps the stay. Without them the property check-in and check-out times count, as
 * for a new booking or a unit with no times yet. See docs/12-reservations/10-day-use.md.
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
    public function forStay(RoomType $roomType, CarbonInterface $arrival, CarbonInterface $departure, ?int $exceptUnitId = null, ?string $arrivalTime = null, ?string $departureTime = null): array
    {
        $stay = $this->stay($arrival, $departure);

        if ($stay === null) {
            return [];
        }

        $unitsByRoom = $this->availability->unitsByRoom($roomType);
        $taken = $this->takenNights($roomType, $unitsByRoom, $stay, $exceptUnitId, $arrivalTime, $departureTime);
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
    public function firstTakenNight(RoomType $roomType, int $unitId, CarbonInterface $arrival, CarbonInterface $departure, ?int $exceptUnitId = null, ?string $arrivalTime = null, ?string $departureTime = null): ?CarbonImmutable
    {
        $stay = $this->stay($arrival, $departure);

        if ($stay === null) {
            return null;
        }

        $dates = $this->takenNights($roomType, $this->availability->unitsByRoom($roomType), $stay, $exceptUnitId, $arrivalTime, $departureTime)[$unitId] ?? [];

        return $dates === [] ? null : CarbonImmutable::parse(min($dates));
    }

    /**
     * For each room or bed of the room type, the first night from which it is free on every night to the departure:
     * `$from` when it is free for all of them, the night after its last taken night otherwise. A room or bed taken on
     * the last night is left out. The Timeline reads this once when a drag starts, so a drop on a later night needs no
     * new request.
     *
     * @param  int|null  $exceptUnitId  A reservation unit whose own assignment does not count.
     * @return array<int, string> The first free night (`Y-m-d`) by room or bed id, in the order of the property's room list.
     */
    public function freeFrom(RoomType $roomType, CarbonInterface $from, CarbonInterface $departure, ?int $exceptUnitId = null, ?string $arrivalTime = null, ?string $departureTime = null): array
    {
        $stay = $this->stay($from, $departure);

        if ($stay === null) {
            return [];
        }

        $unitsByRoom = $this->availability->unitsByRoom($roomType);
        $taken = $this->takenNights($roomType, $unitsByRoom, $stay, $exceptUnitId, $arrivalTime, $departureTime);
        $lastNight = $stay->includedEnd()->format('Y-m-d');
        $rooms = Room::query()->whereIn('id', array_keys($unitsByRoom))->orderBy('position')->orderBy('id')->pluck('id');
        $free = [];

        foreach ($rooms as $roomId) {
            foreach ($unitsByRoom[$roomId] as $unitId) {
                $lastTaken = isset($taken[$unitId]) ? max($taken[$unitId]) : null;

                if ($lastTaken === null) {
                    $free[$unitId] = $stay->includedStart()->format('Y-m-d');
                } elseif ($lastTaken < $lastNight) {
                    $free[$unitId] = CarbonImmutable::parse($lastTaken)->addDay()->toDateString();
                }
            }
        }

        return $free;
    }

    private function stay(CarbonInterface $arrival, CarbonInterface $departure): ?Period
    {
        $first = $arrival->toImmutable()->startOfDay();
        $last = $departure->toImmutable()->startOfDay()->subDay();

        return $last->lt($first) ? null : Period::make($first, $last, Precision::DAY(), Boundaries::EXCLUDE_NONE());
    }

    /**
     * The nights of the stay on which each room or bed is blocked or already assigned, by unit id. A live day use (not
     * released, on a unit that holds inventory) takes its room: on the arrival date when it ends after the arrival time,
     * on the departure date when it starts before the departure time (counted on the last night), and on any date in
     * between. Beds are never taken by a day use.
     *
     * @param  array<int, list<int>>  $unitsByRoom
     * @return array<int, list<string>>
     */
    private function takenNights(RoomType $roomType, array $unitsByRoom, Period $stay, ?int $exceptUnitId, ?string $arrivalTime, ?string $departureTime): array
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
            $taken[(int) ($night->bed_id ?? $night->room_id)][] = $night->date->toDateString();
        }

        foreach ($this->takenByDayUse($roomType, $unitsByRoom, $stay, $exceptUnitId, $arrivalTime, $departureTime) as $roomId => $dates) {
            $taken[$roomId] = [...($taken[$roomId] ?? []), ...$dates];
        }

        return $taken;
    }

    /**
     * @param  array<int, list<int>>  $unitsByRoom
     * @return array<int, list<string>> The nights taken by live day use blocks, by room id.
     */
    private function takenByDayUse(RoomType $roomType, array $unitsByRoom, Period $stay, ?int $exceptUnitId, ?string $arrivalTime, ?string $departureTime): array
    {
        if ($roomType->sell_unit !== SellUnit::Room || $unitsByRoom === []) {
            return [];
        }

        $first = $stay->includedStart()->format('Y-m-d');
        $last = $stay->includedEnd()->format('Y-m-d');
        $departureDate = CarbonImmutable::parse($last)->addDay()->toDateString();
        $settings = PropertySetting::query()->where('property_id', $roomType->property_id)->first();
        $arrives = $this->normalize($arrivalTime ?? $settings->check_in_time ?? '15:00');
        $departs = $this->normalize($departureTime ?? $settings->check_out_time ?? '11:00');
        $blocks = UnitDayUse::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')
            ->where('reservation_units.property_id', $roomType->property_id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereIn('unit_day_uses.room_id', array_keys($unitsByRoom))
            ->whereNull('unit_day_uses.released_at')
            ->whereBetween('unit_day_uses.date', [$first, $departureDate])
            ->when($exceptUnitId !== null, fn ($query) => $query->where('reservation_units.id', '!=', $exceptUnitId))
            ->get(['unit_day_uses.room_id', 'unit_day_uses.date', 'unit_day_uses.starts_at', 'unit_day_uses.ends_at']);
        $taken = [];

        foreach ($blocks as $block) {
            $date = $block->date->toDateString();

            if ($date === $first) {
                $overlaps = $block->ends_at > $arrives;
                $night = $date;
            } elseif ($date === $departureDate) {
                $overlaps = $block->starts_at < $departs;
                $night = $last;
            } else {
                $overlaps = true;
                $night = $date;
            }

            if ($overlaps) {
                $taken[(int) $block->room_id][] = $night;
            }
        }

        return $taken;
    }

    private function normalize(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
