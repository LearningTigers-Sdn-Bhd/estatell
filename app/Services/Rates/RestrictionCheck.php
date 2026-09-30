<?php

namespace App\Services\Rates;

use App\Models\Rate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * The one check of selling restrictions for every channel. It reports what fails. The caller decides
 * whether to block, warn or accept.
 */
class RestrictionCheck
{
    /**
     * The reasons a stay breaks the restrictions of a room type and plan. An empty list means it can be sold.
     * A date with no rate row has no restrictions.
     *
     * @return list<string>
     */
    public function violations(int $ratePlanId, int $roomTypeId, CarbonInterface $arrival, CarbonInterface $departure): array
    {
        $arrival = $arrival->startOfDay();
        $departure = $departure->startOfDay();
        $nights = (int) $arrival->diffInDays($departure);

        if ($nights < 1) {
            return [];
        }

        $rates = Rate::query()
            ->where('rate_plan_id', $ratePlanId)
            ->where('room_type_id', $roomTypeId)
            ->whereBetween('date', [$arrival->toDateString(), $departure->toDateString()])
            ->get()
            ->keyBy(fn (Rate $rate): string => $rate->date->toDateString());

        $violations = [];

        for ($date = $arrival; $date->lt($departure); $date = $date->addDay()) {
            if ($rates->get($date->toDateString())?->stop_sell) {
                $violations[] = __('Not for sale on :date.', ['date' => $date->format('d M Y')]);
            }
        }

        $arrivalRate = $rates->get($arrival->toDateString());

        if ($arrivalRate?->closed_to_arrival) {
            $violations[] = __('Closed to arrival on :date.', ['date' => $arrival->format('d M Y')]);
        }

        if ($arrivalRate?->min_stay !== null && $nights < $arrivalRate->min_stay) {
            $violations[] = trans_choice('Min stay :count night.|Min stay :count nights.', $arrivalRate->min_stay);
        }

        if ($arrivalRate?->max_stay !== null && $nights > $arrivalRate->max_stay) {
            $violations[] = trans_choice('Max stay :count night.|Max stay :count nights.', $arrivalRate->max_stay);
        }

        if ($rates->get($departure->toDateString())?->closed_to_departure) {
            $violations[] = __('Closed to departure on :date.', ['date' => $departure->format('d M Y')]);
        }

        return $violations;
    }
}
