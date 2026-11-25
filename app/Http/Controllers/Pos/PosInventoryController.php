<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\ClosePosStocktake;
use App\Actions\Pos\CountPosStocktakeLine;
use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\ReceiveStockPurchase;
use App\Actions\Pos\RecordStockWaste;
use App\Actions\Pos\SavePosIngredient;
use App\Actions\Pos\SavePosRecipe;
use App\Actions\Pos\StartPosStocktake;
use App\Actions\Pos\TransferStock;
use App\Enums\PosStockMovementKind;
use App\Enums\PosStocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\CountPosStocktakeLineRequest;
use App\Http\Requests\Pos\ReceiveStockPurchaseRequest;
use App\Http\Requests\Pos\RecordStockWasteRequest;
use App\Http\Requests\Pos\SavePosIngredientRequest;
use App\Http\Requests\Pos\SavePosRecipeRequest;
use App\Http\Requests\Pos\StartPosStocktakeRequest;
use App\Http\Requests\Pos\StockTransferRequest;
use App\Models\PosIngredient;
use App\Models\PosItemVariant;
use App\Models\PosOutlet;
use App\Models\PosRecipe;
use App\Models\PosStocktake;
use App\Models\Property;
use App\Services\Pos\PosStockBalances;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings and counting for the back of house: ingredients, recipes, what is on hand at an outlet, deliveries, waste,
 * transfers and the closing count. Every write is an Action. See docs/34-native-pos/07-inventory-and-recipes.md.
 */
class PosInventoryController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property, PosStockBalances $balances): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->orderBy('name')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();
        $onHand = $outlet === null ? [] : $balances->forOutlet($outlet->id);
        $stocktake = $outlet === null ? null : PosStocktake::query()->where('pos_outlet_id', $outlet->id)->where('status', PosStocktakeStatus::Draft->value)->with('lines')->latest('id')->first();

        return Inertia::render('settings/pos/inventory', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->name],
            'ingredients' => PosIngredient::query()->where('property_id', $property->id)->orderBy('name')->get()->map(fn (PosIngredient $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'baseUnit' => $row->base_unit,
                'purchaseUnit' => $row->purchase_unit,
                'ratio' => (string) $row->purchase_to_base_ratio,
                'unitCost' => (string) $row->unit_cost,
                'threshold' => $row->low_stock_threshold === null ? null : (string) $row->low_stock_threshold,
                'active' => $row->active,
                'onHand' => $onHand[$row->id] ?? '0',
            ])->values()->all(),
            'variants' => PosItemVariant::query()->where('property_id', $property->id)->where('active', true)->with(['item', 'recipes'])->orderBy('id')->get()->map(fn (PosItemVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->item->name.($variant->name === 'Regular' ? '' : ' · '.$variant->name),
                'recipe' => $variant->recipes->map(fn (PosRecipe $recipe): array => ['ingredientId' => $recipe->pos_ingredient_id, 'quantity' => (string) $recipe->quantity_in_base_unit])->values()->all(),
            ])->values()->all(),
            'stocktake' => $stocktake === null ? null : [
                'id' => $stocktake->id,
                'lines' => $stocktake->lines->map(fn ($line): array => [
                    'ingredientId' => $line->pos_ingredient_id,
                    'counted' => (string) $line->counted_quantity,
                    'countedAt' => $line->counted_at->toIso8601String(),
                ])->values()->all(),
            ],
            'movementKinds' => PosStockMovementKind::options(),
        ]);
    }

    public function storeIngredient(SavePosIngredientRequest $request, Property $property, SavePosIngredient $savePosIngredient): RedirectResponse
    {
        $this->toast($this->saveIngredient($request, $property, $savePosIngredient, null)->refusal, __('Ingredient added.'));

        return back();
    }

    public function updateIngredient(SavePosIngredientRequest $request, Property $property, int $ingredient, SavePosIngredient $savePosIngredient): RedirectResponse
    {
        $model = PosIngredient::query()->where('property_id', $property->id)->findOrFail($ingredient);
        $this->toast($this->saveIngredient($request, $property, $savePosIngredient, $model)->refusal, __('Ingredient saved.'));

        return back();
    }

    public function saveRecipe(SavePosRecipeRequest $request, Property $property, int $variant, SavePosRecipe $savePosRecipe): RedirectResponse
    {
        $model = PosItemVariant::query()->where('property_id', $property->id)->findOrFail($variant);
        $this->toast($savePosRecipe($request->user(), $property, $model, (array) $request->validated('quantities'))->refusal, __('Recipe saved.'));

        return back();
    }

    public function receive(ReceiveStockPurchaseRequest $request, Property $property, int $outlet, ReceiveStockPurchase $receiveStockPurchase): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $ingredient = PosIngredient::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('ingredient_id'));
        $this->toast($receiveStockPurchase($request->user(), $property, $model, $ingredient, (string) $request->validated('quantity'), (string) $request->validated('unit_cost'))->refusal, __('Delivery received.'));

        return back();
    }

    public function waste(RecordStockWasteRequest $request, Property $property, int $outlet, RecordStockWaste $recordStockWaste): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $ingredient = PosIngredient::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('ingredient_id'));
        $this->toast($recordStockWaste($request->user(), $property, $model, $ingredient, (string) $request->validated('quantity'), (string) $request->validated('reason'))->refusal, __('Waste recorded.'));

        return back();
    }

    public function transfer(StockTransferRequest $request, Property $property, TransferStock $transferStock): RedirectResponse
    {
        $ingredient = PosIngredient::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('ingredient_id'));
        $from = PosOutlet::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('from_outlet_id'));
        $to = PosOutlet::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('to_outlet_id'));
        $this->toast($transferStock($request->user(), $property, $ingredient, $from, $to, (string) $request->validated('quantity'))->refusal, __('Stock transferred.'));

        return back();
    }

    public function startStocktake(StartPosStocktakeRequest $request, Property $property, int $outlet, StartPosStocktake $startPosStocktake): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($startPosStocktake($request->user(), $property, $model, $request->validated('note'))->refusal, __('Closing count started.'));

        return back();
    }

    public function count(CountPosStocktakeLineRequest $request, Property $property, int $stocktake, int $ingredient, CountPosStocktakeLine $countPosStocktakeLine): RedirectResponse
    {
        $model = PosStocktake::query()->where('property_id', $property->id)->findOrFail($stocktake);
        $item = PosIngredient::query()->where('property_id', $property->id)->findOrFail($ingredient);
        $this->toast($countPosStocktakeLine($request->user(), $property, $model, $item, (string) $request->validated('counted_quantity'), CarbonImmutable::now())->refusal, __('Count saved.'));

        return back();
    }

    public function closeStocktake(Request $request, Property $property, int $stocktake, ClosePosStocktake $closePosStocktake): RedirectResponse
    {
        $model = PosStocktake::query()->where('property_id', $property->id)->findOrFail($stocktake);
        $this->toast($closePosStocktake($request->user(), $property, $model)->refusal, __('Stocktake closed.'));

        return back();
    }

    private function saveIngredient(SavePosIngredientRequest $request, Property $property, SavePosIngredient $savePosIngredient, ?PosIngredient $ingredient): PosActionResult
    {
        return $savePosIngredient(
            $request->user(),
            $property,
            (string) $request->validated('name'),
            (string) $request->validated('base_unit'),
            $request->validated('purchase_unit'),
            (string) ($request->validated('purchase_to_base_ratio') ?? '1'),
            $request->validated('sku'),
            $request->validated('low_stock_threshold'),
            $request->boolean('active', true),
            $ingredient,
        );
    }
}
