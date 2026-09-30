<?php

namespace App\Actions\Rates;

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Rates\DerivedRatePlans;
use App\Services\Rates\RatePlanCoverage;
use Illuminate\Support\Facades\DB;

class SyncRatePlanRoomTypes
{
    public function __construct(private RatePlanCoverage $coverage, private DerivedRatePlans $derivedRatePlans) {}

    /**
     * Set which active room types the plan sells. Links to archived room types are left alone.
     *
     * Returns false for an archived plan, for a room type that is not an active room type of the property whose sell unit
     * fits the plan's pricing basis, when unlinking would leave an active room type with no active plan,
     * when unlinking a room type that an active follower sells, or when a derived plan adds a room type its parent does not sell.
     * A save that changes nothing is not logged.
     *
     * @param  list<int>  $roomTypeIds
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $roomTypeIds): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $roomTypeIds): bool {
            Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return false;
            }

            $wanted = array_values(array_unique($roomTypeIds));
            $eligible = $this->coverage->sellableRoomTypeIds($ratePlan->property_id, $ratePlan->pricing_basis);

            if (array_diff($wanted, $eligible) !== [] || $this->coverage->stranded($ratePlan, $wanted)->isNotEmpty()) {
                return false;
            }

            $current = $ratePlan->roomTypes()->active()->pluck('room_types.id')->all();
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));

            if ($this->derivedRatePlans->unlinkMessage($ratePlan, $removed) !== null) {
                return false;
            }

            if ($ratePlan->parent_rate_plan_id !== null && array_diff($added, $ratePlan->parent->roomTypes()->pluck('room_types.id')->all()) !== []) {
                return false;
            }

            if ($added === [] && $removed === []) {
                return true;
            }

            $ratePlan->roomTypes()->attach($added);
            $ratePlan->roomTypes()->detach($removed);

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.room_types_updated')
                ->withProperties(['property_id' => $ratePlan->property_id, 'added' => $added, 'removed' => $removed])
                ->log('Rate plan room types updated');

            return true;
        });
    }
}
