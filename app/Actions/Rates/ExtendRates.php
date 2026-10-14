<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class ExtendRates
{
    public function __construct(private UpsertRates $upsertRates) {}

    /**
     * Copy the last 7 priced days of every linked room type forward by 12 months.
     *
     * Each weekday takes the amount, occupancy prices, hour prices, min stay and max stay of its latest source date.
     * Stop sell and closed-to-arrival/departure are date-specific and are not copied. Existing ones on
     * the new dates stay. A room type with no price yet is skipped.
     *
     * Returns the number of dates written, or false for an archived, package or derived plan.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, CarbonInterface $today): int|false
    {
        return DB::transaction(function () use ($actor, $ratePlan, $today): int|false {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->pricing_basis === PricingBasis::Package || $ratePlan->parent_rate_plan_id !== null) {
                return false;
            }

            $lastDates = Rate::query()
                ->where('rate_plan_id', $ratePlan->id)
                ->whereIn('room_type_id', $ratePlan->roomTypes()->whereNull('room_types.archived_at')->pluck('room_types.id'))
                ->priced()
                ->groupBy('room_type_id')
                ->selectRaw('room_type_id, max(date) as last_date')
                ->pluck('last_date', 'room_type_id');

            $rows = [];
            $occupancy = [];
            $hours = [];

            foreach ($lastDates as $roomTypeId => $lastDate) {
                $last = Date::parse($lastDate)->startOfDay();
                $start = $last->addDay()->max($today->startOfDay());
                $end = $start->addMonthsNoOverflow(12)->subDay();

                $sources = Rate::query()
                    ->with('occupancyPrices', 'hourPrices')
                    ->where('rate_plan_id', $ratePlan->id)
                    ->where('room_type_id', $roomTypeId)
                    ->whereBetween('date', [$last->subDays(6)->toDateString(), $last->toDateString()])
                    ->get()
                    ->keyBy(fn (Rate $rate): int => $rate->date->dayOfWeekIso);

                $existing = Rate::query()
                    ->where('rate_plan_id', $ratePlan->id)
                    ->where('room_type_id', $roomTypeId)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->get()
                    ->keyBy(fn (Rate $rate): string => $rate->date->toDateString());

                for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                    $source = $sources->get($date->dayOfWeekIso);

                    if ($source === null) {
                        continue;
                    }

                    $current = $existing->get($date->toDateString());

                    $rows[] = [
                        'room_type_id' => $roomTypeId,
                        'date' => $date->toDateString(),
                        'amount' => $source->amount,
                        'min_stay' => $source->min_stay,
                        'max_stay' => $source->max_stay,
                        'closed_to_arrival' => $current->closed_to_arrival ?? false,
                        'closed_to_departure' => $current->closed_to_departure ?? false,
                        'stop_sell' => $current->stop_sell ?? false,
                    ];
                    $occupancy[$roomTypeId.'|'.$date->toDateString()] = array_values($source->occupancyPrices
                        ->map(fn ($price): array => ['adults' => $price->adults, 'amount' => $price->amount])
                        ->all());

                    if ($source->hourPrices->isNotEmpty()) {
                        $hours[$roomTypeId.'|'.$date->toDateString()] = array_values($source->hourPrices
                            ->map(fn ($price): array => ['hours' => $price->hours, 'amount' => $price->amount])
                            ->all());
                    }
                }
            }

            $written = ($this->upsertRates)($ratePlan->id, $rows, $occupancy, $hours, $ratePlan->day_use_min_hours, $ratePlan->day_use_max_hours);

            if ($written > 0) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rates.extended')
                    ->withProperties(['property_id' => $ratePlan->property_id, 'dates' => $written])
                    ->log('Rates extended');
            }

            return $written;
        });
    }
}
