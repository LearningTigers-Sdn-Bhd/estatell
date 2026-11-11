<?php

namespace App\Services\Commissions;

use App\Enums\BookingSourceType;
use App\Models\Reservation;
use App\Services\BookingEngine\OnlineBookingMoney;
use App\Services\Folio\ReservationRoomRevenue;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Booking engine commission: a reservation on the Booking Engine source whose payment Estatell collected. The base is the
 * folio's room revenue before tax, capped at the room total the guest booked, so a night added later at the desk does not
 * count. See docs/30-commission-fees/02-commission-rules.md#services.
 */
class BookingEngineCommission implements CommissionRule
{
    public function __construct(
        private OnlineBookingMoney $onlineBookingMoney,
        private ReservationRoomRevenue $roomRevenue,
    ) {}

    /**
     * @return Builder<Reservation>
     */
    public function candidates(): Builder
    {
        return Reservation::query()->whereHas('bookingSource', fn (Builder $query) => $query->where('type', BookingSourceType::BookingEngine));
    }

    public function appliesTo(Reservation $reservation, CarbonImmutable $dueDate): bool
    {
        return $reservation->bookingSource->type === BookingSourceType::BookingEngine
            && $this->onlineBookingMoney->forReservation($reservation) !== null;
    }

    public function base(Reservation $reservation): string
    {
        $revenue = BigDecimal::of($this->roomRevenue->forReservation($reservation));
        $booked = $this->onlineBookingMoney->forReservation($reservation)?->bookedRoomTotal;

        return (string) ($booked === null ? $revenue : BigDecimal::min($revenue, BigDecimal::of($booked)))->toScale(2);
    }
}
