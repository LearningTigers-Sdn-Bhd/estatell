<?php

namespace App\Services\BookingEngine;

use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use Illuminate\Database\Eloquent\Builder;

/**
 * The caps on open holds, so a bug or abuse cannot hold every room. It reads only.
 * See docs/29-booking-engine/03-checkout-and-hold.md#open-hold-limits.
 */
class BookingHoldLimits
{
    public const int MINIMUM_PROPERTY_LINES = 2;

    public const int GUEST_IP_HOLDS = 3;

    public function __construct(private AvailabilityService $availability) {}

    /**
     * Open lines, with the new ones, stay within half of the property's rooms and beds.
     */
    public function propertyAllows(Property $property, int $newLines): bool
    {
        $open = BookingHoldLine::query()
            ->whereHas('hold', fn (Builder $hold) => $hold->where('property_id', $property->id)->open())
            ->count();

        return $open + $newLines <= max(self::MINIMUM_PROPERTY_LINES, (int) ceil($this->sellableUnits($property) / 2));
    }

    public function guestIpAllows(Property $property, string $guestIpHash): bool
    {
        return BookingHold::query()
            ->where('property_id', $property->id)
            ->where('guest_ip_hash', $guestIpHash)
            ->open()
            ->count() < self::GUEST_IP_HOLDS;
    }

    private function sellableUnits(Property $property): int
    {
        $today = now()->toImmutable()->startOfDay();
        $nights = $this->availability->nightsForRoomTypes(RoomType::query()->where('property_id', $property->id)->active()->get(), $today, $today->addDay());

        return array_sum(array_map(fn (array $typeNights): int => array_sum(array_map(fn (NightAvailability $night): int => $night->total, $typeNights)), $nights));
    }
}
