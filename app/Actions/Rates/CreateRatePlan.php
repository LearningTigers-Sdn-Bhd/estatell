<?php

namespace App\Actions\Rates;

use App\Enums\AgeBandPricing;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Rates\RatePlanCoverage;
use Illuminate\Support\Facades\DB;

class CreateRatePlan
{
    public function __construct(private RatePlanCoverage $coverage) {}

    /**
     * Add a rate plan at the end of the list, selling the given room types (none by default).
     *
     * A per-guest plan starts with one child price per age group: the youngest group free, every other group 50%.
     *
     * Returns null, without writing, when a room type is not an active room type of the property that fits the pricing basis.
     *
     * @param  array<string, mixed>  $attributes  Validated name, pricing_basis and optional description.
     * @param  list<int>  $roomTypeIds
     */
    public function __invoke(Property $property, User $actor, array $attributes, array $roomTypeIds = []): ?RatePlan
    {
        return DB::transaction(function () use ($property, $actor, $attributes, $roomTypeIds): ?RatePlan {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $basis = PricingBasis::from($attributes['pricing_basis']);

            if (array_diff($roomTypeIds, $this->coverage->sellableRoomTypeIds($property->id, $basis)) !== []) {
                return null;
            }

            $ratePlan = new RatePlan($attributes);
            $ratePlan->property_id = $property->id;
            $ratePlan->position = ((int) $property->ratePlans()->max('position')) + 1;
            $ratePlan->save();

            $ratePlan->roomTypes()->attach($roomTypeIds);

            if ($ratePlan->pricing_basis === PricingBasis::PerGuest) {
                foreach ($property->ageGroups()->ordered()->get() as $index => $ageGroup) {
                    $ratePlan->childPrices()->create($index === 0
                        ? ['property_age_group_id' => $ageGroup->id, 'pricing' => AgeBandPricing::Free, 'value' => null]
                        : ['property_age_group_id' => $ageGroup->id, 'pricing' => AgeBandPricing::Percent, 'value' => '50.00']);
                }
            }

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.created')
                ->withProperties(['property_id' => $property->id, 'name' => $ratePlan->name, 'pricing_basis' => $ratePlan->pricing_basis->value, 'room_type_ids' => $roomTypeIds])
                ->log('Rate plan created');

            return $ratePlan;
        });
    }
}
