<?php

namespace App\Actions\Boats;

use App\Enums\BoatMode;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;

class ResyncBoatFares
{
    public function __construct(private SyncBoatFare $syncBoatFare) {}

    /**
     * Write the fare rows of each scheduled boat trip of a unit again, so a per-guest fare follows a new party. A trip
     * whose fare is already charged is left alone, so the fare is never charged twice. Returns the refusal code of
     * `SyncBoatFare`, or null. Call it with the unit row locked, inside the caller's transaction.
     */
    public function __invoke(User $actor, ReservationUnit $unit): ?string
    {
        $trips = UnitBoatTrip::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('mode', BoatMode::Scheduled)
            ->with(['boatDeparture', 'addOns.unitAddOn'])
            ->orderBy('id')
            ->get();

        foreach ($trips as $trip) {
            if ($trip->addOns->contains(fn ($link): bool => $link->unitAddOn->folio_line_id !== null)) {
                continue;
            }

            $result = ($this->syncBoatFare)($actor, $unit, $trip, $trip->boatDeparture);

            if ($result->isRefused()) {
                return $result->refusal;
            }
        }

        return null;
    }
}
