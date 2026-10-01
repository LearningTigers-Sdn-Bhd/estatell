<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Support\Facades\DB;

class SyncReservationStatus
{
    /**
     * Store the status derived from the reservation's units (`ReservationStatus::fromUnits()`).
     * Every unit transition Action calls this inside its own transaction after it changes a unit.
     *
     * Returns true when the status changed. The reservation is locked and its units are read again,
     * so a stale model never decides the status.
     */
    public function __invoke(Reservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation): bool {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            $status = ReservationStatus::fromUnits(
                ReservationUnit::query()->where('reservation_id', $locked->id)->pluck('status'),
            );

            $changed = $locked->status !== $status;

            if ($changed) {
                $locked->status = $status;
                $locked->save();
            }

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });
    }
}
