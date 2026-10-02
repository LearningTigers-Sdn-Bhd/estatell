<?php

namespace App\Services\Reservations;

use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\BookingQuoteData;
use App\Data\Reservations\BookingQuoteNightData;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Carbon\CarbonImmutable;

/**
 * Prices one booking line for the booking form, with the same services `AddUnit` uses, so the form shows what the
 * save will charge. It reads only. A Bed room type is priced for one bed, and the line total is that times `$beds`.
 * The catalogue discount and price overrides are not part of the preview.
 */
class BookingQuote
{
    public function __construct(
        private RateQuoteService $rateQuoteService,
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private ReservationRefusals $refusals,
    ) {}

    /**
     * @param  list<int>  $childAges
     */
    public function line(Property $property, int $roomTypeId, int $ratePlanId, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges, int $beds): BookingQuoteData
    {
        $beds = max(1, $beds);
        $roomType = RoomType::query()->where('property_id', $property->id)->active()->find($roomTypeId);
        $ratePlan = RatePlan::query()->where('property_id', $property->id)->find($ratePlanId);

        if ($roomType === null || $ratePlan === null) {
            return BookingQuoteData::refused($this->refusals->errors(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND), $beds);
        }

        if (! $ratePlan->roomTypes()->whereKey($roomType->id)->exists()) {
            return BookingQuoteData::refused($this->refusals->errors(ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED), $beds);
        }

        $arrival = $arrival->startOfDay();
        $departure = $departure->startOfDay();

        if ($departure->lte($arrival)) {
            return BookingQuoteData::refused($this->refusals->errors(ReservationResult::REFUSAL_INVALID_STAY), $beds);
        }

        $quote = $this->rateQuoteService->quote(new RateQuoteRequest($ratePlan, $roomType, $arrival, $departure, $adults, $childAges, 1));

        if ($quote->isRefused()) {
            return BookingQuoteData::refused($this->refusals->errors((string) $quote->refusal, $quote->refusalDate), $beds);
        }

        return new BookingQuoteData(
            nights: array_map(fn (NightPrice $night): BookingQuoteNightData => new BookingQuoteNightData(
                date: $night->date->toDateString(),
                base: (string) $night->base->getAmount(),
                extra: (string) $night->extra->getAmount(),
                supplement: (string) $night->supplement->getAmount(),
                discount: (string) $night->discountAmount->getAmount(),
                amount: (string) $night->net()->getAmount(),
            ), $quote->nights),
            unitTotal: (string) $quote->total->getAmount(),
            lineTotal: (string) $quote->total->multipliedBy($beds)->getAmount(),
            beds: $beds,
            violations: $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $arrival, $departure),
            available: $this->availability->availableForStay($roomType, $arrival, $departure),
            errors: [],
        );
    }
}
