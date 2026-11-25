<?php

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\RedeemMeal;
use App\Actions\Pos\SavePosModifierGroup;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UndoMealRedemption;
use App\Data\Pos\PosModifierGroupInputData;
use App\Data\Pos\PosModifierOptionInputData;
use App\Data\Pos\PosOrderLineInputData;
use App\Enums\PosOrderStatus;
use App\Models\MealRedemption;
use App\Models\PosOrder;
use App\Models\PosOrderLine;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posMealOrder(array $s, int $breakfasts, bool $mocha = true): PosOrder
{
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['breakfast'], $breakfasts, ['coverWithEntitlement' => true]);

    if ($mocha) {
        posAddLine($s, $order, $s['mocha']);
    }

    return $order;
}

it('covers two included breakfasts and bills only the iced mocha: RM 16.24', function () {
    $s = posOrderSetup(true);
    $order = posMealOrder($s, 2);
    $covered = $order->lines()->where('is_entitlement_covered', true)->orderBy('id')->get();

    expect($covered)->toHaveCount(2)
        ->and($covered->pluck('quantity')->unique()->all())->toBe(['1.000'])
        ->and($covered->pluck('line_total')->unique()->all())->toBe(['0.00'])
        ->and($covered->pluck('unit_price')->unique()->all())->toBe(['25.00'])
        ->and($order->fresh()->subtotal)->toBe('14.00')
        ->and($order->fresh()->service_charge_total)->toBe('1.40')
        ->and($order->fresh()->tax_total)->toBe('0.84')
        ->and($order->fresh()->grand_total)->toBe('16.24');
});

it('redeems one guest for each covered line when it settles, linked one to one', function () {
    $s = posOrderSetup(true);
    $order = posMealOrder($s, 2);

    expect(app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')])->refusal)->toBe('confirmation_required')
        ->and(MealRedemption::query()->count())->toBe(0);

    $settled = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')], mealConfirmed: true)->model;
    $covered = $settled->lines()->where('is_entitlement_covered', true)->orderBy('id')->get();
    $redemptions = MealRedemption::query()->orderBy('id')->get();

    expect($settled->status)->toBe(PosOrderStatus::Settled)
        ->and($redemptions)->toHaveCount(2)
        ->and($redemptions->pluck('redeemed_guests')->unique()->all())->toBe([1])
        ->and($redemptions->pluck('reservation_unit_id')->unique()->all())->toBe([$s['unit']->id])
        ->and($redemptions->pluck('meal_period_id')->unique()->all())->toBe([$s['period']->id])
        ->and($redemptions->pluck('service_date')->map->toDateString()->unique()->all())->toBe(['2026-10-14'])
        ->and($covered->pluck('meal_redemption_id')->all())->toBe($redemptions->pluck('id')->all());
});

it('does not ask the guest to confirm when the outlet does not require it', function () {
    $s = posOrderSetup(true);
    $s['outlet']->forceFill(['require_meal_redemption_confirmation' => false])->save();
    $order = posMealOrder($s, 1);

    expect(app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')])->isRefused())->toBeFalse();
});

it('refuses more breakfasts than the booking includes and leaves no redemption behind', function () {
    $s = posOrderSetup(true);
    $order = posMealOrder($s, 4);

    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')], mealConfirmed: true);

    expect($result->refusal)->toBe('exceeds_entitlement')
        ->and(MealRedemption::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and(PosOrderLine::query()->whereNotNull('meal_redemption_id')->count())->toBe(0);
});

it('counts breakfasts already checked in at the meal desk', function () {
    $s = posOrderSetup(true);
    app(RedeemMeal::class)($s['manager'], $s['unit'], $s['period'], $s['date'], 2);
    $order = posMealOrder($s, 2);

    expect(app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')], mealConfirmed: true)->refusal)->toBe('exceeds_entitlement');
});

it('refuses to cover a dish with a meal when the order has no room or the item has no meal period', function () {
    $s = posOrderSetup(true);
    $noRoom = posNewOrder($s);

    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $noRoom, new PosOrderLineInputData($s['breakfast']->id, coverWithEntitlement: true))->refusal)->toBe('not_entitled');

    $withRoom = posNewOrder($s, null, $s['unit']);
    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $withRoom, new PosOrderLineInputData($s['mocha']->id, coverWithEntitlement: true))->refusal)->toBe('not_entitled');
});

it('bills a paid upgrade on an included breakfast: RM 3.48', function () {
    $s = posOrderSetup(true);
    $group = app(SavePosModifierGroup::class)($s['manager'], $s['property'], new PosModifierGroupInputData('Upgrade', [new PosModifierOptionInputData('Smoked salmon', priceDelta: '3.00')], [$s['breakfast']->item->id]))->model;
    $order = posNewOrder($s, null, $s['unit']);

    $line = app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['breakfast']->id, modifierOptionIds: [$group->options()->first()->id], coverWithEntitlement: true))->model;

    expect($line->fresh()->line_gross)->toBe('3.00')
        ->and($line->fresh()->service_charge_amount)->toBe('0.30')
        ->and($line->fresh()->tax_amount)->toBe('0.18')
        ->and($order->fresh()->grand_total)->toBe('3.48');

    $settled = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('3.48')], mealConfirmed: true)->model;
    expect($settled->grand_total)->toBe('3.48')->and(MealRedemption::query()->count())->toBe(1);
});

it('keeps the meal desk from undoing a meal a POS order used, but POS can give it back', function () {
    $s = posOrderSetup(true);
    $order = posMealOrder($s, 2);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')], mealConfirmed: true);
    $redemption = MealRedemption::query()->orderBy('id')->first();

    expect(app(UndoMealRedemption::class)($s['manager'], $redemption)->refusal)->toBe('redeemed_in_pos');

    $result = app(UndoMealRedemption::class)->forPos($s['manager'], $redemption);

    expect($result->isRefused())->toBeFalse()
        ->and($redemption->fresh()->undone_at)->not->toBeNull()
        ->and(MealRedemption::query()->active()->count())->toBe(1)
        ->and(app(UndoMealRedemption::class)->forPos($s['manager'], $redemption)->refusal)->toBe('already_undone');
});

it('lets the meal desk undo a meal that no POS order used', function () {
    $s = posOrderSetup(true);
    $redemption = app(RedeemMeal::class)($s['manager'], $s['unit'], $s['period'], $s['date'], 1)->redemption;

    expect(app(UndoMealRedemption::class)($s['manager'], $redemption)->isRefused())->toBeFalse();
});

it('does not settle a bill of nothing', function () {
    $s = posOrderSetup(true);
    $order = posMealOrder($s, 2, false);

    expect($order->fresh()->grand_total)->toBe('0.00')
        ->and(app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [], mealConfirmed: true)->isRefused())->toBeFalse()
        ->and(MealRedemption::query()->count())->toBe(2);
});
