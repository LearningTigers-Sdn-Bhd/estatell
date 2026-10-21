<?php

namespace App\Actions\Rates;

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Channels\ChannelArchiveGuard;
use App\Services\Rates\DerivedRatePlans;
use App\Services\Rates\RatePlanCoverage;
use Illuminate\Support\Facades\DB;

class ArchiveRatePlan
{
    public function __construct(private RatePlanCoverage $coverage, private DerivedRatePlans $derivedRatePlans, private ChannelArchiveGuard $channelGuard) {}

    /**
     * Hide a plan from new work. Its links and prices stay.
     *
     * Returns false when it is already archived, when active plans follow it, when a room type would be left with no active plan, or when the channel manager still has it.
     */
    public function __invoke(User $actor, RatePlan $ratePlan): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan): bool {
            Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $this->derivedRatePlans->followers($ratePlan)->isNotEmpty() || $this->coverage->stranded($ratePlan)->isNotEmpty() || $this->channelGuard->ratePlanMessage($ratePlan) !== null) {
                return false;
            }

            $ratePlan->forceFill(['archived_at' => now()])->save();

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.archived')
                ->withProperties(['property_id' => $ratePlan->property_id])
                ->log('Rate plan archived');

            return true;
        });
    }
}
