<?php

namespace App\Actions\Rates;

use App\Events\RatesChanged;
use App\Models\Rate;
use App\Models\RateHourPrice;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use Illuminate\Support\Collection;

class UpsertRates
{
    /**
     * Write full daily rate rows for one plan in bulk, and replace the occupancy prices of the rates named in `$occupancy`.
     *
     * Each row holds every column of a rate. The caller merges new values over the existing row first.
     * `$occupancy` is keyed `{room_type_id}|{Y-m-d}`. A key with an empty list clears that rate's occupancy prices.
     * Returns the number of rate rows written.
     *
     * @param  list<array{room_type_id: int, date: string, amount: string|null, min_stay: int|null, max_stay: int|null, closed_to_arrival: bool, closed_to_departure: bool, stop_sell: bool}>  $rows
     * @param  array<string, list<array{adults: int, amount: string}>>  $occupancy
     * @param  array<string, list<array{hours: int, amount: string}>>  $hours  Day use hour prices, keyed like `$occupancy`. A key replaces the stored hours from `$minHours` to `$maxHours` and keeps the others.
     */
    public function __invoke(int $ratePlanId, array $rows, array $occupancy = [], array $hours = [], ?int $minHours = null, ?int $maxHours = null): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            Rate::query()->upsert(
                array_map(fn (array $row): array => $row + [
                    'rate_plan_id' => $ratePlanId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['room_type_id', 'rate_plan_id', 'date'],
                ['amount', 'min_stay', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell', 'updated_at'],
            );
        }

        if ($occupancy !== []) {
            $this->replaceOccupancyPrices($ratePlanId, $rows, $occupancy, $now);
        }

        if ($hours !== []) {
            $this->replaceHourPrices($ratePlanId, $rows, $hours, $minHours ?? 1, $maxHours ?? 24, $now);
        }

        $roomTypeIds = array_unique(array_column($rows, 'room_type_id'));
        $dates = array_column($rows, 'date');
        RatesChanged::dispatch(RatePlan::query()->whereKey($ratePlanId)->value('property_id'), min($dates), max($dates), count($roomTypeIds) === 1 ? $roomTypeIds[0] : null, $ratePlanId);

        return count($rows);
    }

    /**
     * @param  list<array{room_type_id: int, date: string}>  $rows
     * @param  array<string, list<array{hours: int, amount: string}>>  $hours
     */
    private function replaceHourPrices(int $ratePlanId, array $rows, array $hours, int $minHours, int $maxHours, mixed $now): void
    {
        $rateIds = $this->rateIds($ratePlanId, $rows);
        $replaced = [];
        $inserts = [];

        foreach ($hours as $key => $prices) {
            $rateId = $rateIds->get($key);

            if ($rateId === null) {
                continue;
            }

            $replaced[] = $rateId;

            foreach ($prices as $price) {
                $inserts[] = [
                    'rate_id' => $rateId,
                    'hours' => $price['hours'],
                    'amount' => $price['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($replaced, 1000) as $chunk) {
            RateHourPrice::query()->whereIn('rate_id', $chunk)->whereBetween('hours', [$minHours, $maxHours])->delete();
        }

        foreach (array_chunk($inserts, 1000) as $chunk) {
            RateHourPrice::query()->upsert($chunk, ['rate_id', 'hours'], ['amount', 'updated_at']);
        }
    }

    /**
     * @param  list<array{room_type_id: int, date: string}>  $rows
     * @return Collection<string, int>
     */
    private function rateIds(int $ratePlanId, array $rows): Collection
    {
        $dates = collect($rows)->pluck('date');
        $rateIds = [];

        $rates = Rate::query()
            ->where('rate_plan_id', $ratePlanId)
            ->whereIn('room_type_id', array_unique(array_column($rows, 'room_type_id')))
            ->whereBetween('date', [$dates->min(), $dates->max()])
            ->get(['id', 'room_type_id', 'date']);

        foreach ($rates as $rate) {
            $rateIds[$rate->room_type_id.'|'.$rate->date->toDateString()] = $rate->id;
        }

        return collect($rateIds);
    }

    /**
     * @param  list<array{room_type_id: int, date: string}>  $rows
     * @param  array<string, list<array{adults: int, amount: string}>>  $occupancy
     */
    private function replaceOccupancyPrices(int $ratePlanId, array $rows, array $occupancy, mixed $now): void
    {
        $rateIds = $this->rateIds($ratePlanId, $rows);
        $replaced = [];
        $inserts = [];

        foreach ($occupancy as $key => $prices) {
            $rateId = $rateIds->get($key);

            if ($rateId === null) {
                continue;
            }

            $replaced[] = $rateId;

            foreach ($prices as $price) {
                $inserts[] = [
                    'rate_id' => $rateId,
                    'adults' => $price['adults'],
                    'amount' => $price['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($replaced, 1000) as $chunk) {
            RateOccupancyPrice::query()->whereIn('rate_id', $chunk)->delete();
        }

        foreach (array_chunk($inserts, 1000) as $chunk) {
            RateOccupancyPrice::query()->insert($chunk);
        }
    }
}
