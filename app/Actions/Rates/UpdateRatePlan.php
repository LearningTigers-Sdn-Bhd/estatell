<?php

namespace App\Actions\Rates;

use App\Models\RatePlan;
use App\Models\User;
use App\Services\Rates\DerivedRatePlans;
use Illuminate\Support\Facades\DB;

class UpdateRatePlan
{
    public function __construct(private DerivedRatePlans $derivedRatePlans) {}

    /**
     * Save the details of an active rate plan. The pricing basis never changes after creation.
     *
     * Returns false for an archived plan, or a package whose nights change while it follows another package or is followed.
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

            foreach (['day_use_from', 'day_use_until'] as $time) {
                if (is_string($attributes[$time] ?? null) && strlen($attributes[$time]) === 5) {
                    $attributes[$time] .= ':00';
                }
            }

            $ratePlan->fill($attributes);
            $changes = $ratePlan->getDirty();

            if (array_key_exists('fixed_nights', $changes) && $this->derivedRatePlans->nightsLockMessage($ratePlan) !== null) {
                return false;
            }

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
