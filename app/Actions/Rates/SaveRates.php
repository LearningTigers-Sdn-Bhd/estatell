<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class SaveRates
{
    public function __construct(private UpsertRates $upsertRates) {}

    /**
     * Save prices and restrictions for a date range, one room type at a time.
     *
     * `$days` of a room type are keyed by ISO weekday (1 = Monday). A weekday with no amount leaves its price alone.
     * A weekday with an amount sets the price and replaces the occupancy prices of every date it covers.
     * `$restrictions` holds only the restriction columns to change. They apply to every date of the range,
     * and dates with no rate row yet get one.
     *
     * Returns the number of dates written, or false for an archived plan, a room type the plan does not sell,
     * an amount on a package plan or a derived plan, or a range or restriction set that would leave a min stay above a max stay.
     *
     * @param  list<array{room_type_id: int, days: array<int, array{amount: string|null, occupancy: list<array{adults: int, amount: string}>}>}>  $roomTypes
     * @param  array{min_stay?: int|null, max_stay?: int|null, closed_to_arrival?: bool, closed_to_departure?: bool, stop_sell?: bool}  $restrictions
     */
    public function __invoke(User $actor, RatePlan $ratePlan, string $from, string $to, array $roomTypes, array $restrictions = []): int|false
    {
        return DB::transaction(function () use ($actor, $ratePlan, $from, $to, $roomTypes, $restrictions): int|false {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);
            $first = Date::parse($from)->startOfDay();
            $last = Date::parse($to)->startOfDay();

            if ($ratePlan->archived_at !== null || $last->lt($first)) {
                return false;
            }

            $roomTypeIds = array_column($roomTypes, 'room_type_id');
            $linkedIds = $ratePlan->roomTypes()->whereNull('room_types.archived_at')->pluck('room_types.id')->all();

            if ($roomTypeIds === [] || array_diff($roomTypeIds, $linkedIds) !== []) {
                return false;
            }

            $existing = Rate::query()
                ->where('rate_plan_id', $ratePlan->id)
                ->whereIn('room_type_id', $roomTypeIds)
                ->whereBetween('date', [$first->toDateString(), $last->toDateString()])
                ->get()
                ->keyBy(fn (Rate $rate): string => $rate->room_type_id.'|'.$rate->date->toDateString());

            $rows = [];
            $occupancy = [];

            foreach ($roomTypes as $roomType) {
                for ($date = $first; $date->lte($last); $date = $date->addDay()) {
                    $day = $roomType['days'][$date->dayOfWeekIso] ?? null;
                    $amount = $day['amount'] ?? null;

                    if ($amount === null && $restrictions === []) {
                        continue;
                    }

                    if ($amount !== null && ($ratePlan->pricing_basis === PricingBasis::Package || $ratePlan->parent_rate_plan_id !== null)) {
                        return false;
                    }

                    $key = $roomType['room_type_id'].'|'.$date->toDateString();
                    $current = $existing->get($key);

                    $row = [
                        'room_type_id' => $roomType['room_type_id'],
                        'date' => $date->toDateString(),
                        'amount' => $amount === null ? $current?->amount : number_format((float) $amount, 2, '.', ''),
                        'min_stay' => $current?->min_stay,
                        'max_stay' => $current?->max_stay,
                        'closed_to_arrival' => $current?->closed_to_arrival ?? false,
                        'closed_to_departure' => $current?->closed_to_departure ?? false,
                        'stop_sell' => $current?->stop_sell ?? false,
                    ];
                    $row = array_merge($row, $restrictions);

                    if ($row['min_stay'] !== null && $row['max_stay'] !== null && $row['max_stay'] < $row['min_stay']) {
                        return false;
                    }

                    $rows[] = $row;

                    if ($amount !== null) {
                        $occupancy[$key] = array_map(
                            fn (array $price): array => [
                                'adults' => $price['adults'],
                                'amount' => number_format((float) $price['amount'], 2, '.', ''),
                            ],
                            $day['occupancy'],
                        );
                    }
                }
            }

            $written = ($this->upsertRates)($ratePlan->id, $rows, $occupancy);

            if ($written > 0) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rates.updated')
                    ->withProperties([
                        'property_id' => $ratePlan->property_id,
                        'from' => $first->toDateString(),
                        'to' => $last->toDateString(),
                        'room_type_ids' => $roomTypeIds,
                        'dates' => $written,
                    ])
                    ->log('Rates updated');
            }

            return $written;
        });
    }
}
