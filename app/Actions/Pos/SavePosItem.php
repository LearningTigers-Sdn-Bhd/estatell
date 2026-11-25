<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Enums\PosPricingType;
use App\Enums\TransactionKind;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a menu item with its variants. An item always keeps at least one active variant (`variant_required`),
 * because the variant holds the price. Variants sent with an `id` are updated, new ones are created, and variants left
 * out are deactivated, never deleted, since order lines point at them. The category, transaction code and meal period
 * must be the property's, and a SKU or barcode is unique per property (`sku_taken`, `barcode_taken`). A refusal writes nothing.
 * See docs/34-native-pos/02-menu-catalog.md.
 */
class SavePosItem
{
    public function __invoke(User $actor, Property $property, PosItemInputData $data, ?PosItem $item = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $data, $item): PosActionResult {
            if ($item !== null && $item->property_id !== $property->id) {
                return PosActionResult::refused('item_invalid');
            }

            $refusal = $this->refusal($property, $data, $item);

            if ($refusal !== null) {
                return PosActionResult::refused($refusal);
            }

            $created = $item === null;
            $item ??= new PosItem;
            $item->fill([
                'pos_category_id' => $data->posCategoryId,
                'transaction_code_id' => $data->transactionCodeId,
                'meal_period_id' => $data->mealPeriodId,
                'name' => trim($data->name),
                'internal_name' => $data->internalName,
                'description' => $data->description,
                'sku_prefix' => $data->skuPrefix,
                'price_includes_tax' => $data->priceIncludesTax,
                'service_charge_exempt' => $data->serviceChargeExempt,
                'cooking_guide' => $data->cookingGuide,
                'sold_by_weight' => $data->soldByWeight,
                'weight_unit' => $data->soldByWeight ? trim((string) $data->weightUnit) : null,
                'weight_unit_divisor' => $data->soldByWeight ? $data->weightUnitDivisor : null,
                'sort_order' => $data->sortOrder,
                'active' => $data->active,
            ]);
            $item->property_id = $property->id;
            $item->save();

            $this->saveVariants($property, $item, $data->variants);

            activity()->performedOn($item)->causedBy($actor)->event($created ? 'pos.item_created' : 'pos.item_updated')
                ->withProperties(['property_id' => $property->id, 'name' => $item->name])
                ->log($created ? 'POS item created' : 'POS item updated');

            return PosActionResult::saved($item);
        });
    }

    private function refusal(Property $property, PosItemInputData $data, ?PosItem $item): ?string
    {
        if (! PosCategory::query()->where('property_id', $property->id)->whereKey($data->posCategoryId)->exists()) {
            return 'category_invalid';
        }

        if ($data->transactionCodeId !== null && ! TransactionCode::query()->where('property_id', $property->id)->where('kind', TransactionKind::Charge->value)->whereKey($data->transactionCodeId)->exists()) {
            return 'transaction_code_invalid';
        }

        if ($data->mealPeriodId !== null && ! MealPeriod::query()->where('property_id', $property->id)->whereKey($data->mealPeriodId)->exists()) {
            return 'meal_period_invalid';
        }

        if ($data->soldByWeight && (trim((string) $data->weightUnit) === '' || $data->weightUnitDivisor === null || preg_match('/^\d{1,7}(\.\d{1,3})?$/', $data->weightUnitDivisor) !== 1 || ! BigDecimal::of($data->weightUnitDivisor)->isPositive())) {
            return 'weight_unit_required';
        }

        if ($data->soldByWeight && array_any($data->variants, fn (PosItemVariantInputData $variant): bool => $variant->pricingType === PosPricingType::Variable)) {
            return 'weight_price_fixed';
        }

        if (array_all($data->variants, fn (PosItemVariantInputData $variant): bool => ! $variant->active)) {
            return 'variant_required';
        }

        $ownIds = $item === null ? [] : $item->variants()->pluck('id')->all();

        foreach ($data->variants as $variant) {
            if ($variant->id !== null && ! in_array($variant->id, $ownIds, true)) {
                return 'variant_invalid';
            }

            if (BigDecimal::of($variant->price)->isNegative()) {
                return 'price_invalid';
            }
        }

        foreach (['sku' => 'sku_taken', 'barcode' => 'barcode_taken'] as $column => $code) {
            $values = array_filter(array_map(fn (PosItemVariantInputData $variant): ?string => $variant->{$column} === '' ? null : $variant->{$column}, $data->variants));

            if (count($values) !== count(array_unique($values))) {
                return $code;
            }

            $keptIds = array_filter(array_map(fn (PosItemVariantInputData $variant): ?int => $variant->id, $data->variants));

            $taken = $values !== [] && PosItemVariant::query()
                ->where('property_id', $property->id)
                ->whereIn($column, $values)
                ->whereNotIn('id', $keptIds)
                ->exists();

            if ($taken) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  list<PosItemVariantInputData>  $variants
     */
    private function saveVariants(Property $property, PosItem $item, array $variants): void
    {
        $keptIds = [];

        foreach ($variants as $input) {
            $variant = $input->id === null ? new PosItemVariant : $item->variants()->findOrFail($input->id);
            $variant->fill([
                'name' => trim($input->name) === '' ? 'Regular' : trim($input->name),
                'sku' => $input->sku === '' ? null : $input->sku,
                'barcode' => $input->barcode === '' ? null : $input->barcode,
                'pricing_type' => $input->pricingType,
                'price' => $input->price,
                'cost' => $input->cost,
                'sort_order' => $input->sortOrder,
                'active' => $input->active,
            ]);
            $variant->property_id = $property->id;
            $variant->pos_item_id = $item->id;
            $variant->save();
            $keptIds[] = $variant->id;
        }

        $item->variants()->whereNotIn('id', $keptIds)->update(['active' => false]);
    }
}
