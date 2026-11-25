<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosCategory;
use App\Actions\Pos\SavePosItem;
use App\Data\Pos\PosCategoryInputData;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosCategoryRequest;
use App\Http\Requests\Pos\SavePosItemRequest;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosMenuController extends Controller
{
    use RespondsToPos;

    public function index(Property $property): Response
    {
        $categories = PosCategory::query()->where('property_id', $property->id)->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'), 'items.variants' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('settings/pos/menu', [
            'categories' => $categories->map(fn (PosCategory $category): array => [
                'id' => $category->id, 'name' => $category->name, 'transactionCodeId' => $category->transaction_code_id, 'internalName' => $category->internal_name,
                'colorToken' => $category->color_token, 'sortOrder' => $category->sort_order, 'active' => $category->active,
                'items' => $category->items->map(fn (PosItem $item): array => [
                    'id' => $item->id, 'name' => $item->name, 'transactionCodeId' => $item->transaction_code_id, 'mealPeriodId' => $item->meal_period_id, 'internalName' => $item->internal_name,
                    'description' => $item->description, 'priceIncludesTax' => $item->price_includes_tax, 'serviceChargeExempt' => $item->service_charge_exempt, 'soldByWeight' => $item->sold_by_weight, 'weightUnit' => $item->weight_unit, 'weightUnitDivisor' => $item->weight_unit_divisor, 'sortOrder' => $item->sort_order, 'active' => $item->active,
                    'variants' => $item->variants->map(fn (PosItemVariant $variant): array => ['id' => $variant->id, 'name' => $variant->name, 'price' => $variant->price, 'sku' => $variant->sku, 'active' => $variant->active])->all(),
                ])->values()->all(),
            ])->values()->all(),
            'transactionCodes' => TransactionCode::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->all(),
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

    public function storeItem(SavePosItemRequest $request, Property $property, SavePosItem $savePosItem): RedirectResponse
    {
        $this->toast($savePosItem($request->user(), $property, $this->item($request))->refusal, __('Item added.'));

        return back();
    }

    public function updateItem(SavePosItemRequest $request, Property $property, int $item, SavePosItem $savePosItem): RedirectResponse
    {
        $model = PosItem::query()->where('property_id', $property->id)->findOrFail($item);
        $this->toast($savePosItem($request->user(), $property, $this->item($request), $model)->refusal, __('Item saved.'));

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
            array_values(array_map(fn (array $row): PosItemVariantInputData => new PosItemVariantInputData((string) $row['price'], (string) $row['name'], isset($row['id']) ? (int) $row['id'] : null, sku: $row['sku'] ?? null, active: (bool) $row['active']), $request->validated('variants'))),
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
