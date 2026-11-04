<?php

namespace App\Services\BookingEngine;

use App\Actions\Reservations\ReservationResult;
use App\Enums\SellUnit;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use App\Services\Reservations\ReservationRefusals;
use Carbon\CarbonImmutable;

/**
 * What a guest can book for a stay: each active room type of each public rate plan, for one room with the party, or one
 * bed for one adult. It reads only. With no nationality, a guest is priced as a local guest, and the hold prices again
 * with the real one. See docs/29-booking-engine/02-api.md.
 */
class BookingOffers
{
    public const string REFUSAL_TOO_MANY_ADULTS = 'too_many_adults';

    public const string REFUSAL_TOO_MANY_CHILDREN = 'too_many_children';

    public const string REFUSAL_RESTRICTED = 'restricted';

    public function __construct(
        private OnlineStayPrice $onlineStayPrice,
        private RateQuoteService $rateQuoteService,
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private ReservationRefusals $refusals,
    ) {}

    /**
     * @param  list<int>  $childAges
     * @return list<BookingOffer>
     */
    public function forStay(Property $property, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges, ?string $nationality): array
    {
        $plans = RatePlan::query()
            ->where('property_id', $property->id)
            ->bookableOnline()
            ->ordered()
            ->with(['inclusions.chargeItem.transactionCode', 'roomTypes' => fn ($roomTypes) => $roomTypes->active()->ordered()])
            ->get();
        $nights = $this->availability->nightsForRoomTypes($plans->flatMap(fn (RatePlan $plan) => $plan->roomTypes)->unique('id')->all(), $arrival, $departure);
        $policy = RoomRevenuePolicy::query()->where('property_id', $property->id)->where('code', SystemTransactionCode::CancellationRevenue)->first();
        $context = $this->onlineStayPrice->context($property);
        $nationality = filled($nationality) ? $nationality : $property->country_code;
        $offers = [];

        foreach ($plans as $plan) {
            foreach ($plan->roomTypes as $roomType) {
                $offers[] = $this->offer($context, $plan, $roomType, $nights[$roomType->id] ?? [], $policy, $arrival, $departure, $adults, $childAges, $nationality);
            }
        }

        return $offers;
    }

    /**
     * @param  list<NightAvailability>  $nights
     * @param  list<int>  $childAges
     */
    private function offer(OnlinePriceContext $context, RatePlan $plan, RoomType $roomType, array $nights, ?RoomRevenuePolicy $policy, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges, ?string $nationality): BookingOffer
    {
        $left = $nights === [] ? 0 : min(array_map(fn (NightAvailability $night): int => $night->available, $nights));
        $blocked = fn (string $code, string $message): BookingOffer => new BookingOffer($roomType, $plan, $left, null, $policy, $code, $message);
        $isBed = $roomType->sell_unit === SellUnit::Bed;

        if (! $isBed && $adults > $roomType->max_adults) {
            return $blocked(self::REFUSAL_TOO_MANY_ADULTS, __('This room takes at most :count adults.', ['count' => $roomType->max_adults]));
        }

        if (! $isBed && count($childAges) > $roomType->max_children) {
            return $blocked(self::REFUSAL_TOO_MANY_CHILDREN, __('This room takes at most :count children.', ['count' => $roomType->max_children]));
        }

        $unitAdults = $isBed ? 1 : $adults;
        $unitChildAges = $isBed ? [] : $childAges;
        $quote = $this->rateQuoteService->quote(new RateQuoteRequest($plan, $roomType, $arrival, $departure, $unitAdults, $unitChildAges, 1));

        if ($quote->isRefused()) {
            return $blocked((string) $quote->refusal, $this->refusals->message((string) $quote->refusal, $quote->refusalDate));
        }

        if ($left < 1) {
            return $blocked(ReservationResult::REFUSAL_NOT_AVAILABLE, $this->refusals->message(ReservationResult::REFUSAL_NOT_AVAILABLE));
        }

        $violations = $this->restrictionCheck->violations($plan->id, $roomType->id, $arrival, $departure);

        if ($violations !== []) {
            return $blocked(self::REFUSAL_RESTRICTED, implode(' ', $violations));
        }

        $price = $this->onlineStayPrice->price($context, $plan, $quote, $arrival, $departure, $unitAdults, $unitChildAges, $nationality);

        if ($price->isRefused()) {
            return $blocked((string) $price->refusal, __('This room cannot be booked online.'));
        }

        return new BookingOffer($roomType, $plan, $left, $price, $policy);
    }
}
