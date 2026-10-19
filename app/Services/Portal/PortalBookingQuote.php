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
 * items of the property on every unit, so the total the page shows is what the save charges. A line picks the rate
 * plan first: its offers are the room types that plan sells. A room line is priced for its rooms, a dorm line for one
 * adult in each bed. It reads only. See docs/23-company-portal/01-company-portal.md#booking.
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
     * The offers for each line: every active room type of the line's plan, quoted and checked. A blocked room type keeps
     * its reason. What is left of a room type counts the units of the lines above that picked it. A line without a plan,
     * or with a plan the company cannot see, has a `lineError` and no offers.
     *
     * @param  list<NewPortalLineData>  $lines
     * @return list<PortalLineOffersData>
     */
    public function offers(Property $property, Company $company, CarbonImmutable $arrival, CarbonImmutable $departure, array $lines): array
    {
        $plans = $this->portalRatePlans->bookablePlansFor($company)->get()->keyBy('id');
        $roomTypes = $plans->flatMap(fn (RatePlan $plan) => $plan->roomTypes)->keyBy('id');
        $nights = $this->availability->nightsForRoomTypes($roomTypes->all(), $arrival, $departure);
        $taken = [];
        $result = [];

        foreach ($lines as $line) {
            $plan = $line->ratePlanId === null ? null : $plans->get($line->ratePlanId);

            if ($plan === null) {
                $result[] = new PortalLineOffersData([], $line->ratePlanId === null ? null : $this->messages->message(PortalBookingResult::REFUSAL_PLAN_NOT_VISIBLE));
            } else {
                $result[] = new PortalLineOffersData(
                    array_values($plan->roomTypes->map(fn (RoomType $roomType): PortalOfferData => $this->offer($property, $roomType, $plan, $line, $arrival, $departure, $nights[$roomType->id] ?? [], $taken[$roomType->id] ?? 0))->all()),
                    null,
                );
            }

            if ($line->roomTypeId !== null && $roomTypes->has($line->roomTypeId)) {
                $taken[$line->roomTypeId] = ($taken[$line->roomTypeId] ?? 0) + $this->units($roomTypes->get($line->roomTypeId), $line);
            }
        }

        return $result;
    }

    /**
     * The total of a line (its rooms or beds with their auto-added items), or null when the quote is refused (no rate,
     * wrong stay length).
     */
    public function lineTotal(Property $property, RoomType $roomType, NewPortalLineData $line, CarbonImmutable $arrival, CarbonImmutable $departure): ?string
    {
        $quote = $this->quote($property, $roomType, (int) $line->ratePlanId, $line, $arrival, $departure);

        return $quote->errors === [] ? $this->total($quote) : null;
    }

    /**
     * How many units a line books: its beds on a dorm, its rooms otherwise.
     */
    public function units(RoomType $roomType, NewPortalLineData $line): int
    {
        return $roomType->sell_unit === SellUnit::Bed ? max(1, $line->beds) : max(1, $line->rooms);
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
    private function offer(Property $property, RoomType $roomType, RatePlan $plan, NewPortalLineData $line, CarbonImmutable $arrival, CarbonImmutable $departure, array $nights, int $taken): PortalOfferData
    {
        $left = max(0, ($nights === [] ? 0 : min(array_map(fn (NightAvailability $night): int => $night->available, $nights))) - $taken);
        $occupancy = $this->occupancyRefusal($roomType, $line);

        if ($occupancy !== null) {
            return new PortalOfferData($roomType->id, null, $left, $this->messages->message($occupancy));
        }

        $quote = $this->quote($property, $roomType, $plan->id, $line, $arrival, $departure);

        if ($quote->errors !== []) {
            return new PortalOfferData($roomType->id, null, $left, (string) current($quote->errors));
        }

        $needed = $this->units($roomType, $line);
        $soldOut = array_find($nights, fn (NightAvailability $night): bool => $night->available - $taken < 1);

        $reason = match (true) {
            $nights === [] => $this->messages->message(ReservationResult::REFUSAL_NOT_AVAILABLE),
            $soldOut !== null => $this->messages->message(ReservationResult::REFUSAL_NOT_AVAILABLE, $soldOut->date),
            $needed > $left => $this->messages->onlyLeft($left),
            $quote->violations !== [] => implode(' ', $quote->violations),
            default => null,
        };

        return new PortalOfferData($roomType->id, $quote, $left, $reason);
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
            $this->units($roomType, $line),
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
