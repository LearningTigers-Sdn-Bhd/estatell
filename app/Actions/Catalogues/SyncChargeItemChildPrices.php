<?php

namespace App\Actions\Catalogues;

use App\Enums\AgeBandPricing;
use App\Models\ChargeItem;
use App\Models\PropertyAgeGroup;

class SyncChargeItemChildPrices
{
    /**
     * Keep a charge item's child prices in step with its charging unit and the property's age groups.
     *
     * - A unit that does not price children has no rows: any rows are deleted.
     * - With `$prices` null, missing rows are added at the default (Percent 100, or Free when the item is priced
     *   when charging) and existing rows are kept.
     * - With `$prices`, there is one row per age group of the property, as sent.
     *
     * Read `charging_unit` and `default_amount` from the given model, so call it after filling them. It writes rows only.
     * Returns whether any row changed.
     *
     * @param  list<array{property_age_group_id: int, pricing: string, value: string|int|float|null}>|null  $prices
     */
    public function __invoke(ChargeItem $chargeItem, ?array $prices): bool
    {
        $existing = $chargeItem->childPrices()->get()->keyBy('property_age_group_id');

        if (! $chargeItem->charging_unit->pricesChildren()) {
            if ($existing->isEmpty()) {
                return false;
            }

            $chargeItem->childPrices()->delete();

            return true;
        }

        $groupIds = PropertyAgeGroup::query()->where('property_id', $chargeItem->property_id)->pluck('id')->all();
        $changed = false;

        if ($prices === null) {
            $priced = $chargeItem->default_amount !== null;

            foreach ($groupIds as $groupId) {
                if (! $existing->has($groupId)) {
                    $chargeItem->childPrices()->create([
                        'property_age_group_id' => $groupId,
                        'pricing' => $priced ? AgeBandPricing::Percent : AgeBandPricing::Free,
                        'value' => $priced ? '100.00' : null,
                    ]);
                    $changed = true;
                }
            }

            return $changed;
        }

        foreach ($prices as $price) {
            $pricing = AgeBandPricing::from($price['pricing']);
            $value = $pricing === AgeBandPricing::Free ? null : number_format((float) $price['value'], 2, '.', '');
            $row = $existing->get($price['property_age_group_id']);

            if ($row === null) {
                $chargeItem->childPrices()->create(['property_age_group_id' => $price['property_age_group_id'], 'pricing' => $pricing, 'value' => $value]);
                $changed = true;
            } elseif ($row->pricing !== $pricing || $row->value !== $value) {
                $row->update(['pricing' => $pricing, 'value' => $value]);
                $changed = true;
            }
        }

        return $changed;
    }
}
