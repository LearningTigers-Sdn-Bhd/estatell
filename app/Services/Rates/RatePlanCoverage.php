<?php

namespace App\Services\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Checks that every active room type keeps at least one active linked rate plan.
 */
class RatePlanCoverage
{
    /**
     * The ids of the property's active room types that a plan with this pricing basis can sell.
     *
     * @return list<int>
     */
    public function sellableRoomTypeIds(int $propertyId, PricingBasis $basis): array
    {
        return RoomType::query()
            ->where('property_id', $propertyId)
            ->active()
            ->get()
            ->filter(fn (RoomType $roomType): bool => $basis->fits($roomType->sell_unit))
            ->pluck('id')
            ->all();
    }

    /**
     * The active room types that would be left with no active plan if this plan stopped selling them.
     * Pass the room types the plan keeps, or null when the plan is archived and keeps none.
     *
     * @param  list<int>|null  $keepRoomTypeIds
     * @return Collection<int, RoomType>
     */
    public function stranded(RatePlan $plan, ?array $keepRoomTypeIds = null): Collection
    {
        return RoomType::query()
            ->where('property_id', $plan->property_id)
            ->active()
            ->whereHas('ratePlans', fn ($query) => $query->whereKey($plan->id))
            ->when($keepRoomTypeIds !== null, fn ($query) => $query->whereNotIn('id', $keepRoomTypeIds))
            ->whereDoesntHave('ratePlans', fn ($query) => $query->active()->whereKeyNot($plan->id))
            ->ordered()
            ->get();
    }

    /**
     * The staff message for the room types that would be stranded, or null when none would be.
     *
     * @param  list<int>|null  $keepRoomTypeIds
     */
    public function message(RatePlan $plan, ?array $keepRoomTypeIds = null): ?string
    {
        $names = $this->stranded($plan, $keepRoomTypeIds)->pluck('name')->all();

        if ($names === []) {
            return null;
        }

        return __(':names would have no rate plan. Link another plan first.', [
            'names' => Arr::join($names, ', ', __(' and ')),
        ]);
    }
}
