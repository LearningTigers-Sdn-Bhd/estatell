<?php

namespace App\Services\Reports;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The overnight unit nights of each date, counted in one query: how many (a room or a bed is one each), how many are
 * on a complimentary rate plan, and the adults and children who sleep them. The statuses say which units count: the
 * ones that stayed for a past date, the ones that hold inventory for a forecast. See
 * docs/22-reports/04-history-and-forecast.md.
 */
class BookedNightCounts
{
    /**
     * @param  list<UnitStatus>  $statuses
     * @return array<string, array{sold: int, complimentary: int, pax: int}> Keyed by `Y-m-d`; a date with no night is not in the list.
     */
    public function between(Property $property, CarbonImmutable $from, CarbonImmutable $to, array $statuses): array
    {
        return DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->join('rate_plans', 'rate_plans.id', '=', 'unit_nights.rate_plan_id')
            ->where('reservation_units.property_id', $property->id)
            ->where('reservation_units.stay_type', StayType::Overnight->value)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, $statuses))
            ->whereBetween('unit_nights.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('unit_nights.date')
            ->selectRaw('unit_nights.date as date, COUNT(*) as sold, COUNT(*) FILTER (WHERE rate_plans.is_complimentary) as complimentary, SUM(reservation_units.adults + reservation_units.children) as pax')
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse((string) $row->date)->toDateString() => [
                'sold' => (int) $row->sold,
                'complimentary' => (int) $row->complimentary,
                'pax' => (int) $row->pax,
            ]])
            ->all();
    }
}
