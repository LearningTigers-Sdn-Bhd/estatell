<?php

namespace App\Services\Rates;

use App\Models\Property;
use App\Models\Rate;
use Illuminate\Support\Facades\Date;

/**
 * The saved prices the bulk edit shows as placeholders and changes by a percent or an amount.
 */
class RateBulkSavedPrices
{
    public const DEFAULT_DAYS = 28;

    /**
     * For each plan and room type, the saved price of the first date of the range on each ISO weekday (1 = Monday).
     * A weekday with no price in the range is null. With no range, the next 28 days are used.
     *
     * @return array<string, array<int, string|null>> Keyed `{plan id}|{room type id}`, then weekday.
     */
    public function forRange(Property $property, ?string $from, ?string $to, string $today): array
    {
        $first = Date::parse($from ?? $today)->startOfDay();
        $last = $from !== null && $to !== null ? Date::parse($to)->startOfDay() : $first->addDays(self::DEFAULT_DAYS - 1);
        $prices = [];

        Rate::query()
            ->whereIn('rate_plan_id', $property->ratePlans()->active()->pluck('rate_plans.id'))
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])
            ->whereNotNull('amount')
            ->orderBy('date')
            ->toBase()
            ->get(['rate_plan_id', 'room_type_id', 'date', 'amount'])
            ->each(function (object $row) use (&$prices): void {
                $key = $row->rate_plan_id.'|'.$row->room_type_id;
                $weekday = Date::parse($row->date)->dayOfWeekIso;

                $prices[$key] ??= array_fill(1, 7, null);
                $prices[$key][$weekday] ??= $row->amount;
            });

        return $prices;
    }
}
