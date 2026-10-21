<?php

namespace App\Services\Channels;

use App\Enums\ChannelSellMode;
use App\Enums\ChannelSupport;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\Weekday;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Decides, for each room type and rate plan pair, whether it is sent in full, sent flattened or not sent, with the
 * reason. The admin preview, the sync and the owner list all read the same answer.
 */
class ChannelCapability
{
    public function forPair(RoomType $roomType, RatePlan $ratePlan): ChannelCapabilityResult
    {
        if ($ratePlan->pricing_basis->channelRefusal() !== null) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, $ratePlan->pricing_basis->channelRefusal());
        }

        if ($roomType->sell_unit === SellUnit::Bed || $ratePlan->pricing_basis === PricingBasis::PerBed) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, __('Dorm beds are not on channels yet.'));
        }

        if ($this->activeRoomCount($roomType) === 0) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, __('This room type has no active rooms.'));
        }

        return match ($ratePlan->pricing_basis) {
            PricingBasis::Package => $this->forPackage($roomType, $ratePlan),
            default => new ChannelCapabilityResult(ChannelSupport::Full),
        };
    }

    /**
     * The rooms a room type can sell. Uses `active_rooms_count` when the caller loaded it.
     */
    public function activeRoomCount(RoomType $roomType): int
    {
        return (int) ($roomType->getAttribute('active_rooms_count') ?? $roomType->rooms()->active()->count());
    }

    /**
     * Flat package: one price for every arrival, sent as the price per night with min stay = max stay = the nights.
     */
    private function forPackage(RoomType $roomType, RatePlan $ratePlan): ChannelCapabilityResult
    {
        $amounts = $ratePlan->loadMissing('packagePrices')->packagePrices
            ->where('room_type_id', $roomType->id)
            ->pluck('amount')
            ->map(fn (string $amount): string => number_format((float) $amount, 2, '.', ''))
            ->unique();

        if ($amounts->isEmpty()) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, __('This package has no price for this room type yet.'));
        }

        if ($amounts->count() > 1) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, __('The package price varies by arrival date. Channels cannot take it yet.'));
        }

        $note = __('Min stay = max stay :nights.', ['nights' => $ratePlan->fixed_nights]);
        $allowed = $this->allowedArrivalDays($ratePlan);

        if ($allowed !== null) {
            $note .= ' '.__('Arrival on :days only.', ['days' => Arr::join(array_map(fn (Weekday $day): string => $day->label(), $allowed), ', ', __(' and '))]);
        }

        return new ChannelCapabilityResult(ChannelSupport::Flattened, $note);
    }

    /**
     * The weekdays a package can start on, or null when it can start on any day.
     *
     * @return list<Weekday>|null
     */
    public function allowedArrivalDays(RatePlan $ratePlan): ?array
    {
        $days = $ratePlan->loadMissing('arrivalWeekdays')->arrivalWeekdays->pluck('weekday')->all();

        return $days === [] || count($days) === 7 ? null : array_values($days);
    }

    /**
     * How a pair is sold on the channel manager. A per-guest plan, and a per-room plan or per-room package whose price
     * depends on the number of adults, send one price for each adult count (Channex PerRoom holds one price for any
     * number of guests). The price depends on adults when the room type holds more adults than its base occupancy and
     * the plan (or the plan it follows) charges extra adults or sets a price for an adult count. Every other plan is
     * one price for the room.
     */
    public function sellMode(RatePlan $ratePlan, RoomType $roomType): ChannelSellMode
    {
        if ($this->isPerGuestPackage($ratePlan) || $ratePlan->pricing_basis === PricingBasis::PerGuest) {
            return ChannelSellMode::PerPerson;
        }

        $pricingPlan = $ratePlan->loadMissing('parent')->parent ?? $ratePlan;
        $perRoom = $pricingPlan->pricing_basis === PricingBasis::PerRoom
            || ($pricingPlan->pricing_basis === PricingBasis::Package && $pricingPlan->package_unit === PackageUnit::PerRoom);

        if (! $perRoom || $roomType->max_adults <= $roomType->base_occupancy) {
            return ChannelSellMode::PerRoom;
        }

        return BigDecimal::of($pricingPlan->extra_adult_amount ?? '0')->isPositive() || $this->hasAdultCountPrices($pricingPlan, $roomType)
            ? ChannelSellMode::PerPerson
            : ChannelSellMode::PerRoom;
    }

    /**
     * The flat extra a child adds to a night, as a decimal string. Per-room plans and per-room packages charge it for
     * every child. A plan that follows another uses the amount of the plan it follows, before its own discount. Other
     * plans have no flat child amount here.
     */
    public function childFee(RatePlan $ratePlan): string
    {
        $pricingPlan = $ratePlan->loadMissing('parent')->parent ?? $ratePlan;
        $perRoom = $pricingPlan->pricing_basis === PricingBasis::PerRoom
            || ($pricingPlan->pricing_basis === PricingBasis::Package && $pricingPlan->package_unit === PackageUnit::PerRoom);

        return $perRoom ? number_format((float) ($pricingPlan->extra_child_amount ?? 0), 2, '.', '') : '0.00';
    }

    private function hasAdultCountPrices(RatePlan $pricingPlan, RoomType $roomType): bool
    {
        return RateOccupancyPrice::query()
            ->whereHas('rate', fn (Builder $query) => $query->where('rate_plan_id', $pricingPlan->id)->where('room_type_id', $roomType->id))
            ->exists();
    }

    /**
     * Whether a package is charged per guest, which decides its Channex sell mode.
     */
    public function isPerGuestPackage(RatePlan $ratePlan): bool
    {
        return $ratePlan->pricing_basis === PricingBasis::Package && $ratePlan->package_unit === PackageUnit::PerGuest;
    }
}
