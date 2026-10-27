<?php

namespace App\Services\Rooms;

use App\Enums\UnitStatus;
use App\Models\Room;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;

/**
 * Finds the stays a room, or one of its beds, still has. Read only.
 *
 * A night counts when it is assigned to the room or to one of its beds on or after the business date. A day use
 * has no nights, so its unreleased block on the room counts too. Before the property goes live there is no business
 * date, so every assigned night and block counts.
 */
class RoomAssignedNights
{
    public function __construct(private CurrentBusinessDate $businessDate) {}

    /**
     * The first date on or after the business date with a night assigned to the room or its beds, or null.
     */
    public function firstDate(Room $room): ?CarbonImmutable
    {
        $from = $this->businessDate->date($room->property);

        $night = UnitNight::query()
            ->where(fn ($query) => $query
                ->where('room_id', $room->id)
                ->orWhereIn('bed_id', $room->beds()->select('id')))
            ->when($from !== null, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->min('date');

        $dayUse = UnitDayUse::query()
            ->where('room_id', $room->id)
            ->whereNull('released_at')
            ->when($from !== null, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->min('date');

        $dates = array_filter([$night, $dayUse]);

        return $dates === [] ? null : CarbonImmutable::parse(min($dates));
    }

    /**
     * Whether a unit that holds inventory has a night from the first to the last night (both included) that a block
     * on the room, or on one of its beds, would take.
     *
     * Without a bed, a night on the room or on any of its beds counts. With a bed, a night on that bed or on the whole
     * room counts. A day use holds no night, so it does not count.
     */
    public function existBetween(Room $room, ?int $bedId, string $firstNight, string $lastNight): bool
    {
        return UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->whereBetween('unit_nights.date', [$firstNight, $lastNight])
            ->where(fn ($query) => $query
                ->where('unit_nights.room_id', $room->id)
                ->when(
                    $bedId === null,
                    fn ($query) => $query->orWhereIn('unit_nights.bed_id', $room->beds()->select('id')),
                    fn ($query) => $query->orWhere('unit_nights.bed_id', $bedId),
                ))
            ->exists();
    }
}
