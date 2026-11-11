<?php

namespace App\Services\BookingEngine;

use App\Data\Rates\RateCalendarCellData;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\Rates\RateCalendarService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The lowest price and closed dates for guests choosing dates. A date is open when a public plan sells a room type with
 * rooms left on it, and is inside the booking window. A package cell holds the price of a whole stay, so it opens the
 * date but gives no nightly price. It reads only, and looks at one night at a time: `offers` decides whether a stay can
 * be booked.
 */
class BookingCalendar
{
    public function __construct(
        private RateCalendarService $rateCalendar,
        private AvailabilityService $availability,
        private BookingWindow $window,
    ) {}

    /**
     * @return list<BookingCalendarDay>
     */
    public function forRange(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $property->loadMissing('settings');
        $bookablePlanIds = RatePlan::query()->where('property_id', $property->id)->bookableOnline()->pluck('id')->all();
        $rows = $this->rateCalendar->prices($property, $from, $to, $property->settings->currency->getCurrencyCode());
        $roomTypes = array_map(fn (array $row): RoomType => $row['roomType'], $rows);
        $nights = $this->availability->nightsForRoomTypes($roomTypes, $from, $to->addDay());
        /** @var array<string, BigDecimal|null> $open The lowest nightly price of each open date, by date. */
        $open = [];

        foreach ($rows as $row) {
            $hasRooms = $this->datesWithRooms($nights[$row['roomType']->id] ?? []);

            foreach ($row['plans'] as $plan) {
                if (! in_array($plan->id, $bookablePlanIds, true)) {
                    continue;
                }

                foreach ($plan->cells as $cell) {
                    if ($this->sells($cell) && isset($hasRooms[$cell->date])) {
                        $price = $plan->pricingBasis === PricingBasis::Package ? null : BigDecimal::of((string) $cell->amount);
                        $lowest = $open[$cell->date] ?? null;
                        $open[$cell->date] = $lowest === null || ($price !== null && $price->isLessThan($lowest)) ? ($price ?? $lowest) : $lowest;
                    }
                }
            }
        }

        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $isOpen = array_key_exists($key, $open) && $this->window->contains($property, $date);
            $days[] = new BookingCalendarDay($key, $isOpen && $open[$key] !== null ? (string) $open[$key]->toScale(2) : null, ! $isOpen);
        }

        return $days;
    }

    private function sells(RateCalendarCellData $cell): bool
    {
        return $cell->amount !== null && ! $cell->stopSell;
    }

    /**
     * @param  list<NightAvailability>  $nights
     * @return array<string, true>
     */
    private function datesWithRooms(array $nights): array
    {
        $dates = [];

        foreach ($nights as $night) {
            if ($night->available > 0) {
                $dates[$night->date->toDateString()] = true;
            }
        }

        return $dates;
    }
}
