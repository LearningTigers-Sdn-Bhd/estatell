<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;

class ReleaseUnitRooms
{
    /**
     * Clear `room_id` and `bed_id` on every night of a unit, so its rooms and beds can be sold again. `CancelUnit`
     * and `MarkNoShow` call this inside their transaction. The nights and their prices stay as history, and the
     * returned ids go in the activity log. A day use unit keeps its `room_id` and gets `released_at = now()` on its
     * block, so the exclusion constraint no longer holds the room and `DayUseAvailability` sees it free. See docs/12-reservations/01-reservation-shape.md.
     *
     * @return array{room_ids: list<int>, bed_ids: list<int>} the distinct rooms and beds that were released
     */
    public function __invoke(ReservationUnit $unit): array
    {
        $dayUse = $unit->dayUse;

        if ($dayUse !== null) {
            $dayUse->released_at = now();
            $dayUse->save();

            return ['room_ids' => $dayUse->room_id === null ? [] : [$dayUse->room_id], 'bed_ids' => []];
        }

        $nights = $unit->nights()->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))->get();

        $released = [
            'room_ids' => array_values($nights->pluck('room_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all()),
            'bed_ids' => array_values($nights->pluck('bed_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all()),
        ];

        $unit->nights()->update(['room_id' => null, 'bed_id' => null]);

        return $released;
    }
}
