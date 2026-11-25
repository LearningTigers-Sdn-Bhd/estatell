<?php

use App\Actions\Pos\ClosePosStocktake;
use App\Actions\Pos\CountPosStocktakeLine;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\ReceiveStockPurchase;
use App\Actions\Pos\RecordStockWaste;
use App\Actions\Pos\SavePosIngredient;
use App\Actions\Pos\SavePosRecipe;
use App\Actions\Pos\StartPosStocktake;
use App\Actions\Pos\TransferStock;
use App\Actions\Pos\VoidOrderLine;
use App\Enums\PosStockMovementKind;
use App\Models\PosIngredient;
use App\Models\PosOutlet;
use App\Models\PosStockMovement;
use App\Models\PosVoidReason;
use App\Models\User;
use App\Services\Pos\PosStockBalances;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/PosOrderSetup.php';

/** The outlet of the order setup, a rice ingredient, and a Steak whose recipe is 180 g of rice. */
function posInventorySetup(): array
{
    $s = posOrderSetup();
    $rice = app(SavePosIngredient::class)($s['manager'], $s['property'], 'Rice', 'g', 'kg', '1000', null, null, true)->model;
    app(SavePosRecipe::class)($s['manager'], $s['property'], $s['steak'], [$rice->id => '180']);

    return [...$s, 'rice' => $rice, 'balances' => app(PosStockBalances::class)];
}

function posReceiveRice(array $s, string $kilos, string $unitCost): void
{
    app(ReceiveStockPurchase::class)($s['manager'], $s['property'], $s['outlet'], $s['rice'], $kilos, $unitCost);
}

it('takes the recipe out of stock when a line is fired, and stamps its cost on the line', function () {
    $s = posInventorySetup();
    posReceiveRice($s, '2', '5.00');
    $order = posNewOrder($s);
    $steak = posAddLine($s, $order, $s['steak'], 2);

    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('1640.0000')
        ->and(PosStockMovement::query()->where('kind', PosStockMovementKind::Sale->value)->sole()->quantity_delta)->toBe('-360.0000')
        ->and($steak->refresh()->unit_cost)->toBe('0.90');
});

it('returns the recipe to stock only when a fired line is voided with stock returned', function () {
    $s = posInventorySetup();
    posReceiveRice($s, '2', '5.00');
    $order = posNewOrder($s);
    $keep = posAddLine($s, $order, $s['steak']);
    $bin = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $reason = PosVoidReason::factory()->create(['property_id' => $s['property']->id, 'active' => true]);

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $bin, $reason, null, false, $s['manager']);
    expect($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('1640.0000');

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $keep, $reason, null, true, $s['manager']);
    expect($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('1820.0000')
        ->and(PosStockMovement::query()->where('kind', PosStockMovementKind::VoidReturn->value)->count())->toBe(1);
});

it('keeps a moving average cost across deliveries, in base units', function () {
    $s = posInventorySetup();

    posReceiveRice($s, '2', '10.00');
    posReceiveRice($s, '1', '16.00');

    expect($s['rice']->refresh()->unit_cost)->toBe('0.0120')
        ->and($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('3000.0000');
});

it('writes off waste only with a reason, and moves a transfer as a pair of rows', function () {
    $s = posInventorySetup();
    posReceiveRice($s, '2', '5.00');
    $other = PosOutlet::factory()->create(['property_id' => $s['property']->id, 'native_pos_enabled' => true]);

    expect(app(RecordStockWaste::class)($s['manager'], $s['property'], $s['outlet'], $s['rice'], '100', '  ')->refusal)->toBe('waste_reason_required')
        ->and(app(RecordStockWaste::class)($s['manager'], $s['property'], $s['outlet'], $s['rice'], '100', 'Dropped')->isRefused())->toBeFalse()
        ->and(app(TransferStock::class)($s['manager'], $s['property'], $s['rice'], $s['outlet'], $s['outlet'], '50')->refusal)->toBe('transfer_same_outlet')
        ->and(app(TransferStock::class)($s['manager'], $s['property'], $s['rice'], $s['outlet'], $other, '500')->isRefused())->toBeFalse();

    expect($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('1400.0000')
        ->and($s['balances']->onHand($other->id, $s['rice']->id))->toBe('500.0000');
});

it('closes a count against the ledger as it stood when each line was counted, so a later sale is not counted twice', function () {
    $s = posInventorySetup();
    $start = CarbonImmutable::now();
    CarbonImmutable::setTestNow($start->subHours(2));
    posReceiveRice($s, '2', '5.00');

    CarbonImmutable::setTestNow($start->subHour());
    $stocktake = app(StartPosStocktake::class)($s['manager'], $s['property'], $s['outlet'])->model;
    app(CountPosStocktakeLine::class)($s['manager'], $s['property'], $stocktake, $s['rice'], '1800', CarbonImmutable::now());

    CarbonImmutable::setTestNow($start);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak'], 2);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect(app(ClosePosStocktake::class)($s['manager'], $s['property'], $stocktake->refresh())->isRefused())->toBeFalse();

    $line = $stocktake->lines()->sole();
    expect($line->expected_quantity)->toBe('2000.0000')
        ->and($line->variance_quantity)->toBe('-200.0000')
        ->and($s['balances']->onHand($s['outlet']->id, $s['rice']->id))->toBe('1440.0000');

    CarbonImmutable::setTestNow();
});

it('refuses a recipe that uses another property\'s ingredient, and a closed count', function () {
    $s = posInventorySetup();
    $other = posInventorySetup();

    expect(app(SavePosRecipe::class)($s['manager'], $s['property'], $s['pasta'], [$other['rice']->id => '100'])->refusal)->toBe('recipe_ingredient_invalid')
        ->and(app(SavePosRecipe::class)($s['manager'], $s['property'], $s['pasta'], [$s['rice']->id => '0'])->refusal)->toBe('recipe_quantity_invalid')
        ->and(app(SavePosIngredient::class)($s['manager'], $s['property'], ' rice ', 'g', null, '1', null, null, true)->refusal)->toBe('ingredient_name_taken');

    $stocktake = app(StartPosStocktake::class)($s['manager'], $s['property'], $s['outlet'])->model;
    expect(app(ClosePosStocktake::class)($s['manager'], $s['property'], $stocktake)->refusal)->toBe('stocktake_empty');
    expect(PosIngredient::query()->where('property_id', $s['property']->id)->count())->toBe(1);
});

it('lets only staff with the inventory permission of this property open the stock settings', function () {
    $s = posInventorySetup();
    $code = $s['property']->code;
    $menuOnly = User::factory()->for($s['property'])->withPermissions(['pos.menu'])->create();
    $stock = User::factory()->for($s['property'])->withPermissions(['pos.inventory'])->create();

    $this->actingAs($menuOnly)->get(route('settings.pos.inventory.index', [$code, 'outlet' => $s['outlet']->id]))->assertForbidden();
    $this->actingAs($stock)->get(route('settings.pos.inventory.index', [$code, 'outlet' => $s['outlet']->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/pos/inventory')
            ->has('ingredients', 1)
            ->where('ingredients.0.name', 'Rice'));
});
