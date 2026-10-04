<?php

namespace App\Services\Reservations;

use App\Enums\UnitStatus;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * The room total of a reservation: the sum of `unit_nights.amount` of its live units (not Cancelled or NoShow), as a
 * decimal string with two places. It writes nothing.
 */
class StayTotal
{
    public function forReservation(Reservation $reservation): string
    {
        return (string) DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.reservation_id', $reservation->id)
            ->whereNotIn('reservation_units.status', [UnitStatus::Cancelled->value, UnitStatus::NoShow->value])
            ->selectRaw('COALESCE(SUM(unit_nights.amount), 0)::numeric(14, 2) AS total')
            ->value('total');
    }
}
