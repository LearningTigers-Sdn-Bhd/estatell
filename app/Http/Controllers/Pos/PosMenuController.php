<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosCategory;
use App\Actions\Pos\SavePosItem;
use App\Actions\Pos\UpdateOutletItemAvailability;
use App\Actions\Pos\UpdateSharedStockCount;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosCategoryInputData;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Data\Property\CurrencyData;
use App\Enums\PosAvailability;
use App\Enums\PosPricingType;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosCategoryRequest;
use App\Http\Requests\Pos\SavePosItemRequest;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PosMenuController extends Controller
{
    use RespondsToPos;

    public function index(Property $property): Response
    {
        $property->loadMissing('settings');
        $categories = PosCategory::query()
            ->where('property_id', $property->id)
            ->with([
                'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
                'items.variants' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'items.variants.outletItems',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $outlets = PosOutlet::query()
            ->where('property_id', $property->id)
            ->where('pos_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('settings/pos/menu', [
            'currency' => CurrencyData::forProperty($property),
            'outlets' => $outlets->map(fn (PosOutlet $outlet): array => ['value' => (string) $outlet->id, 'label' => $outlet->name])->all(),
            'pricingTypes' => PosPricingType::options(),
            'availabilityOptions' => PosAvailability::options(),
            'categories' => $categories->map(fn (PosCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'transactionCodeId' => $category->transaction_code_id,
                'internalName' => $category->internal_name,
                'colorToken' => $category->color_token,
                'sortOrder' => $category->sort_order,
                'active' => $category->active,
                'items' => $category->items->map(fn (PosItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'transactionCodeId' => $item->transaction_code_id,
                    'mealPeriodId' => $item->meal_period_id,
                    'internalName' => $item->internal_name,
                    'description' => $item->description,
                    'priceIncludesTax' => $item->price_includes_tax,
                    'serviceChargeExempt' => $item->service_charge_exempt,
                    'soldByWeight' => $item->sold_by_weight,
                    'weightUnit' => $item->weight_unit,
                    'weightUnitDivisor' => $item->weight_unit_divisor,
                    'sharedStockCount' => $item->shared_stock_count,
                    'dailySharedStockReset' => $item->daily_shared_stock_reset,
                    'sortOrder' => $item->sort_order,
                    'active' => $item->active,
                    'variants' => $item->variants->map(fn (PosItemVariant $variant): array => [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'pricingType' => $variant->pricing_type->value,
                        'price' => $variant->price,
                        'cost' => $variant->cost,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'active' => $variant->active,
                        'outletItems' => $variant->outletItems->map(fn (PosOutletItem $row): array => [
                            'outletId' => $row->pos_outlet_id,
                            'availability' => $row->availability->value,
                            'priceOverride' => $row->price_override,
                            'stockCountdown' => $row->stock_countdown,
                            'dailyStockReset' => $row->daily_stock_reset,
                        ])->values()->all(),
                    ])->all(),
                ])->values()->all(),
            ])->values()->all(),
            'transactionCodes' => TransactionCode::query()->where('property_id', $property->id)->where('kind', TransactionKind::Charge->value)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->all(),
            'mealPeriods' => MealPeriod::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (MealPeriod $period): array => ['value' => (string) $period->id, 'label' => $period->name])->all(),
        ]);
    }

    public function storeCategory(SavePosCategoryRequest $request, Property $property, SavePosCategory $savePosCategory): RedirectResponse
    {
        $this->toast($savePosCategory($request->user(), $property, $this->category($request))->refusal, __('Category added.'));

        return back();
    }

    public function updateCategory(SavePosCategoryRequest $request, Property $property, int $category, SavePosCategory $savePosCategory): RedirectResponse
    {
        $model = PosCategory::query()->where('property_id', $property->id)->findOrFail($category);
        $this->toast($savePosCategory($request->user(), $property, $this->category($request), $model)->refusal, __('Category saved.'));

        return back();
    }

    public function storeItem(SavePosItemRequest $request, Property $property, SavePosItem $savePosItem, UpdateSharedStockCount $updateSharedStock): RedirectResponse
    {
        $result = $savePosItem($request->user(), $property, $this->item($request));

        if (! $result->isRefused() && $result->model instanceof PosItem && ($request->has('shared_stock_count') || $request->has('daily_shared_stock_reset'))) {
            $count = $request->validated('shared_stock_count');
            $reset = $request->validated('daily_shared_stock_reset');
            $updateSharedStock($request->user(), $property, $result->model, $count === null ? null : (int) $count, $reset === null ? null : (int) $reset);
        }

        $this->toast($result->refusal, __('Item added.'));

        return back();
    }

    public function updateItem(SavePosItemRequest $request, Property $property, int $item, SavePosItem $savePosItem, UpdateSharedStockCount $updateSharedStock): RedirectResponse
    {
        $model = PosItem::query()->where('property_id', $property->id)->findOrFail($item);
        $result = $savePosItem($request->user(), $property, $this->item($request), $model);

        if (! $result->isRefused() && ($request->has('shared_stock_count') || $request->has('daily_shared_stock_reset'))) {
            $count = $request->validated('shared_stock_count');
            $reset = $request->validated('daily_shared_stock_reset');
            $updateSharedStock($request->user(), $property, $model, $count === null ? null : (int) $count, $reset === null ? null : (int) $reset);
        }

        $this->toast($result->refusal, __('Item saved.'));

        return back();
    }

    public function updateOutletItem(
        Request $request,
        Property $property,
        int $outlet,
        int $variant,
        UpdateOutletItemAvailability $updateAvailability,
        UpdateStockCountdown $updateStock,
    ): RedirectResponse {
        $validated = $request->validate([
            'availability' => ['required', Rule::enum(PosAvailability::class)],
            'price_override' => ['nullable', 'decimal:0,2', 'min:0'],
            'stock_countdown' => ['nullable', 'integer'],
            'daily_stock_reset' => ['nullable', 'integer', 'min:0'],
        ]);

        $outletModel = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $variantModel = PosItemVariant::query()->where('property_id', $property->id)->findOrFail($variant);

        $first = $updateAvailability(
            $request->user(),
            $property,
            $outletModel,
            $variantModel,
            PosAvailability::from((string) $validated['availability']),
            $validated['price_override'] === null || $validated['price_override'] === '' ? null : (string) $validated['price_override'],
        );

        if ($first->isRefused()) {
            $this->toast($first->refusal, '');

            return back();
        }

        $second = $updateStock(
            $request->user(),
            $property,
            $outletModel,
            $variantModel,
            $validated['stock_countdown'] === null || $validated['stock_countdown'] === '' ? null : (int) $validated['stock_countdown'],
            $validated['daily_stock_reset'] === null || $validated['daily_stock_reset'] === '' ? null : (int) $validated['daily_stock_reset'],
        );

        $this->toast($second->refusal, __('Outlet override saved.'));

        return back();
    }

    private function category(SavePosCategoryRequest $request): PosCategoryInputData
    {
        return new PosCategoryInputData((string) $request->validated('name'), (int) $request->validated('transaction_code_id'), $request->validated('internal_name'), (string) $request->validated('color_token'), (int) ($request->validated('sort_order') ?? 0), $request->boolean('active'));
    }

    private function item(SavePosItemRequest $request): PosItemInputData
    {
        return new PosItemInputData(
            (string) $request->validated('name'),
            (int) $request->validated('pos_category_id'),
            array_values(array_map(fn (array $row): PosItemVariantInputData => new PosItemVariantInputData(
                (string) $row['price'],
                (string) $row['name'],
                isset($row['id']) ? (int) $row['id'] : null,
                pricingType: isset($row['pricing_type']) && $row['pricing_type'] !== '' ? PosPricingType::from((string) $row['pricing_type']) : PosPricingType::Fixed,
                sku: $row['sku'] ?? null,
                barcode: $row['barcode'] ?? null,
                cost: isset($row['cost']) && $row['cost'] !== '' ? (string) $row['cost'] : null,
                active: (bool) $row['active'],
            ), $request->validated('variants'))),
            $request->validated('transaction_code_id') === null ? null : (int) $request->validated('transaction_code_id'),
            $request->validated('meal_period_id') === null ? null : (int) $request->validated('meal_period_id'),
            $request->validated('internal_name'),
            $request->validated('description'),
            null,
            $request->boolean('price_includes_tax'),
            $request->boolean('service_charge_exempt'),
            null,
            (int) ($request->validated('sort_order') ?? 0),
            $request->boolean('active'),
            $request->boolean('sold_by_weight'),
            $request->validated('weight_unit'),
            $request->validated('weight_unit_divisor'),
        );
    }
}
