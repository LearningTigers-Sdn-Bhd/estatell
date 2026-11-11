<?php

namespace App\Services\Commissions;

use App\Models\Reservation;
use App\Services\Channels\ChannexReservations;
use App\Services\Folio\ReservationRoomRevenue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Channel manager commission: a reservation that came from Channex. The base is the folio's room revenue before tax,
 * including a cancellation or no-show fee staff post. The OTA or guest pays the property, so no money entries are written.
 * See docs/30-commission-fees/02-commission-rules.md#services.
 */
class ChannelManagerCommission implements CommissionRule
{
    public function __construct(
        private ChannexReservations $channexReservations,
        private ReservationRoomRevenue $roomRevenue,
    ) {}

    /**
     * @return Builder<Reservation>
     */
    public function candidates(): Builder
    {
        return $this->channexReservations->constrain(Reservation::query());
    }

    public function appliesTo(Reservation $reservation): bool
    {
        return $this->channexReservations->isFromChannex($reservation);
    }

    public function base(Reservation $reservation): string
    {
        return $this->roomRevenue->forReservation($reservation);
    }
}
