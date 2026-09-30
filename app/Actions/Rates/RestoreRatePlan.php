<?php

namespace App\Actions\Rates;

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreRatePlan
{
    /**
     * Bring an archived plan back at the end of the list.
     *
     * Returns false when it is not archived, or an active plan already has the same name.
     */
    public function __invoke(User $actor, RatePlan $ratePlan): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan): bool {
            Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at === null) {
                return false;
            }

            $nameTaken = RatePlan::query()
                ->where('property_id', $ratePlan->property_id)
                ->active()
                ->whereRaw('lower(name) = ?', [mb_strtolower($ratePlan->name)])
                ->exists();

            if ($nameTaken) {
                return false;
            }

            $ratePlan->forceFill([
                'archived_at' => null,
                'position' => ((int) RatePlan::query()->where('property_id', $ratePlan->property_id)->max('position')) + 1,
            ])->save();

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.restored')
                ->withProperties(['property_id' => $ratePlan->property_id])
                ->log('Rate plan restored');

            return true;
        });
    }
}
