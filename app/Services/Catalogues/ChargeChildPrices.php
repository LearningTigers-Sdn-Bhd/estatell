<?php

namespace App\Services\Catalogues;

use App\Enums\AgeBandPricing;
use App\Models\ChargeItem;
use App\Models\PropertyAgeGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Splits the children of a unit by the child prices of a per-guest charge item, and prices a child row.
 * See docs/06-catalogues/02-charge-items.md#child-prices.
 */
class ChargeChildPrices
{
    /**
     * Returns null when the item charges every guest the same: it is not a per-guest item, or it has no child
     * prices. Load nothing first; the item's `childPrices` and the property's age groups are read here.
     *
     * @param  list<int>  $childAges
     */
    public function split(ChargeItem $item, array $childAges, int $children): ?ChildPriceSplit
    {
        if (! $item->charging_unit->pricesChildren() || $children === 0) {
            return null;
        }

        $prices = $item->childPrices()->get()->keyBy('property_age_group_id');

        if ($prices->isEmpty()) {
            return null;
        }

        $ageGroups = PropertyAgeGroup::query()->where('property_id', $item->property_id)->ordered()->get();
        $counts = [];

        foreach ($childAges as $age) {
            $group = $ageGroups->first(fn (PropertyAgeGroup $group): bool => $age >= $group->min_age && $age <= $group->max_age);

            if ($group !== null) {
                $counts[$group->id] = ($counts[$group->id] ?? 0) + 1;
            }
        }

        $groups = [];

        foreach ($ageGroups as $group) {
            $count = $counts[$group->id] ?? 0;
            $price = $prices->get($group->id);

            if ($count === 0 || $price === null || $price->pricing === AgeBandPricing::Free) {
                continue;
            }

            $groups[] = ['age_group_id' => $group->id, 'count' => $count, 'pricing' => $price->pricing, 'value' => $price->value];
        }

        // Children with no group, no price row or no recorded age pay the adult price. Free groups pay nothing.
        $priced = 0;

        foreach ($counts as $groupId => $count) {
            if ($prices->has($groupId)) {
                $priced += $count;
            }
        }

        return new ChildPriceSplit(max(0, $children - $priced), $groups);
    }

    /**
     * The unit amount of one resolved row: the adult amount, or the child price of its age group.
     *
     * @param  array{pricing: AgeBandPricing, value: string|null}|null  $childPrice  The row's `child_price`.
     */
    public function rowAmount(?array $childPrice, Money $adultAmount, ?Money $defaultAmount): Money
    {
        return $childPrice === null
            ? $adultAmount
            : $this->amount($childPrice['pricing'], $childPrice['value'], $adultAmount, $defaultAmount);
    }

    /**
     * The unit amount of a child row. A percent is of the default amount, or of the adult amount when the item is
     * priced when charging.
     */
    public function amount(AgeBandPricing $pricing, ?string $value, Money $adultAmount, ?Money $defaultAmount): Money
    {
        if ($pricing === AgeBandPricing::Fixed) {
            return Money::of($value ?? '0', $adultAmount->getCurrency(), roundingMode: RoundingMode::Down);
        }

        return ($defaultAmount ?? $adultAmount)->multipliedBy(BigDecimal::of($value ?? '100')->dividedBy(100, 4, RoundingMode::Down), RoundingMode::Down);
    }
}
