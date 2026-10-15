<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\SellUnit;
use App\Enums\StepState;
use App\Models\Property;
use App\Models\Rate;
use App\Services\Onboarding\BasicRatePlans;

/**
 * Done when every active room type sold by room has a price on each of the next 30 days (today in the property time zone)
 * on its Basic Rate plan. InProgress when some prices exist but not all, NotStarted when there is none. More rates never
 * count. The window is 30 days because the wizard saves 365 days from the day the form is saved.
 */
class BasicRateCheck implements StepCheck
{
    public const DAYS = 30;

    public function __construct(private BasicRatePlans $basicRatePlans = new BasicRatePlans) {}

    public function state(Property $property): StepState
    {
        $property->loadMissing('settings');
        $roomTypes = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->with('ratePlans')->get();

        if ($roomTypes->isEmpty() || $property->settings === null) {
            return StepState::NotStarted;
        }

        $basicPlanIds = $this->basicRatePlans->forProperty($property)->pluck('id')->all();
        $from = now($property->settings->time_zone)->startOfDay();
        $counts = Rate::query()
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereIn('rate_plan_id', $basicPlanIds)
            ->whereBetween('date', [$from->toDateString(), $from->addDays(self::DAYS - 1)->toDateString()])
            ->whereNotNull('amount')
            ->selectRaw('room_type_id, rate_plan_id, count(*) as days')
            ->groupBy('room_type_id', 'rate_plan_id')
            ->get();

        $covered = 0;
        $priced = 0;

        foreach ($roomTypes as $roomType) {
            $days = $counts
                ->where('room_type_id', $roomType->id)
                ->filter(fn (Rate $row): bool => $roomType->ratePlans->contains('id', $row->rate_plan_id))
                ->max('days') ?? 0;

            $priced += $days > 0 ? 1 : 0;
            $covered += $days >= self::DAYS ? 1 : 0;
        }

        return match (true) {
            $covered === $roomTypes->count() => StepState::Done,
            $priced > 0 => StepState::InProgress,
            default => StepState::NotStarted,
        };
    }
}
