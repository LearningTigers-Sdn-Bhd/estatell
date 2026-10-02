<?php

namespace App\Services\Reservations;

use App\Data\Reservations\AddUnitOptionsData;
use App\Data\Reservations\ChargeItemOptionData;
use App\Data\Reservations\RatePlanOptionData;
use App\Data\Reservations\RoomTypeOptionData;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * The lists the reservation page forms pick from. Each list is read when its overlay opens, in two queries or fewer.
 */
class ReservationFormOptions
{
    public function addUnit(Property $property): AddUnitOptionsData
    {
        return new AddUnitOptionsData(
            roomTypes: RoomType::query()->where('property_id', $property->id)->active()->ordered()->get()
                ->map(RoomTypeOptionData::fromModel(...))->all(),
            ratePlans: RatePlan::query()->where('property_id', $property->id)->active()->ordered()->with('roomTypes:id')->get()
                ->map(RatePlanOptionData::fromModel(...))->all(),
        );
    }

    /**
     * @return list<ChargeItemOptionData>
     */
    public function chargeItems(Property $property): array
    {
        return ChargeItem::query()->where('property_id', $property->id)->active()->ordered()->get()
            ->map(ChargeItemOptionData::fromModel(...))->all();
    }
}
