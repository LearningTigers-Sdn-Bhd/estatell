<?php

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\RemoveDraftLine;
use App\Actions\Pos\SavePosComboSlots;
use App\Actions\Pos\SavePosItem;
use App\Actions\Pos\SavePosMenuSchedule;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateDraftOrderLine;
use App\Actions\Pos\VoidOrderLine;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Data\Pos\PosMenuScheduleInputData;
use App\Data\Pos\PosOrderLineInputData;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Models\PosMenuSchedule;
use App\Models\PosVoidReason;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\PosMenuAvailability;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/PosOrderSetup.php';

afterEach(fn () => Carbon::setTestNow());

function posPowerItem(array $s, string $name, string $price, array $extra = [])
{
    $result = app(SavePosItem::class)($s['manager'], $s['property'], new PosItemInputData(...array_merge(['name' => $name, 'posCategoryId' => $s['category']->id, 'variants' => [new PosItemVariantInputData($price)]], $extra)));

    if ($result->isRefused()) {
        throw new RuntimeException('item refused: '.$result->refusal);
    }

    return $result->model;
}

it('prices an item sold by weight per unit of weight: 260 g at RM 10.90 per 50 g is RM 56.68 gross', function () {
    $s = posOrderSetup();
    $oyster = posPowerItem($s, 'Oyster w/ Cheese', '10.90', ['soldByWeight' => true, 'weightUnit' => 'g', 'weightUnitDivisor' => '50']);
    $order = posNewOrder($s);

    $line = posAddLine($s, $order, $oyster->variants->first(), 1, ['weight' => '260']);

    expect((string) $line->quantity)->toBe('5.200')->and($line->refresh()->line_gross)->toBe('56.68');

    $order2 = posNewOrder($s);

    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order2, new PosOrderLineInputData(variantId: $oyster->variants->first()->id))->refusal)->toBe('weight_invalid')
        ->and(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 3)->refusal)->toBe('quantity_invalid')
        ->and(app(SavePosItem::class)($s['manager'], $s['property'], new PosItemInputData('Bad', $s['category']->id, [new PosItemVariantInputData('1.00')], soldByWeight: true))->refusal)->toBe('weight_unit_required');
});

it('sells a scheduled item only inside its window and keeps it off the menu outside it', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $schedule = fn (array $overrides = []) => app(SavePosMenuSchedule::class)($s['manager'], $s['property'], $s['outlet'], null, new PosMenuScheduleInputData(...array_merge(['name' => 'Breakfast', 'daysOfWeek' => 31, 'startTime' => '06:00', 'endTime' => '11:00', 'itemIds' => [$s['mocha']->pos_item_id]], $overrides)));

    expect($schedule(['startTime' => '06:00', 'endTime' => '06:00'])->refusal)->toBe('schedule_invalid')
        ->and($schedule(['itemIds' => []])->refusal)->toBe('schedule_empty')
        ->and($schedule()->isRefused())->toBeFalse();

    $names = fn () => collect(app(OutletMenuSnapshot::class)($s['outlet'])['categories'])->flatMap(fn (array $category) => collect($category['items'])->pluck('name'))->all();

    Carbon::setTestNow('2026-11-25 08:30:00');
    expect($names())->toContain('Iced Mocha')->and(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData(variantId: $s['mocha']->id))->isRefused())->toBeFalse();

    Carbon::setTestNow('2026-11-25 14:00:00');
    expect($names())->not->toContain('Iced Mocha')->and($names())->toContain('Steak')
        ->and(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData(variantId: $s['mocha']->id))->refusal)->toBe('item_not_scheduled');

    Carbon::setTestNow('2026-11-28 08:30:00');
    expect($names())->not->toContain('Iced Mocha');
});

it('runs a schedule past midnight on the day it started', function () {
    $s = posOrderSetup();
    app(SavePosMenuSchedule::class)($s['manager'], $s['property'], $s['outlet'], null, new PosMenuScheduleInputData('Late bar', 16, '22:00', '02:00', itemIds: [$s['mocha']->pos_item_id]));

    $sellable = fn (string $at) => app(PosMenuAvailability::class)->sellable($s['outlet'], $s['mocha']->item, Carbon::parse($at));

    expect($sellable('2026-11-27 23:00:00'))->toBeTrue()
        ->and($sellable('2026-11-28 01:00:00'))->toBeTrue()
        ->and($sellable('2026-11-27 01:00:00'))->toBeFalse()
        ->and($sellable('2026-11-28 23:00:00'))->toBeFalse();
});

function posCombo(array $s): array
{
    $combo = posPowerItem($s, 'Set Lunch', '20.00');
    $slots = app(SavePosComboSlots::class)($s['manager'], $s['property'], $combo, [
        ['name' => 'Choose main', 'min' => 1, 'max' => 1, 'options' => [['variantId' => $s['pasta']->id, 'upcharge' => '0.00'], ['variantId' => $s['steak']->id, 'upcharge' => '10.00']]],
        ['name' => 'Choose drink', 'min' => 0, 'max' => 1, 'options' => [['variantId' => $s['mocha']->id, 'upcharge' => '2.00']]],
    ]);

    return [$combo->refresh(), $slots, $combo->comboSlots()->get()];
}

