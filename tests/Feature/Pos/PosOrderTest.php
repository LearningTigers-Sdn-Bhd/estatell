<?php

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\ApplyPosDiscount;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\OpenPosShift;
use App\Actions\Pos\RemoveDraftLine;
use App\Actions\Pos\RemovePosDiscount;
use App\Actions\Pos\SavePosModifierGroup;
use App\Actions\Pos\TransferOrderToRegister;
use App\Actions\Pos\UpdateDraftOrderLine;
use App\Actions\Pos\UpdateOutletItemAvailability;
use App\Actions\Pos\UpdateSharedStockCount;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosModifierGroupInputData;
use App\Data\Pos\PosModifierOptionInputData;
use App\Data\Pos\PosOrderLineInputData;
use App\Enums\PosAvailability;
use App\Enums\PosDiscountType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Enums\PosPricingType;
use App\Enums\PosShiftStatus;
use App\Enums\UnitStatus;
use App\Features\Pos;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineTax;
use App\Models\PosOutletItem;
use App\Models\PosRegister;
use App\Models\Tax;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

it('opens an order on the open shift and returns the same one for the same client uuid', function () {
    $s = posOrderSetup();
    $create = fn (string $uuid) => app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $s['dineIn'], $uuid, 2);

    $first = $create('0d5a1c46-8e0a-4a52-9d5e-1c3c3d9f0a11');
    $again = $create('0d5a1c46-8e0a-4a52-9d5e-1c3c3d9f0a11');

    expect($first->model->status->value)->toBe('open')
        ->and($first->model->guest_count)->toBe(2)
        ->and($first->model->business_date)->toBeNull()
        ->and($first->model->order_number)->toBeNull()
        ->and($first->model->pos_outlet_id)->toBe($s['outlet']->id)
        ->and($first->model->pos_shift_id)->toBe($s['shift']->id)
        ->and($again->replayed)->toBeTrue()
        ->and($again->model->id)->toBe($first->model->id)
        ->and(PosOrder::query()->count())->toBe(1);
});

it('refuses an order on a closed shift, another outlet dining option or when Native POS is off', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $create = fn (array $args = []) => app(CreatePosOrder::class)(...array_replace([$s['manager'], $s['property'], $s['shift'], $s['dineIn'], (string) Str::uuid(), 1], $args));

    expect($create([3 => $other['dineIn']])->refusal)->toBe('dining_option_invalid')
        ->and($create([5 => 0])->refusal)->toBe('guest_count_invalid');

    $s['shift']->forceFill(['status' => PosShiftStatus::ClosingControl])->save();
    expect($create()->refusal)->toBe('shift_not_open');

    $s['shift']->forceFill(['status' => PosShiftStatus::Open])->save();
    Feature::for($s['property'])->deactivate(Pos::class);
    expect($create()->refusal)->toBe('pos_disabled');
});

it('opens an order for an in-house room only', function () {
    $s = posOrderSetup();
    $create = fn ($unit) => app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $s['dineIn'], (string) Str::uuid(), 1, $unit);

    expect($create($s['unit'])->model->reservation_unit_id)->toBe($s['unit']->id);

    posUnitStatus($s['unit'], UnitStatus::Confirmed);
    expect($create($s['unit'])->refusal)->toBe('unit_not_eligible');
});

it('prices a line the moment it is added: RM 100.00 steak is RM 116.00', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);

    $line = posAddLine($s, $order, $s['steak']);
    $order->refresh();

    expect($line->kitchen_status)->toBe(PosKitchenStatus::Draft)
        ->and($line->item_name)->toBe('Steak')
        ->and($line->variant_name)->toBe('Regular')
        ->and($line->unit_price)->toBe('100.00')
        ->and($line->transaction_code_id)->toBe($s['food']->id)
        ->and($line->line_gross)->toBe('100.00')
        ->and($line->net_amount)->toBe('100.00')
        ->and($line->service_charge_amount)->toBe('10.00')
        ->and($line->tax_amount)->toBe('6.00')
        ->and($line->line_total)->toBe('116.00')
        ->and($order->subtotal)->toBe('100.00')
        ->and($order->service_charge_total)->toBe('10.00')
        ->and($order->tax_total)->toBe('6.00')
        ->and($order->grand_total)->toBe('116.00');

    $tax = PosOrderLineTax::query()->where('pos_order_line_id', $line->id)->sole();
    expect($tax->tax_code)->toBe('SST')->and($tax->lhdn_tax_type)->toBe('02')->and($tax->tax_rate)->toBe('6.00')->and($tax->taxable_base)->toBe('100.00')->and($tax->tax_amount)->toBe('6.00')->and($tax->is_inclusive)->toBeFalse();
});

