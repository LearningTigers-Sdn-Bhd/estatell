<?php

namespace App\Services\Channels;

use App\Enums\ChannelSellMode;
use App\Enums\ChannelSupport;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\Weekday;
use App\Models\Bed;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Channels\Dorm\DormStrategyResolver;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Decides, for each room type and rate plan pair, whether it is sent in full, sent flattened or not sent, with the
 * reason. The admin preview, the sync and the owner list all read the same answer. A dorm goes with its dorm strategy
 * (docs/21-channels/03-dorm-strategies.md): its per-bed plans, one bed per unit.
 */
class ChannelCapability
{
    public function __construct(private DormStrategyResolver $dorms) {}

    public function forPair(RoomType $roomType, RatePlan $ratePlan): ChannelCapabilityResult
    {
        if ($ratePlan->pricing_basis->channelRefusal() !== null) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, $ratePlan->pricing_basis->channelRefusal());
        }

        if ($roomType->sell_unit === SellUnit::Bed) {
            $refusal = $this->dorms->for($roomType)->refusal()
                ?? ($ratePlan->pricing_basis === PricingBasis::PerBed ? null : __('On channels a dorm sells its per-bed plans only.'));

            if ($refusal !== null) {
                return new ChannelCapabilityResult(ChannelSupport::Unsupported, $refusal);
            }
        } elseif ($ratePlan->pricing_basis === PricingBasis::PerBed) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, __('A per-bed plan goes on channels with a dorm room type only.'));
        }

        if ($this->activeRoomCount($roomType) === 0) {
            return new ChannelCapabilityResult(ChannelSupport::Unsupported, $roomType->sell_unit === SellUnit::Bed ? __('This dorm has no active beds.') : __('This room type has no active rooms.'));
        }

        return match ($ratePlan->pricing_basis) {
            PricingBasis::Package => $this->forPackage($roomType, $ratePlan),
            default => new ChannelCapabilityResult(ChannelSupport::Full),
        };
    }

    /**
     * The units a room type can sell on a channel: its active rooms, or for a dorm the active beds of its active rooms
     * (each bed is one unit, see `BedAsUnitStrategy`). Uses `active_rooms_count` / `active_beds_count` when the caller
     * loaded it.
     */
    public function activeRoomCount(RoomType $roomType): int
    {
        if ($roomType->sell_unit === SellUnit::Bed) {
            return (int) ($roomType->getAttribute('active_beds_count') ?? Bed::query()->active()->whereHas('room', fn (Builder $rooms) => $rooms->active()->where('room_type_id', $roomType->id))->count());
        }

        return (int) ($roomType->getAttribute('active_rooms_count') ?? $roomType->rooms()->active()->count());
    }

    /**
     * The adults one unit of the room type holds on the channel: its top adults, or for a dorm what its strategy sends
     * (one guest per bed).
     */
    public function channelAdults(RoomType $roomType): int
    {
        return $roomType->sell_unit === SellUnit::Bed ? $this->dorms->for($roomType)->adults($roomType) : $roomType->max_adults;
    }

    /**
     * The children one unit of the room type holds on the channel.
     */
    public function channelChildren(RoomType $roomType): int
    {
        return $roomType->sell_unit === SellUnit::Bed ? $this->dorms->for($roomType)->children($roomType) : $roomType->max_children;
    }

    /**
     * The channel manager's room kind for the room type.
     */
    public function roomKind(RoomType $roomType): string
    {
        return $roomType->sell_unit === SellUnit::Bed ? $this->dorms->for($roomType)->roomKind() : 'room';
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
        if ($roomType->sell_unit === SellUnit::Bed) {
            return ChannelSellMode::PerRoom;
        }

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
