<?php

namespace App\Services\Channels;

use App\Enums\ChannelSupport;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\Weekday;
use App\Models\RatePlan;
use App\Models\RoomType;
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
     * Whether a package is charged per guest, which decides its Channex sell mode.
     */
    public function isPerGuestPackage(RatePlan $ratePlan): bool
    {
        return $ratePlan->pricing_basis === PricingBasis::Package && $ratePlan->package_unit === PackageUnit::PerGuest;
    }
}