it('prices the spec bill: steak RM 100.00 and pasta RM 37.93 come to RM 160.00', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $order->refresh();

    expect($order->subtotal)->toBe('137.93')->and($order->service_charge_total)->toBe('13.79')->and($order->tax_total)->toBe('8.28')->and($order->grand_total)->toBe('160.00');
});

it('uses the price of the item on the line, not the menu price later', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);

    $s['steak']->forceFill(['price' => '150.00'])->save();
    app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['mocha']->id));

    expect($line->fresh()->unit_price)->toBe('100.00')->and($order->fresh()->grand_total)->toBe('132.24');
});

it('uses the outlet price override and refuses an item the outlet switched off', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    app(UpdateOutletItemAvailability::class)($s['manager'], $s['property'], $s['outlet'], $s['steak'], PosAvailability::PosOnly, '90.00');
    app(UpdateOutletItemAvailability::class)($s['manager'], $s['property'], $s['outlet'], $s['pasta'], PosAvailability::None);

    expect(posAddLine($s, $order, $s['steak'])->unit_price)->toBe('90.00')
        ->and(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['pasta']->id))->refusal)->toBe('item_unavailable');
});

it('asks for the price of an item priced at the register', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $s['pasta']->forceFill(['pricing_type' => PosPricingType::Variable, 'price' => '0.00'])->save();
    $add = fn (?string $price) => app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['pasta']->id, unitPrice: $price));

    expect($add(null)->refusal)->toBe('price_required')
        ->and($add('abc')->refusal)->toBe('price_required')
        ->and($add('55.50')->model->unit_price)->toBe('55.50');
});

it('refuses an item that is off the menu, from another property or a bad quantity', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    $add = fn (int $variantId, int $quantity = 1) => app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($variantId, $quantity));

    expect($add($other['steak']->id)->refusal)->toBe('item_unavailable')
        ->and($add($s['steak']->id, 0)->refusal)->toBe('quantity_invalid')
        ->and($add($s['steak']->id, 1000)->refusal)->toBe('quantity_invalid');

    $s['steak']->item->forceFill(['active' => false])->save();
    expect($add($s['steak']->id)->refusal)->toBe('item_unavailable')
        ->and($order->lines()->count())->toBe(0);
});

it('refuses new lines on an order that is not open or whose shift is closing', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);

    $s['shift']->forceFill(['status' => PosShiftStatus::ClosingControl])->save();
    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['steak']->id))->refusal)->toBe('shift_not_open');

    $s['shift']->forceFill(['status' => PosShiftStatus::Open])->save();
    $order->forceFill(['status' => PosOrderStatus::Voided])->save();
    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['steak']->id))->refusal)->toBe('order_not_open');
});

it('does not add the line when the order cannot be priced without an SST number', function () {
    $s = posOrderSetup();
    Tax::query()->where('code', 'SST')->update(['registration_number' => null]);
    $order = posNewOrder($s);

    $result = app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['steak']->id));

    expect($result->refusal)->toBe('sst_registration_missing')
        ->and($order->lines()->count())->toBe(0)
        ->and($order->fresh()->grand_total)->toBe('0.00');
});

it('adds the packaging fee as a system line for takeaway and drops it with the last item', function () {
    $s = posOrderSetup();
    $s['takeaway']->forceFill(['packaging_fee' => '1.00'])->save();
    $order = posNewOrder($s, $s['takeaway']);

    $line = posAddLine($s, $order, $s['mocha']);
    $order->refresh();
    $packaging = $order->lines()->where('line_kind', PosOrderLineKind::PackagingFee->value)->sole();

    expect($packaging->unit_price)->toBe('1.00')
        ->and($packaging->service_charge_exempt)->toBeTrue()
        ->and($packaging->price_includes_tax)->toBeFalse()
        ->and($packaging->pos_item_variant_id)->toBeNull()
        ->and($packaging->transaction_code_id)->toBe($s['outlet']->packaging_transaction_code_id)
        ->and($order->subtotal)->toBe('15.00')
        ->and($order->service_charge_total)->toBe('0.00')
        ->and($order->grand_total)->toBe('15.84');

    app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $line);
    expect($order->lines()->count())->toBe(0)->and($order->fresh()->grand_total)->toBe('0.00');
});

it('refuses a packaging fee while the outlet has no packaging code', function () {
    $s = posOrderSetup();
    $s['takeaway']->forceFill(['packaging_fee' => '1.00'])->save();
    $s['outlet']->forceFill(['packaging_transaction_code_id' => null])->save();
    $order = posNewOrder($s, $s['takeaway']);

    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['mocha']->id))->refusal)->toBe('packaging_code_required');
});

