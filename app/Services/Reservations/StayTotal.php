<?php

namespace App\Services\Reservations;

use App\Enums\UnitStatus;
use App\Models\Reservation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The room total of a reservation: the sum of `unit_nights.amount` and `unit_day_uses.amount` of its live units (not Cancelled or NoShow), as a
 * decimal string with two places. It writes nothing.
 */
class StayTotal
{
    public function forReservation(Reservation $reservation): string
    {
        $live = [UnitStatus::Cancelled->value, UnitStatus::NoShow->value];
        $nights = (string) DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.reservation_id', $reservation->id)
            ->whereNotIn('reservation_units.status', $live)
            ->selectRaw('COALESCE(SUM(unit_nights.amount), 0)::numeric(14, 2) AS total')
            ->value('total');
        $dayUse = (string) DB::table('unit_day_uses')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')
            ->where('reservation_units.reservation_id', $reservation->id)
            ->whereNotIn('reservation_units.status', $live)
            ->selectRaw('COALESCE(SUM(unit_day_uses.amount), 0)::numeric(14, 2) AS total')
            ->value('total');

        return BigDecimal::of($nights)->plus($dayUse)->toScale(2)->__toString();
    }
}
