<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookableOnlineResult;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turn one rate plan on or off for online booking. Only an active, nightly plan open to everyone can be turned on.
 * Returns a refusal, and writes nothing, for another plan, and for turning off the last bookable plan of a published site.
 * Asking for the state the plan already has changes and logs nothing.
 */
class SetRatePlanBookableOnline
{
    public function __invoke(User $actor, Property $property, int $ratePlanId, bool $on): BookableOnlineResult
    {
        return DB::transaction(function () use ($actor, $property, $ratePlanId, $on): BookableOnlineResult {
            $plans = RatePlan::query()->where('property_id', $property->id)->lockForUpdate()->get();
            $plan = $plans->firstWhere('id', $ratePlanId);

            if ($plan === null) {
                return BookableOnlineResult::NotAvailable;
            }

            if ($plan->bookable_online === $on) {
                return BookableOnlineResult::Saved;
            }

            if ($on) {
                if (! RatePlan::query()->whereKey($plan->id)->offerableOnline()->exists()) {
                    return BookableOnlineResult::NotAvailable;
                }
            } elseif ($property->bookingSite()->first()?->isPublished() === true && ! $this->anotherBookablePlan($property, $plan)) {
                return BookableOnlineResult::LastPlan;
            }

            $plan->forceFill(['bookable_online' => $on])->save();

            activity()
                ->performedOn($plan)
                ->causedBy($actor)
                ->event('rate_plan.bookable_online_changed')
                ->withProperties(['property_id' => $property->id, 'bookable_online' => $on])
                ->log($on ? 'Rate plan turned on for online booking' : 'Rate plan turned off for online booking');

            return BookableOnlineResult::Saved;
        });
    }

    private function anotherBookablePlan(Property $property, RatePlan $plan): bool
    {
        return RatePlan::query()->where('property_id', $property->id)->whereKeyNot($plan->id)->bookableOnline()->exists();
    }
}