it('prices modifiers, makes the cheapest included choices free and enforces the group rules', function () {
    $s = posOrderSetup();
    $item = $s['mocha']->item;
    $group = app(SavePosModifierGroup::class)($s['manager'], $s['property'], new PosModifierGroupInputData('Extras', [
        new PosModifierOptionInputData('Shot', priceDelta: '3.00'),
        new PosModifierOptionInputData('Oat', priceDelta: '2.00'),
        new PosModifierOptionInputData('Syrup', priceDelta: '1.00'),
    ], [$item->id], minSelect: 1, maxSelect: 3, maxPerOption: 2, freeIncludedCount: 1))->model;
    [$shot, $oat, $syrup] = $group->options()->orderBy('id')->get()->all();
    $order = posNewOrder($s);
    $add = fn (array $ids) => app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['mocha']->id, modifierOptionIds: $ids));

    expect($add([])->refusal)->toBe('modifier_selection_invalid')
        ->and($add([$shot->id, $shot->id, $shot->id])->refusal)->toBe('modifier_selection_invalid')
        ->and($add([$shot->id, $oat->id, $syrup->id, $syrup->id])->refusal)->toBe('modifier_selection_invalid')
        ->and($add([999999])->refusal)->toBe('modifier_invalid');

    $line = $add([$shot->id, $oat->id, $syrup->id])->model;
    $charged = $line->modifiers()->orderBy('id')->get();

    expect($charged->pluck('option_name')->sort()->values()->all())->toBe(['Oat', 'Shot', 'Syrup'])
        ->and($charged->firstWhere('option_name', 'Syrup')->charged_price)->toBe('0.00')
        ->and($charged->firstWhere('option_name', 'Oat')->charged_price)->toBe('2.00')
        ->and($charged->firstWhere('option_name', 'Shot')->charged_price)->toBe('3.00')
        ->and($line->fresh()->line_gross)->toBe('19.00')
        ->and($line->fresh()->net_amount)->toBe('19.00');
});

it('leaves a switched off modifier choice out', function () {
    $s = posOrderSetup();
    $group = app(SavePosModifierGroup::class)($s['manager'], $s['property'], new PosModifierGroupInputData('Milk', [new PosModifierOptionInputData('Oat')], [$s['mocha']->item->id]))->model;
    $group->options()->update(['availability' => PosAvailability::None]);
    $order = posNewOrder($s);

    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['mocha']->id, modifierOptionIds: [$group->options()->first()->id]))->refusal)->toBe('modifier_invalid');
});

it('changes the quantity and note of a draft line and prices again', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['mocha']);

    $result = app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 3, ' no ice ');

    expect($result->model->quantity)->toBe('3.000')->and($result->model->note)->toBe('no ice')
        ->and($order->fresh()->subtotal)->toBe('42.00')
        ->and($order->fresh()->grand_total)->toBe('48.72')
        ->and(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 0)->refusal)->toBe('quantity_invalid');
});

it('locks a line once it is fired and a quantity cut once a pre-bill is printed', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['mocha'], 2);

    $order->forceFill(['prebill_print_count' => 1])->save();
    expect(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 1)->refusal)->toBe('prebill_locked')
        ->and(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 3)->isRefused())->toBeFalse()
        ->and(app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $line)->refusal)->toBe('prebill_locked');

    $order->forceFill(['prebill_print_count' => 0])->save();
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect(app(UpdateDraftOrderLine::class)($s['manager'], $s['property'], $order, $line, 1)->refusal)->toBe('line_locked')
        ->and(app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $line)->refusal)->toBe('line_locked');
});

it('removes a draft line with its modifiers and tax rows', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $keep = posAddLine($s, $order, $s['steak']);
    $drop = posAddLine($s, $order, $s['mocha']);

    app(RemoveDraftLine::class)($s['manager'], $s['property'], $order, $drop);

    expect(PosOrderLine::query()->whereKey($drop->id)->exists())->toBeFalse()
        ->and(PosOrderLineTax::query()->where('pos_order_line_id', $drop->id)->exists())->toBeFalse()
        ->and($order->fresh()->grand_total)->toBe('116.00')
        ->and($keep->fresh()->exists)->toBeTrue();
});

it('fires draft lines once, takes stock off the outlet count and the shared count, and allows negative', function () {
    $s = posOrderSetup();
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 2);
    app(UpdateSharedStockCount::class)($s['manager'], $s['property'], $s['steak']->item, 5);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha'], 3);
    posAddLine($s, $order, $s['steak'], 2);
    posAddLine($s, $order, $s['pasta']);

    $result = app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $again = app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $lines = $order->lines()->orderBy('id')->get();

    expect($result->isRefused())->toBeFalse()
        ->and($lines->pluck('kitchen_status')->unique()->all())->toBe([PosKitchenStatus::Fired])
        ->and($lines->first()->fired_at)->not->toBeNull()
        ->and(PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown)->toBe(-1)
        ->and($s['steak']->item->fresh()->shared_stock_count)->toBe(3)
        ->and($again->isRefused())->toBeFalse();
});

