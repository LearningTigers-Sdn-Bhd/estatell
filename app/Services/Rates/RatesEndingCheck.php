<?php

namespace App\Services\Rates;

use App\Models\Rate;
use App\Models\RatePlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Finds the room types of a plan whose prices run out soon.
 */
class RatesEndingCheck
{
    public const WARNING_DAYS = 60;

    /**
     * The last date each active linked room type has a price for, as `Y-m-d`, or null when it has none.
     *
     * @return array<int, string|null> Keyed by room type id.
     */
    public function pricedUntil(RatePlan $ratePlan): array
    {
        $roomTypeIds = $ratePlan->roomTypes()->whereNull('room_types.archived_at')->pluck('room_types.id');

        $lastDates = Rate::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereNotNull('amount')
            ->groupBy('room_type_id')
            ->selectRaw('room_type_id, max(date) as last_date')
            ->pluck('last_date', 'room_type_id');

        return $roomTypeIds->mapWithKeys(fn (int $id): array => [$id => $lastDates->get($id)])->all();
    }

    /**
     * The same as `pricedUntil()` for many plans in one query. A room type the plan does not sell may appear, so
     * read only the room types you know the plan sells.
     *
     * @param  list<int>  $ratePlanIds
     * @return array<int, array<int, string>> Last priced date as `Y-m-d`, keyed by plan id then room type id.
     */
    public function pricedUntilByPlan(array $ratePlanIds): array
    {
        $lastDates = [];

        Rate::query()
            ->whereIn('rate_plan_id', $ratePlanIds)
            ->whereNotNull('amount')
            ->groupBy('rate_plan_id', 'room_type_id')
            ->selectRaw('rate_plan_id, room_type_id, max(date) as last_date')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$lastDates): void {
                $lastDates[$row->rate_plan_id][$row->room_type_id] = $row->last_date;
            });

        return $lastDates;
    }

    /**
     * Whether prices that end on this date run out within the warning window.
     */
    public function isEnding(?string $pricedUntil, CarbonInterface $today): bool
    {
        return $pricedUntil === null
            || Date::parse($pricedUntil)->lt($today->startOfDay()->addDays(self::WARNING_DAYS));
    }
}