it('builds a combo line with a part line for each pick, priced at the upcharge', function () {
    $s = posOrderSetup();
    [$combo, , $slots] = posCombo($s);
    $order = posNewOrder($s);

    expect($combo->is_combo)->toBeTrue();

    $line = posAddLine($s, $order, $combo->variants->first(), 2, ['comboSelections' => [$slots[0]->id => [$s['steak']->id], $slots[1]->id => [$s['mocha']->id]]]);
    $parts = $order->lines()->where('parent_combo_line_id', $line->id)->orderBy('id')->get();

    expect($parts)->toHaveCount(2)->and($parts->pluck('line_kind')->unique()->all())->toBe([PosOrderLineKind::ComboComponent])
        ->and($parts->pluck('unit_price')->all())->toBe(['10.00', '2.00'])->and((string) $parts[0]->quantity)->toBe('2.000')
        ->and($order->refresh()->subtotal)->toBe('64.00');

    $bad = app(AddOrderLine::class)($s['manager'], $s['property'], posNewOrder($s), new PosOrderLineInputData(variantId: $combo->variants->first()->id, comboSelections: []));

    expect($bad->refusal)->toBe('combo_selection_invalid');
});

it('changes, removes and voids a combo as one, and refuses to touch a part alone', function () {
    $s = posOrderSetup();
    [$combo, , $slots] = posCombo($s);
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $combo->variants->first(), 1, ['comboSelections' => [$slots[0]->id => [$s['pasta']->id]]]);
    $part = $order->lines()->where('parent_combo_line_id', $line->id)->sole();

    expect(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $part, 2)->refusal)->toBe('combo_part_locked')
        ->and(app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $part)->refusal)->toBe('combo_part_locked');

    app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 3);
    expect((string) $part->refresh()->quantity)->toBe('3.000');

    app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $line);
    expect($order->lines()->count())->toBe(0);

    $again = posAddLine($s, $order, $combo->variants->first(), 1, ['comboSelections' => [$slots[0]->id => [$s['pasta']->id]]]);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $reason = PosVoidReason::factory()->create(['property_id' => $s['property']->id]);
    $result = app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $again, $reason, null, null, $s['manager']);

    expect($result->isRefused())->toBeFalse()->and($order->lines()->where('kitchen_status', PosKitchenStatus::Voided->value)->count())->toBe(2);
});

it('refuses a combo with a nested combo, a weighed part or too few options', function () {
    $s = posOrderSetup();
    [$combo] = posCombo($s);
    $other = posPowerItem($s, 'Another set', '15.00');

    $nest = fn (array $slots) => app(SavePosComboSlots::class)($s['manager'], $s['property'], $other, $slots)->refusal;

    expect($nest([['name' => 'Pick', 'min' => 1, 'max' => 1, 'options' => [['variantId' => $combo->variants->first()->id, 'upcharge' => '0.00']]]]))->toBe('combo_invalid')
        ->and($nest([['name' => 'Pick', 'min' => 2, 'max' => 1, 'options' => [['variantId' => $s['pasta']->id, 'upcharge' => '0.00']]]]))->toBe('combo_invalid')
        ->and($nest([['name' => 'Pick', 'min' => 2, 'max' => 2, 'options' => [['variantId' => $s['pasta']->id, 'upcharge' => '0.00']]]]))->toBe('combo_invalid');
});

it('saves schedules and a combo, and swaps a tender, through the routes', function () {
    $s = posOrderSetup();
    $code = $s['property']->code;

    $this->actingAs($s['manager'])->post(route('settings.pos.schedules.store', [$code, $s['outlet']->id]), ['name' => 'Happy hour', 'days_of_week' => 127, 'start_time' => '17:00', 'end_time' => '20:00', 'active' => true, 'item_ids' => [$s['mocha']->pos_item_id]])->assertRedirect();
    $schedule = PosMenuSchedule::query()->sole();

    $this->actingAs($s['manager'])->put(route('settings.pos.schedules.update', [$code, $schedule->id]), ['name' => 'Happy hour', 'days_of_week' => 31, 'start_time' => '17:00', 'end_time' => '20:00', 'active' => true, 'item_ids' => [$s['mocha']->pos_item_id]])->assertRedirect();
    expect($schedule->refresh()->days_of_week)->toBe(31);

    $combo = posPowerItem($s, 'Set Dinner', '30.00');
    $this->actingAs($s['manager'])->put(route('settings.pos.items.combo', [$code, $combo->id]), ['slots' => [['name' => 'Main', 'min' => 1, 'max' => 1, 'options' => [['variant_id' => $s['pasta']->id, 'upcharge' => '0.00']]]]])->assertRedirect();
    expect($combo->refresh()->is_combo)->toBeTrue()
        ->and($this->actingAs($s['manager'])->get(route('settings.pos.schedules.index', $code))->assertOk()->viewData('page')['props']['combos'])->toHaveCount(1);

    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCard($order->refresh()->grand_total)]);
    $payment = $order->payments()->sole();

    $this->actingAs($s['manager'])->post(route('finance.pos-orders.change-payment', [$code, $order->id]), ['payment_id' => $payment->id, 'tender_type' => 'cash'])->assertRedirect();
    expect($payment->refresh()->status->value)->toBe('replaced')->and($order->payments()->count())->toBe(2);
});

it('does not let the back office change a tender alone at an outlet that needs two people', function () {
    $s = posOrderSetup();
    $s['outlet']->forceFill(['require_dual_control' => true])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCard($order->refresh()->grand_total)]);
    $payment = $order->payments()->sole();

    $this->actingAs($s['manager'])->post(route('finance.pos-orders.change-payment', [$s['property']->code, $order->id]), ['payment_id' => $payment->id, 'tender_type' => 'cash'])->assertRedirect();

    expect($payment->refresh()->status->value)->toBe('captured')->and($order->payments()->count())->toBe(1);
});
