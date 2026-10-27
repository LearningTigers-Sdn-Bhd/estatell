<?php

namespace App\Services\Rates;

use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\DayUseOfferData;
use App\Enums\PricingBasis;
use App\Models\Rate;
use App\Models\RateHourPrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\CarbonInterface;

/**
 * Which day use blocks a plan sells: the hours with a price on the date that start on the hour or the half hour, stay
 * inside the plan's Sold between window and end on the same day. It reads only. See docs/12-reservations/10-day-use.md.
 */
class DayUseOffers
{
    /**
     * Minutes since midnight of an `H:i` or `H:i:s` time.
     */
    public static function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * An `H:i:s` time from minutes since midnight.
     */
    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * The end time (`H:i:s`) of a block, or null when it does not end on the same day.
     */
    public static function endsAt(string $startTime, int $hours): ?string
    {
        $end = self::minutes($startTime) + $hours * 60;

        return $end >= 24 * 60 ? null : self::time($end);
    }

    /**
     * The refusal code for an entered price block, or null. No plan limits it: any start on the hour or half hour, and
     * whole hours from 1 that end before midnight.
     */
    public function enteredRefusal(string $startTime, int $hours): ?string
    {
        if (preg_match('/^\d{2}:(00|30)(:00)?$/', $startTime) !== 1 || self::minutes($startTime) >= 24 * 60 || self::endsAt($startTime, $hours) === null) {
            return ReservationResult::REFUSAL_START_TIME_INVALID;
        }

        return $hours < 1 ? RateQuote::REFUSAL_HOURS_NOT_SOLD : null;
    }

    /**
     * The refusal code for a start time and hours the plan does not sell, or null when the block fits the plan.
     * It does not read prices.
     */
    public function refusal(RatePlan $plan, string $startTime, int $hours): ?string
    {
        if (preg_match('/^\d{2}:(00|30)(:00)?$/', $startTime) !== 1 || self::minutes($startTime) >= 24 * 60 || self::endsAt($startTime, $hours) === null) {
            return ReservationResult::REFUSAL_START_TIME_INVALID;
        }

        if ($plan->pricing_basis !== PricingBasis::DayUse || $hours < ($plan->day_use_min_hours ?? 1) || $hours > ($plan->day_use_max_hours ?? 24)) {
            return RateQuote::REFUSAL_HOURS_NOT_SOLD;
        }

        if ($plan->day_use_from !== null && $plan->day_use_until !== null
            && (self::minutes($startTime) < self::minutes($plan->day_use_from) || self::minutes(self::endsAt($startTime, $hours)) > self::minutes($plan->day_use_until))) {
            return ReservationResult::REFUSAL_OUTSIDE_SOLD_BETWEEN;
        }

        return null;
    }

    /**
     * The hours the plan sells for the room type on the date, starting at `$startTime`, each with its price and end time.
     * An hour with no price is left out, and so is an hour that would end after the window. A start time the plan
     * cannot sell gives an empty list.
     *
     * @return list<DayUseOfferData>
     */
    public function forStart(RatePlan $plan, RoomType $roomType, CarbonInterface $date, string $startTime): array
    {
        if ($plan->pricing_basis !== PricingBasis::DayUse || ! $plan->roomTypes()->whereKey($roomType->id)->exists()) {
            return [];
        }

        $rate = Rate::query()
            ->where('rate_plan_id', $plan->id)
            ->where('room_type_id', $roomType->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($rate === null) {
            return [];
        }

        $prices = RateHourPrice::query()->where('rate_id', $rate->id)->orderBy('hours')->pluck('amount', 'hours');
        $offers = [];

        for ($hours = $plan->day_use_min_hours ?? 1; $hours <= ($plan->day_use_max_hours ?? 24); $hours++) {
            if (! $prices->has($hours) || $this->refusal($plan, $startTime, $hours) !== null) {
                continue;
            }

            $offers[] = new DayUseOfferData($hours, (string) $prices->get($hours), substr((string) self::endsAt($startTime, $hours), 0, 5));
        }

        return $offers;
    }
}
