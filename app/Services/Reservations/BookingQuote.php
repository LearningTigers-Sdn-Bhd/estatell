<?php

namespace App\Services\Reservations;

use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\BookingQuoteData;
use App\Data\Reservations\BookingQuoteNightData;
use App\Data\Reservations\NewAddOnData;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Catalogues\ChargeQuantityResolver;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Prices one booking line for the booking form, with the same services `AddUnit` and `AddUnitAddOn` use, so the form
 * shows what the save will charge. It reads only. A Bed room type is priced for one bed, and the totals are that times
 * `$beds`. A price override replaces the quote. The catalogue discount is not part of the preview. An add-on that
 * cannot be priced (an unknown item, or no amount) is left out of the total, and the save refuses it.
 */
class BookingQuote
{
    public function __construct(
        private RateQuoteService $rateQuoteService,
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private ReservationRefusals $refusals,
        private ChargeQuantityResolver $quantityResolver,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * @param  list<int>  $childAges
     * @param  list<NewAddOnData>  $addOns
     */
    public function line(Property $property, int $roomTypeId, int $ratePlanId, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges, int $beds, array $addOns = [], ?string $overrideTotal = null): BookingQuoteData
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

        $unitTotal = $overrideTotal === null ? $quote->total : Money::of($overrideTotal, $quote->total->getCurrency());

        return new BookingQuoteData(
            nights: array_map(fn (NightPrice $night): BookingQuoteNightData => new BookingQuoteNightData(
                date: $night->date->toDateString(),
                base: (string) $night->base->getAmount(),
                extra: (string) $night->extra->getAmount(),
                supplement: (string) $night->supplement->getAmount(),
                discount: (string) $night->discountAmount->getAmount(),
                amount: (string) $night->net()->getAmount(),
            ), $quote->nights),
            unitTotal: (string) $unitTotal->getAmount(),
            lineTotal: (string) $unitTotal->multipliedBy($beds)->getAmount(),
            addOnsTotal: (string) $this->addOnsTotal($property, $addOns, $arrival, $departure, $adults, count($childAges), $quote->total->getCurrency())->multipliedBy($beds)->getAmount(),
            isOverridden: $overrideTotal !== null,
            beds: $beds,
            violations: $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $arrival, $departure),
            available: $this->availability->availableForStay($roomType, $arrival, $departure),
            errors: [],
        );
    }

    /**
     * The add-ons of one unit: each item's rows from `ChargeQuantityResolver`, times the amount.
     *
     * @param  list<NewAddOnData>  $addOns
     */
    private function addOnsTotal(Property $property, array $addOns, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, int $children, mixed $currency): Money
    {
        $total = Money::zero($currency);

        if ($addOns === []) {
            return $total;
        }

        $unit = (new ReservationUnit)->forceFill(['arrival_date' => $arrival, 'departure_date' => $departure, 'adults' => $adults, 'children' => $children]);
        $businessDate = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
        $items = ChargeItem::query()->where('property_id', $property->id)->active()->whereIn('id', array_map(fn (NewAddOnData $addOn): int => $addOn->chargeItemId, $addOns))->get()->keyBy('id');

        foreach ($addOns as $addOn) {
            $item = $items->get($addOn->chargeItemId);
            $amount = $addOn->unitAmount ?? $item?->default_amount;

            if ($item === null || $amount === null || preg_match('/^\d+(\.\d{1,2})?$/', (string) $amount) !== 1) {
                continue;
            }

            $price = Money::of($amount, $currency, roundingMode: RoundingMode::Down);

            foreach ($this->quantityResolver->rows($item->charging_unit, $unit, $businessDate, $addOn->quantity, $addOn->serviceDate) as $row) {
                $total = $total->plus($price->multipliedBy($row['quantity']));
            }
        }

        return $total;
    }
}
