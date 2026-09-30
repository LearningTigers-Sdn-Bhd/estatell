<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SeedRatePlans
{
    /**
     * Create the ordinary "Basic Rate" plan, priced per room, when the property has no rate plans yet.
     * It is linked to the property's active room-sold room types. A property that already has plans keeps every change.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->ratePlans()->exists()) {
                return;
            }

            $ratePlan = $property->ratePlans()->create([
                'name' => 'Basic Rate',
                'pricing_basis' => PricingBasis::PerRoom,
                'position' => 1,
            ]);

            $ratePlan->roomTypes()->attach(
                $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->pluck('id'),
            );
        });
    }
}
