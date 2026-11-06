<?php

namespace App\Services\Reservations;

use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Enums\StayType;
use App\Models\ChargeItem;
use App\Models\Property;

/**
 * The charge items that go on every new room: active items with `auto_add`. A day use room gets only the items that
 * do not charge by the night. A channel booking gets only the items that also have `auto_add_channels`. See
 * docs/12-reservations/04-add-ons.md#added-automatically.
 */
class AutoAddOns
{
    /**
     * @return list<ChargeItem>
     */
    public function items(Property $property, StayType $stayType = StayType::Overnight): array
    {
        return array_values(ChargeItem::query()
            ->where('property_id', $property->id)
            ->active()
            ->where('auto_add', true)
            ->ordered()
            ->get()
            ->filter(fn (ChargeItem $item): bool => $stayType !== StayType::DayUse || ! in_array($item->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true))
            ->values()
            ->all());
    }

    /**
     * The add-ons of a room booked on a channel: the auto-add items that are also added to channel bookings. Channel
     * rooms are always overnight.
     *
     * @return list<NewAddOnData>
     */
    public function forChannelUnit(Property $property): array
    {
        $items = array_filter($this->items($property), fn (ChargeItem $item): bool => $item->auto_add_channels);

        return array_values(array_map(fn (ChargeItem $item): NewAddOnData => new NewAddOnData($item->id), $items));
    }

    /**
     * @return list<NewAddOnData>
     */
    public function forUnit(Property $property, StayType $stayType = StayType::Overnight): array
    {
        return array_map(fn (ChargeItem $item): NewAddOnData => new NewAddOnData($item->id), $this->items($property, $stayType));
    }
}
