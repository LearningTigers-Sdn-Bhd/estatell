<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Enums\Weekday;
use App\Events\RatesChanged;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Packages\PackagePriceOverlap;
use Illuminate\Support\Facades\DB;

class SavePackagePricing
{
    public function __construct(private PackagePriceOverlap $overlap) {}

    /**
     * Replace a package plan's stay prices and arrival weekdays. Price rows with an `id` are updated, rows without one
     * are added, and rows left out are removed. A plan that can start on all seven weekdays stores none, which means
     * any day.
     *
     * A package that follows another has no prices of its own, so only its arrival weekdays save.
     *
     * Returns false for an archived plan, a plan that is not a package, prices on a package that follows another, a price row whose `id` is not the plan's, a room
     * type the plan does not sell, or periods that overlap for one room type. A save that changes nothing is not logged.
     *
     * @param  list<array{id: int|null, room_type_id: int, arrival_from: string, arrival_to: string, amount: string|int|float}>  $prices  Dates as `Y-m-d`.
     * @param  list<string>  $weekdays
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $prices, array $weekdays): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $prices, $weekdays): bool {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->pricing_basis !== PricingBasis::Package || ($ratePlan->parent_rate_plan_id !== null && $prices !== [])) {
                return false;
            }

            $existing = $ratePlan->packagePrices()->get()->keyBy('id');
            $givenIds = array_filter(array_column($prices, 'id'), fn (?int $id): bool => $id !== null);
            $linked = $ratePlan->roomTypes()->pluck('room_types.id')->all();

            if (
                array_diff($givenIds, $existing->keys()->all()) !== []
                || array_diff(array_column($prices, 'room_type_id'), $linked) !== []
                || $this->overlap->firstOverlap($prices) !== null
            ) {
                return false;
            }

            $changed = false;

            foreach ($prices as $price) {
                $row = $price['id'] === null ? null : $existing->get($price['id']);
                $attributes = [
                    'room_type_id' => $price['room_type_id'],
                    'arrival_from' => $price['arrival_from'],
                    'arrival_to' => $price['arrival_to'],
                    'amount' => number_format((float) $price['amount'], 2, '.', ''),
                ];

                if ($row === null) {
                    $ratePlan->packagePrices()->create($attributes);
                    $changed = true;
                } elseif (
                    $row->room_type_id !== $attributes['room_type_id']
                    || $row->arrival_from->toDateString() !== $attributes['arrival_from']
                    || $row->arrival_to->toDateString() !== $attributes['arrival_to']
                    || $row->amount !== $attributes['amount']
                ) {
                    $row->update($attributes);
                    $changed = true;
                }
            }

            $removed = $existing->keys()->diff($givenIds);

            if ($removed->isNotEmpty()) {
                $ratePlan->packagePrices()->whereIn('id', $removed->all())->delete();
                $changed = true;
            }

            if ($this->saveWeekdays($ratePlan, $weekdays)) {
                $changed = true;
            }

            if ($changed) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rate_plan.package_pricing_updated')
                    ->withProperties(['property_id' => $ratePlan->property_id])
                    ->log('Package pricing updated');
            }

            event(RatesChanged::forPlan($ratePlan));

            return true;
        });
    }

    /**
     * @param  list<string>  $weekdays
     */
    private function saveWeekdays(RatePlan $ratePlan, array $weekdays): bool
    {
        $wanted = array_values(array_unique($weekdays));
        $wanted = count($wanted) === count(Weekday::cases()) ? [] : $wanted;
        $current = $ratePlan->arrivalWeekdays()->get()->map(fn ($row): string => $row->weekday->value)->all();

        if (array_diff($wanted, $current) === [] && array_diff($current, $wanted) === []) {
            return false;
        }

        $ratePlan->arrivalWeekdays()->whereNotIn('weekday', $wanted)->delete();

        foreach (array_diff($wanted, $current) as $weekday) {
            $ratePlan->arrivalWeekdays()->create(['weekday' => $weekday]);
        }

        return true;
    }
}
