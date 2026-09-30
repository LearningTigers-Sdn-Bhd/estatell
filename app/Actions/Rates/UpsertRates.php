<?php

namespace App\Actions\Rates;

use App\Models\Rate;
use App\Models\RateOccupancyPrice;

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
     */
    public function __invoke(int $ratePlanId, array $rows, array $occupancy = []): int
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

        return count($rows);
    }

    /**
     * @param  list<array{room_type_id: int, date: string}>  $rows
     * @param  array<string, list<array{adults: int, amount: string}>>  $occupancy
     */
    private function replaceOccupancyPrices(int $ratePlanId, array $rows, array $occupancy, mixed $now): void
    {
        $rateIds = Rate::query()
            ->where('rate_plan_id', $ratePlanId)
            ->whereIn('room_type_id', array_unique(array_column($rows, 'room_type_id')))
            ->whereBetween('date', [min(array_column($rows, 'date')), max(array_column($rows, 'date'))])
            ->get(['id', 'room_type_id', 'date'])
            ->mapWithKeys(fn (Rate $rate): array => [$rate->room_type_id.'|'.$rate->date->toDateString() => $rate->id]);

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
