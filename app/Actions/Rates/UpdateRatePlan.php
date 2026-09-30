<?php

namespace App\Actions\Rates;

use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRatePlan
{
    /**
     * Save the details of an active rate plan. The pricing basis never changes after creation.
     *
     * Returns false for an archived plan.
     *
     * @param  array<string, mixed>  $attributes  Validated details fields.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $attributes): bool {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return false;
            }

            $ratePlan->fill($attributes);
            $changes = $ratePlan->getDirty();

            if ($changes === []) {
                return true;
            }

            $ratePlan->save();

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.updated')
                ->withProperties(['property_id' => $ratePlan->property_id, 'changed' => array_keys($changes)])
                ->log('Rate plan updated');

            return true;
        });
    }
}
