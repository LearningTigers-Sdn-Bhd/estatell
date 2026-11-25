<?php

namespace App\Services\Pos;

use App\Enums\PosAvailability;
use App\Models\PosCategory;
use App\Models\PosComboSlot;
use App\Models\PosComboSlotOption;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosModifierGroup;
use App\Models\PosModifierOption;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use Closure;
use Illuminate\Support\Collection;

/**
 * The bounded catalog one outlet sells from, built in a fixed number of queries so the POS register filters categories and
 * searches items in the browser with no server request. It holds only what the register may sell: active categories, items
 * and variants, choices that are not switched off, and the outlet's own prices (`price_override` replaces the variant
 * price). Stock counts are carried so the register can warn; it never blocks a sale. Items offered to the QR menu only
 * would need a channel flag, which arrives with QR ordering. It reads only. The `version` changes whenever the catalog does.
 * See docs/34-native-pos/02-menu-catalog.md.
 */
class OutletMenuSnapshot
{
    public function __construct(private PosMenuAvailability $availability) {}

    /**
     * @return array<string, mixed> `version`, `outlet`, `categories`, `diningOptions` and `paymentMethods`.
     */
    public function __invoke(PosOutlet $outlet): array
    {
        $categories = PosCategory::query()
            ->where('property_id', $outlet->property_id)
            ->where('active', true)
            ->with([
                'items' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('name')->with([
                    'comboSlots.options.variant.item',
                    'variants' => fn ($variants) => $variants->where('active', true)->orderBy('sort_order')->orderBy('id'),
                    'modifierGroups' => fn ($groups) => $groups->where('pos_modifier_groups.active', true)->orderBy('pos_item_modifier_group.sort_order')->with([
                        'options' => fn ($options) => $options->where('active', true)->where('availability', '!=', PosAvailability::None->value)->orderBy('sort_order')->orderBy('id'),
                    ]),
                ]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $sellable = $this->availability->checker($outlet);
        $outletItems = PosOutletItem::query()->where('pos_outlet_id', $outlet->id)->get()->keyBy('pos_item_variant_id');

        $payload = [
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name, 'code' => $outlet->code],
            'categories' => $categories
                ->map(fn (PosCategory $category): array => $this->category($category, $outletItems, $sellable))
                ->filter(fn (array $category): bool => $category['items'] !== [])
                ->values()
                ->all(),
            'diningOptions' => $outlet->diningOptions()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($option): array => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'kind' => $option->kind->value,
                    'applyServiceCharge' => $option->apply_service_charge,
                    'serviceChargeRate' => $option->service_charge_rate,
                    'serviceChargeTaxable' => $option->service_charge_taxable,
                    'packagingFee' => $option->packaging_fee,
                    'isDefault' => $option->is_default,
                ])->all(),
            'paymentMethods' => $outlet->paymentMethods()->where('active', true)->with('paymentMethod:id,name')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($method): array => [
                    'id' => $method->id,
                    'paymentMethodId' => $method->payment_method_id,
                    'name' => $method->paymentMethod->name,
                    'tenderType' => $method->tender_type->value,
                    'pulseDrawer' => $method->pulse_drawer,
                ])->all(),
        ];

        return ['version' => md5(json_encode($payload, JSON_THROW_ON_ERROR))] + $payload;
    }

    /**
     * @param  Collection<int, PosOutletItem>  $outletItems
     * @return array<string, mixed>
     */
    private function category(PosCategory $category, $outletItems, Closure $sellable): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'colorToken' => $category->color_token,
            'items' => $category->items
                ->filter(fn (PosItem $item): bool => $sellable($item))
                ->map(fn (PosItem $item): array => $this->item($item, $outletItems))
                ->filter(fn (array $item): bool => $item['variants'] !== [])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, PosOutletItem>  $outletItems
     * @return array<string, mixed>
     */
    private function item(PosItem $item, $outletItems): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'internalName' => $item->internal_name,
            'description' => $item->description,
            'mealPeriodId' => $item->meal_period_id,
            'priceIncludesTax' => $item->price_includes_tax,
            'serviceChargeExempt' => $item->service_charge_exempt,
            'soldByWeight' => $item->sold_by_weight,
            'weightUnit' => $item->weight_unit,
            'weightUnitDivisor' => $item->weight_unit_divisor,
            'isCombo' => $item->is_combo,
            'comboSlots' => $item->is_combo ? $item->comboSlots->map(fn (PosComboSlot $slot): array => [
                'id' => $slot->id,
                'name' => $slot->name,
                'min' => $slot->min_select,
                'max' => $slot->max_select,
                'options' => $slot->options->filter(fn (PosComboSlotOption $option): bool => $option->variant->active && $option->variant->item->active)->map(fn (PosComboSlotOption $option): array => [
                    'variantId' => $option->pos_item_variant_id,
                    'name' => $option->variant->item->name.($option->variant->name === 'Regular' ? '' : ' ('.$option->variant->name.')'),
                    'upcharge' => $option->upcharge,
                ])->values()->all(),
            ])->values()->all() : [],
            'sharedStockCount' => $item->shared_stock_count,
            'variants' => $item->variants
                ->map(fn (PosItemVariant $variant): ?array => $this->variant($item, $variant, $outletItems->get($variant->id)))
                ->filter()
                ->values()
                ->all(),
            'modifierGroups' => $item->modifierGroups
                ->filter(fn (PosModifierGroup $group): bool => $group->options->isNotEmpty() || $group->min_select === 0)
                ->map(fn (PosModifierGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'minSelect' => $group->min_select,
                    'maxSelect' => $group->max_select,
                    'maxPerOption' => $group->max_per_option,
                    'freeIncludedCount' => $group->free_included_count,
                    'options' => $group->options->map(fn (PosModifierOption $option): array => [
                        'id' => $option->id,
                        'name' => $option->name,
                        'internalName' => $option->internal_name,
                        'priceDelta' => $option->price_delta,
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null Null when this outlet does not sell the variant.
     */
    private function variant(PosItem $item, PosItemVariant $variant, ?PosOutletItem $outletItem): ?array
    {
        $availability = $outletItem->availability ?? PosAvailability::All;

        if ($availability === PosAvailability::None) {
            return null;
        }

        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'pricingType' => $variant->pricing_type->value,
            'price' => $outletItem->price_override ?? $variant->price,
            'stockCountdown' => $outletItem?->stock_countdown,
            'soldOut' => ($outletItem?->stock_countdown !== null && $outletItem->stock_countdown <= 0)
                || ($item->shared_stock_count !== null && $item->shared_stock_count <= 0),
        ];
    }
}
