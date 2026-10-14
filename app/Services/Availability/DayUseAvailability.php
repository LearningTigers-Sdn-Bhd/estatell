<?php

namespace App\Services\Availability;

use App\Data\Reservations\FreeUnitData;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rooms of a room type that are free on a date between two times. A room is free when it is active, not in an open
 * room block on that date, and none of these overlaps the hours: an overnight guest who stayed the night before and
 * leaves after the start (until the unit's `departure_time`), an overnight guest who has the room on the night of the
 * date (from the unit's `arrival_time`), a guest who stays through the date (the whole day), and another live day use
 * (its `starts_at` to `ends_at`). A day use never changes overnight availability. It reads only.
 * See docs/12-reservations/10-day-use.md.
 */
class DayUseAvailability
{
    /**
     * @param  string  $startsAt  `H:i` or `H:i:s`
     * @param  string  $endsAt  `H:i` or `H:i:s`
     * @param  int|null  $exceptUnitId  A reservation unit whose own block does not count.
     * @return list<FreeUnitData>
     */
    public function freeRooms(RoomType $roomType, CarbonInterface $date, string $startsAt, string $endsAt, ?int $exceptUnitId = null): array
    {
        if ($roomType->sell_unit !== SellUnit::Room) {
            return [];
        }

        $rooms = $this->activeRooms($roomType);
        $busy = $this->busyRoomIds($roomType, $rooms->modelKeys(), $date, $startsAt, $endsAt, $exceptUnitId);

        return array_values($rooms
            ->reject(fn (Room $room): bool => isset($busy[$room->id]))
            ->map(fn (Room $room): FreeUnitData => new FreeUnitData($room->id, $room->number))
            ->values()
            ->all());
    }

    /**
     * Whether one room is an active room of the room type and free for the hours.
     */
    public function isFree(RoomType $roomType, int $roomId, CarbonInterface $date, string $startsAt, string $endsAt, ?int $exceptUnitId = null): bool
    {
        return array_any($this->freeRooms($roomType, $date, $startsAt, $endsAt, $exceptUnitId), fn (FreeUnitData $room): bool => $room->id === $roomId);
    }

    /**
     * Whether a live day use block in the room overlaps the hours on the date, for the late check-out of an overnight
     * guest in that room.
     *
     * @param  string  $from  `H:i` or `H:i:s`
     * @param  string  $until  `H:i` or `H:i:s`
     */
    public function hasLiveBlockBetween(int $propertyId, int $roomId, CarbonInterface $date, string $from, string $until): bool
    {
        return UnitDayUse::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')
            ->where('reservation_units.property_id', $propertyId)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->where('unit_day_uses.room_id', $roomId)
            ->whereNull('unit_day_uses.released_at')
            ->whereDate('unit_day_uses.date', $date->toDateString())
            ->where('unit_day_uses.starts_at', '<', $this->normalize($until))
            ->where('unit_day_uses.ends_at', '>', $this->normalize($from))
            ->exists();
    }

    /**
     * @return Collection<int, Room>
     */
    private function activeRooms(RoomType $roomType): Collection
    {
        return Room::query()
            ->where('property_id', $roomType->property_id)
            ->where('room_type_id', $roomType->id)
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $roomIds
     * @return array<int, true> Busy room ids.
     */
    private function busyRoomIds(RoomType $roomType, array $roomIds, CarbonInterface $date, string $startsAt, string $endsAt, ?int $exceptUnitId): array
    {
        if ($roomIds === []) {
            return [];
        }

        $day = $date->toDateString();
        $start = $this->normalize($startsAt);
        $end = $this->normalize($endsAt);
        $busy = [];

        $blocked = RoomBlock::query()
            ->where('property_id', $roomType->property_id)
            ->whereIn('room_id', $roomIds)
            ->whereNull('bed_id')
            ->open()
            ->overlapping($day, $day)
            ->pluck('room_id');

        foreach ($blocked as $roomId) {
            $busy[$roomId] = true;
        }

        $nights = UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $roomType->property_id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->where('reservation_units.stay_type', StayType::Overnight->value)
            ->whereIn('unit_nights.room_id', $roomIds)
            ->whereBetween('unit_nights.date', [$date->subDay()->toDateString(), $day])
            ->when($exceptUnitId !== null, fn ($query) => $query->where('reservation_units.id', '!=', $exceptUnitId))
            ->get(['unit_nights.reservation_unit_id', 'unit_nights.room_id', 'unit_nights.date', 'reservation_units.arrival_time', 'reservation_units.departure_time']);

        $before = $nights->filter(fn (UnitNight $night): bool => $night->date->toDateString() !== $day)->groupBy('room_id');
        $onDate = $nights->filter(fn (UnitNight $night): bool => $night->date->toDateString() === $day)->groupBy('room_id');

        foreach ($onDate as $roomId => $rows) {
            foreach ($rows as $night) {
                $stayedThrough = ($before->get($roomId) ?? collect())->contains('reservation_unit_id', $night->reservation_unit_id);

                if ($stayedThrough || $end > $this->normalize($night->arrival_time)) {
                    $busy[$roomId] = true;
                }
            }
        }

        foreach ($before as $roomId => $rows) {
            foreach ($rows as $night) {
                $stayedThrough = ($onDate->get($roomId) ?? collect())->contains('reservation_unit_id', $night->reservation_unit_id);

                if (! $stayedThrough && $start < $this->normalize($night->departure_time)) {
                    $busy[$roomId] = true;
                }
            }
        }

        $blocks = UnitDayUse::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')
            ->where('reservation_units.property_id', $roomType->property_id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereIn('unit_day_uses.room_id', $roomIds)
            ->whereNull('unit_day_uses.released_at')
            ->whereDate('unit_day_uses.date', $day)
            ->where('unit_day_uses.starts_at', '<', $end)
            ->where('unit_day_uses.ends_at', '>', $start)
            ->when($exceptUnitId !== null, fn ($query) => $query->where('reservation_units.id', '!=', $exceptUnitId))
            ->pluck('unit_day_uses.room_id');

        foreach ($blocks as $roomId) {
            $busy[$roomId] = true;
        }

        return $busy;
    }

    private function normalize(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
