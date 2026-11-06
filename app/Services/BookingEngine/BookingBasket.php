<?php

namespace App\Services\BookingEngine;

use App\Actions\BookingEngine\BookingHoldResult;
use App\Actions\Reservations\ReservationResult;
use App\Data\BookingEngine\NewBookingHoldLineData;
use App\Models\Property;
use App\Services\Reservations\ReservationRefusals;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The price of the rooms a guest chose, on the same rules as a hold, so the total a guest sees is the total a hold accepts.
 * It reads only. See docs/29-booking-engine/02-api.md#pricing.
 */
class BookingBasket
{
    public function __construct(
        private BookingOffers $offers,
        private ReservationRefusals $refusals,
    ) {}

    /**
     * @param  list<NewBookingHoldLineData>  $lines
     */
    public function price(Property $property, CarbonImmutable $arrival, CarbonImmutable $departure, array $lines, string $nationality): BookingBasketPrice
    {
        $offers = [];
        $needed = [];

        foreach ($this->offers->forLines($property, $arrival, $departure, $lines, $nationality) as $offer) {
            if ($offer === null) {
                return BookingBasketPrice::refused(BookingHoldResult::REFUSAL_PLAN_NOT_AVAILABLE, __('This rate cannot be booked online.'));
            }

            if ($offer->blockedCode !== null) {
                return BookingBasketPrice::refused($offer->blockedCode, (string) $offer->blockedMessage);
            }

            $needed[$offer->roomType->id] = ($needed[$offer->roomType->id] ?? 0) + 1;

            if ($needed[$offer->roomType->id] > $offer->roomsLeft) {
                return BookingBasketPrice::refused(ReservationResult::REFUSAL_NOT_AVAILABLE, $this->refusals->message(ReservationResult::REFUSAL_NOT_AVAILABLE));
            }

            $offers[] = $offer;
        }

        $currency = $property->settings->currency->getCurrencyCode();
        $onlineTotal = Money::zero($currency);
        $deskTotal = Money::zero($currency);

        foreach ($offers as $offer) {
            $onlineTotal = $onlineTotal->plus($offer->price->onlineTotal);
            $deskTotal = $deskTotal->plus($offer->price->deskTotal);
        }

        return BookingBasketPrice::priced($offers, $onlineTotal, $deskTotal);
    }
}
