<?php

namespace App\Services\Portal;

use App\Actions\Portal\PortalBookingResult;
use App\Actions\Reservations\ReservationResult;
use App\Data\Portal\NewPortalLineData;
use App\Data\Portal\PortalLineOffersData;
use App\Data\Portal\PortalOfferData;
use App\Data\Reservations\BookingQuoteData;
use App\Data\Reservations\NewAddOnData;
use App\Enums\SellUnit;
use App\Models\Company;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\Reservations\AutoAddOns;
use App\Services\Reservations\BookingQuote;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Prices what a company user can book. It uses the same `BookingQuote` as the staff booking form, with the auto-add
 * items of the property on every room, so the total the page shows is what the save charges. A dorm line is priced
 * for one adult in each bed. It reads only. See docs/23-company-portal/01-company-portal.md#booking.
 */
class PortalBookingQuote
{
    /** @var array<int, list<NewAddOnData>> */
    private array $autoAddOnCache = [];

    public function __construct(
        private BookingQuote $bookingQuote,
        private AutoAddOns $autoAddOns,
        private PortalRatePlans $portalRatePlans,
        private AvailabilityService $availability,
        private PortalBookingMessages $messages,
    ) {}

    /**
     * The offers for each line: every plan the company can see that sells the room type, quoted and checked. A blocked
     * offer keeps its reason. A line with too many adults or children has a `lineError` and no offers.
     *
     * @param  list<NewPortalLineData>  $lines
     * @return list<PortalLineOffersData>
     */
    public function offers(Property $property, Company $company, CarbonImmutable $arrival, CarbonImmutable $departure, array $lines): array
    {
        $roomTypes = RoomType::query()->where('property_id', $property->id)->active()
            ->whereIn('id', array_unique(array_map(fn (NewPortalLineData $line): int => $line->roomTypeId, $lines)))
            ->get()->keyBy('id');
        $plans = $this->portalRatePlans->visibleTo($company)->with('roomTypes:id')->ordered()->get();
        $nights = $this->availability->nightsForRoomTypes($roomTypes->all(), $arrival, $departure);

        return array_map(function (NewPortalLineData $line) use ($property, $arrival, $departure, $roomTypes, $plans, $nights): PortalLineOffersData {
            $roomType = $roomTypes->get($line->roomTypeId);

            if ($roomType === null) {
                return new PortalLineOffersData([], $this->messages->message(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND));
            }

            $refusal = $this->occupancyRefusal($roomType, $line);

            if ($refusal !== null) {
                return new PortalLineOffersData([], $this->messages->message($refusal));
            }

            return new PortalLineOffersData(
                array_values($plans->filter(fn (RatePlan $plan): bool => $plan->roomTypes->contains('id', $roomType->id))
                    ->map(fn (RatePlan $plan): PortalOfferData => $this->offer($property, $roomType, $plan, $line, $arrival, $departure, $nights[$roomType->id] ?? []))
                    ->all()),
                null,
            );
        }, $lines);
    }

    /**
     * The total of a line with its auto-added items, or null when the quote is refused (no rate, wrong stay length).
     */
    public function lineTotal(Property $property, RoomType $roomType, NewPortalLineData $line, CarbonImmutable $arrival, CarbonImmutable $departure): ?string
    {
        $quote = $this->quote($property, $roomType, (int) $line->ratePlanId, $line, $arrival, $departure);

        return $quote->errors === [] ? $this->total($quote) : null;
    }

    /**
     * Why the room type cannot take the line's guests, as a refusal code, or null when it can. A dorm line has no limit.
     */
    public function occupancyRefusal(RoomType $roomType, NewPortalLineData $line): ?string
    {
        if ($roomType->sell_unit === SellUnit::Bed) {
            return null;
        }

        return match (true) {
            $line->adults > $roomType->max_adults => PortalBookingResult::REFUSAL_TOO_MANY_ADULTS,
            count($line->childAges) > $roomType->max_children => PortalBookingResult::REFUSAL_TOO_MANY_CHILDREN,
            default => null,
        };
    }

    /**
     * @param  list<NightAvailability>  $nights
     */
    private function offer(Property $property, RoomType $roomType, RatePlan $plan, NewPortalLineData $line, CarbonImmutable $arrival, CarbonImmutable $departure, array $nights): PortalOfferData
    {
        $quote = $this->quote($property, $roomType, $plan->id, $line, $arrival, $departure);

        if ($quote->errors !== []) {
            return new PortalOfferData($plan->id, $plan->name, null, (string) current($quote->errors));
        }

        $needed = $roomType->sell_unit === SellUnit::Bed ? max(1, $line->beds) : 1;
        $soldOut = array_find($nights, fn (NightAvailability $night): bool => $night->available < $needed);

        $reason = match (true) {
            $soldOut !== null => $this->messages->message(ReservationResult::REFUSAL_NOT_AVAILABLE, $soldOut->date),
            $quote->violations !== [] => implode(' ', $quote->violations),
            default => null,
        };

        return new PortalOfferData($plan->id, $plan->name, $this->total($quote), $reason);
    }

    private function quote(Property $property, RoomType $roomType, int $ratePlanId, NewPortalLineData $line, CarbonImmutable $arrival, CarbonImmutable $departure): BookingQuoteData
    {
        $isBed = $roomType->sell_unit === SellUnit::Bed;

        return $this->bookingQuote->line(
            $property,
            $roomType->id,
            $ratePlanId,
            $arrival,
            $departure,
            $isBed ? 1 : $line->adults,
            $isBed ? [] : $line->childAges,
            $isBed ? max(1, $line->beds) : 1,
            $this->autoAddOns($property),
        );
    }

    /**
     * @return list<NewAddOnData>
     */
    private function autoAddOns(Property $property): array
    {
        return $this->autoAddOnCache[$property->id] ??= $this->autoAddOns->forUnit($property);
    }

    private function total(BookingQuoteData $quote): string
    {
        return (string) BigDecimal::of((string) $quote->lineTotal)->plus($quote->addOnsTotal)->toScale(2);
    }
}
