<?php

namespace App\Services\Commissions;

use App\Models\Reservation;
use App\Services\Channels\ChannexReservations;
use App\Services\Folio\ReservationRoomRevenue;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Channel manager commission: a reservation that came from Channex, with a due date on or after the channel manager
 * commission start date. With no start date, nothing is charged. The base is the folio's room revenue before tax,
 * including a cancellation or no-show fee staff post. The OTA or guest pays the property, so no money entries are written.
 * See docs/30-commission-fees/02-commission-rules.md#services.
 */
class ChannelManagerCommission implements CommissionRule
{
    public function __construct(
        private ChannexReservations $channexReservations,
        private ReservationRoomRevenue $roomRevenue,
        private PlatformSettings $platformSettings,
    ) {}

    /**
     * @return Builder<Reservation>
     */
    public function candidates(): Builder
    {
        if ($this->startsOn() === null) {
            return Reservation::query()->whereRaw('false');
        }

        return $this->channexReservations->constrain(Reservation::query());
    }

    public function appliesTo(Reservation $reservation, CarbonImmutable $dueDate): bool
    {
        $startsOn = $this->startsOn();

        return $startsOn !== null
            && $dueDate->greaterThanOrEqualTo($startsOn)
            && $this->channexReservations->isFromChannex($reservation);
    }

    public function base(Reservation $reservation): string
    {
        return $this->roomRevenue->forReservation($reservation);
    }

    private function startsOn(): ?CarbonImmutable
    {
        return $this->platformSettings->current()->channel_manager_commission_from;
    }
}
