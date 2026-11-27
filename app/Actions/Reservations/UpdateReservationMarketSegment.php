<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\MarketSegments;
use Illuminate\Support\Facades\DB;

class UpdateReservationMarketSegment
{
    public function __construct(private MarketSegments $marketSegments) {}

    /**
     * Set or clear the market segment. It works on closed reservations too, because it changes no money. Refused when the
     * segment is not an active segment of the property (`market_segment_not_available`). The same segment is not a
     * change: nothing is written or logged, so a segment turned off can stay on the bookings that have it.
     * See docs/12-reservations/15-market-segments.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ?int $marketSegmentId): ReservationResult
    {
        return DB::transaction(function () use ($actor, $reservation, $marketSegmentId): ReservationResult {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->market_segment_id === $marketSegmentId) {
                return ReservationResult::created($reservation);
            }

            if ($marketSegmentId !== null && ! $this->marketSegments->isAvailable($locked->property_id, $marketSegmentId)) {
                return ReservationResult::refused(ReservationResult::REFUSAL_MARKET_SEGMENT_NOT_AVAILABLE);
            }

            $oldMarketSegmentId = $locked->market_segment_id;
            $locked->market_segment_id = $marketSegmentId;
            $locked->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reservation.market_segment_updated')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'old' => ['market_segment_id' => $oldMarketSegmentId],
                    'new' => ['market_segment_id' => $marketSegmentId],
                ])
                ->log('Reservation market segment updated');

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return ReservationResult::created($reservation);
        });
    }
}