it('fires only the lines asked for', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $a = posAddLine($s, $order, $s['mocha']);
    $b = posAddLine($s, $order, $s['steak']);

    app(FireOrderLines::class)($s['manager'], $s['property'], $order, [$a->id]);

    expect($a->fresh()->kitchen_status)->toBe(PosKitchenStatus::Fired)->and($b->fresh()->kitchen_status)->toBe(PosKitchenStatus::Draft);
});

it('moves an open order to another register with an open shift of the same outlet', function () {
    $s = posOrderSetup();
    $other = PosRegister::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id]);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(app(TransferOrderToRegister::class)($s['manager'], $s['property'], $order, $other)->refusal)->toBe('shift_not_open');

    $second = app(OpenPosShift::class)($s['manager'], $s['property'], $other, '100.00')->model;
    $moved = app(TransferOrderToRegister::class)($s['manager'], $s['property'], $order, $other);

    expect($moved->model->pos_shift_id)->toBe($second->id);

    $foreign = posOrderSetup();
    expect(app(TransferOrderToRegister::class)($s['manager'], $s['property'], $order, $foreign['register'])->refusal)->toBe('register_invalid');
});

it('discounts a line before service charge and tax, and a manager is needed for a typed-in discount', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    $apply = fn (PosDiscountType $type, string $value, $approver = null) => app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, $type, $value, $line, 'Regular guest', $approver);

    expect($apply(PosDiscountType::VariablePercent, '10')->refusal)->toBe('approval_required')
        ->and($apply(PosDiscountType::FixedPercent, '0')->refusal)->toBe('discount_invalid')
        ->and($apply(PosDiscountType::FixedPercent, '101')->refusal)->toBe('discount_invalid')
        ->and($apply(PosDiscountType::FixedAmount, '-5')->refusal)->toBe('discount_invalid');

    expect($apply(PosDiscountType::FixedPercent, '10')->isRefused())->toBeFalse();
    $line->refresh();
    $order->refresh();

    expect($line->discount_amount)->toBe('10.00')->and($line->net_amount)->toBe('90.00')->and($line->discount_reason)->toBe('Regular guest')
        ->and($order->discount_total)->toBe('10.00')->and($order->grand_total)->toBe('104.40');

    $apply(PosDiscountType::Foc, '0', $s['manager']);
    expect($order->fresh()->grand_total)->toBe('0.00')->and($line->fresh()->discount_approved_by_user_id)->toBe($s['manager']->id);
});

it('discounts the whole bill and replaces the last bill discount, then removes it', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['mocha']);
    $apply = fn (string $percent) => app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, $percent, null, 'Staff meal');

    expect($order->fresh()->grand_total)->toBe('132.24');

    $apply('10');
    expect($order->fresh()->discount_total)->toBe('11.40')->and($order->fresh()->subtotal)->toBe('102.60')->and($order->fresh()->grand_total)->toBe('119.02');

    $apply('20');
    expect($order->fresh()->discount_total)->toBe('22.80')->and($order->fresh()->grand_total)->toBe('105.79');

    app(RemovePosDiscount::class)($s['manager'], $s['property'], $order);
    expect($order->fresh()->discount_total)->toBe('0.00')->and($order->fresh()->grand_total)->toBe('132.24')->and($order->discounts()->count())->toBe(0);
});

it('needs a manager for any discount, or to remove one, once a pre-bill was printed', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '10', $line);
    $order->forceFill(['prebill_print_count' => 1])->save();

    expect(app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '5')->refusal)->toBe('approval_required')
        ->and(app(RemovePosDiscount::class)($s['manager'], $s['property'], $order, $line)->refusal)->toBe('approval_required')
        ->and(app(RemovePosDiscount::class)($s['manager'], $s['property'], $order, $line, $s['manager'])->isRefused())->toBeFalse()
        ->and($line->fresh()->discount_type)->toBeNull();
});

it('keeps another property out of an order', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['mocha']);

    expect(app(AddOrderLine::class)($other['manager'], $other['property'], $order, new PosOrderLineInputData($other['steak']->id))->refusal)->toBe('order_invalid')
        ->and(app(FireOrderLines::class)($other['manager'], $other['property'], $order)->refusal)->toBe('order_invalid')
        ->and(app(UpdateDraftOrderLine::class)($other['manager'], $other['property'], $order, $line, 2)->refusal)->toBe('order_invalid')
        ->and(app(RemoveDraftLine::class)($other['manager'], $other['property'], $order, $line)->refusal)->toBe('order_invalid')
        ->and(app(ApplyPosDiscount::class)($other['manager'], $other['property'], $order, PosDiscountType::FixedPercent, '5')->refusal)->toBe('order_invalid');
});
