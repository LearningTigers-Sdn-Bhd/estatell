<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;

class ReleaseUnitRooms
{
    /**
     * Clear `room_id` and `bed_id` on every night of a unit, so its rooms and beds can be sold again. `CancelUnit`
     * and `MarkNoShow` call this inside their transaction. The nights and their prices stay as history, and the
     * returned ids go in the activity log. See docs/12-reservations/01-reservation-shape.md.
     *
     * @return array{room_ids: list<int>, bed_ids: list<int>} the distinct rooms and beds that were released
     */
    public function __invoke(ReservationUnit $unit): array
    {
        $nights = $unit->nights()->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))->get();

        $released = [
            'room_ids' => $nights->pluck('room_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all(),
            'bed_ids' => $nights->pluck('bed_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all(),
        ];

        $unit->nights()->update(['room_id' => null, 'bed_id' => null]);

        return $released;
    }
}
