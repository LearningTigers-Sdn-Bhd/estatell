<?php

namespace App\Actions\Rates;

use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\ChargeItem;
use App\Models\MealPeriod;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRatePlanInclusions
{
    /**
     * Replace the plan's inclusions with the given list, in order. Rows with an `id` are updated, rows without one
     * are added, and rows left out are removed.
     *
     * Returns false for an archived plan, an `id` that is not the plan's, or a charge item or meal period that is not
     * the property's. An inactive charge item or meal period is refused unless the plan already uses it. A save that
     * changes nothing is not logged.
     *
     * @param  list<array{id: int|null, charge_item_id: int, meal_period_id: int|null, charging_unit: string, quantity: int, allocated_amount: string|int|float, starts_on: string, ends_on: string}>  $inclusions
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $inclusions): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $inclusions): bool {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return false;
            }

            $existing = $ratePlan->inclusions()->get()->keyBy('id');
            $givenIds = array_filter(array_column($inclusions, 'id'), fn (?int $id): bool => $id !== null);

            if (array_diff($givenIds, $existing->keys()->all()) !== []) {
                return false;
            }

            $chargeItems = ChargeItem::query()->where('property_id', $ratePlan->property_id)
                ->whereIn('id', array_column($inclusions, 'charge_item_id'))->get()->keyBy('id');
            $mealPeriods = MealPeriod::query()->where('property_id', $ratePlan->property_id)
                ->whereIn('id', array_filter(array_column($inclusions, 'meal_period_id')))->get()->keyBy('id');
            $changed = false;

            foreach ($inclusions as $position => $inclusion) {
                $row = $inclusion['id'] === null ? null : $existing->get($inclusion['id']);
                $chargeItem = $chargeItems->get($inclusion['charge_item_id']);
                $mealPeriod = $inclusion['meal_period_id'] === null ? null : $mealPeriods->get($inclusion['meal_period_id']);

                if ($chargeItem === null || ($inclusion['meal_period_id'] !== null && $mealPeriod === null)) {
                    return false;
                }

                if (! $chargeItem->active && $row?->charge_item_id !== $chargeItem->id) {
                    return false;
                }

                if ($mealPeriod !== null && ! $mealPeriod->active && $row?->meal_period_id !== $mealPeriod->id) {
                    return false;
                }

                $attributes = [
                    'charge_item_id' => $chargeItem->id,
                    'meal_period_id' => $mealPeriod?->id,
                    'charging_unit' => ChargingUnit::from($inclusion['charging_unit']),
                    'quantity' => $inclusion['quantity'],
                    'allocated_amount' => number_format((float) $inclusion['allocated_amount'], 2, '.', ''),
                    'starts_on' => InclusionStart::from($inclusion['starts_on']),
                    'ends_on' => InclusionEnd::from($inclusion['ends_on']),
                    'position' => $position,
                ];

                if ($row === null) {
                    $ratePlan->inclusions()->create($attributes);
                    $changed = true;
                } elseif ($this->differs($row->only(array_keys($attributes)), $attributes)) {
                    $row->update($attributes);
                    $changed = true;
                }
            }

            $removed = $existing->keys()->diff($givenIds);

            if ($removed->isNotEmpty()) {
                $ratePlan->inclusions()->whereIn('id', $removed->all())->delete();
                $changed = true;
            }

            if ($changed) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rate_plan.inclusions_updated')
                    ->withProperties(['property_id' => $ratePlan->property_id])
                    ->log('Rate plan inclusions updated');
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $wanted
     */
    private function differs(array $current, array $wanted): bool
    {
        foreach ($wanted as $key => $value) {
            if ($current[$key] !== $value) {
                return true;
            }
        }

        return false;
    }
}
