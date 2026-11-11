<?php

namespace App\Services\Channels;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which reservations came from Channex: those a channel booking revision created or changed. Commission fees reads it.
 * See docs/30-commission-fees/02-commission-rules.md#services.
 */
class ChannexReservations
{
    public function isFromChannex(Reservation $reservation): bool
    {
        return DB::table('channel_booking_revisions')->where('reservation_id', $reservation->id)->exists();
    }

    /**
     * Limits a reservation query to reservations from Channex.
     *
     * @param  Builder<Reservation>  $reservations
     * @return Builder<Reservation>
     */
    public function constrain(Builder $reservations): Builder
    {
        return $reservations->whereExists(fn (QueryBuilder $query) => $query
            ->from('channel_booking_revisions')
            ->whereColumn('channel_booking_revisions.reservation_id', 'reservations.id'));
    }
}
