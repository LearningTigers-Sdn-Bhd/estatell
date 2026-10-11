<?php

namespace App\Actions\Rates;

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

class ReconcileRoomTypeRatePlans
{
    /**
     * Keep a room type's plan links valid after it is created, restored or changes its sell unit.
     *
     * Links to plans whose pricing basis no longer fits the sell unit are removed. When the room type then has no
     * active plan, it is linked to the first active plan (by position) whose basis fits. If none fits, it stays unlinked.
     */
    public function __invoke(RoomType $roomType): void
    {
        DB::transaction(function () use ($roomType): void {
            Property::query()->lockForUpdate()->findOrFail($roomType->property_id);

            $unfitting = $roomType->ratePlans()->get()
                ->reject(fn (RatePlan $ratePlan): bool => $ratePlan->pricing_basis->fits($roomType->sell_unit))
                ->pluck('id')
                ->all();

            if ($unfitting !== []) {
                $roomType->ratePlans()->detach($unfitting);
            }

            if ($roomType->archived_at !== null || $roomType->ratePlans()->active()->nightly()->exists()) {
                return;
            }

            $firstFitting = RatePlan::query()->where('property_id', $roomType->property_id)->active()->nightly()->ordered()->get()
                ->first(fn (RatePlan $ratePlan): bool => $ratePlan->pricing_basis->fits($roomType->sell_unit));

            if ($firstFitting !== null) {
                $roomType->ratePlans()->attach($firstFitting->id);
            }
        });
    }
}
