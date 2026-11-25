<?php

use App\Actions\Pos\ChangePosDiningOption;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\PrintPreBill;
use App\Actions\Pos\SavePosVoidReason;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateStockCountdown;
use App\Actions\Pos\VoidOrderLine;
use App\Actions\Pos\VoidPosOrder;
use App\Enums\BusinessDateStatus;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\BusinessDate;
use App\Models\PosOutletItem;
use App\Models\PosVoidReason;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posVoidReason(array $s, array $overrides = []): PosVoidReason
{
    return PosVoidReason::factory()->create(array_merge(['property_id' => $s['property']->id, 'label' => 'Guest changed mind'], $overrides));
}

it('fires the draft lines on the first pre-bill and counts the print', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['mocha']);

    $result = app(PrintPreBill::class)($s['manager'], $s['property'], $order);

    expect($result->model->prebill_print_count)->toBe(1)
        ->and($order->lines()->pluck('kitchen_status')->unique()->all())->toBe([PosKitchenStatus::Fired])
        ->and(Activity::query()->where('event', 'pos.prebill_printed')->count())->toBe(1);
});

it('needs a manager to reprint the pre-bill when the outlet asks, and logs the reprint', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(PrintPreBill::class)($s['manager'], $s['property'], $order);

    expect(app(PrintPreBill::class)($s['manager'], $s['property'], $order)->refusal)->toBe('approval_required')
        ->and($order->fresh()->prebill_print_count)->toBe(1);

    $again = app(PrintPreBill::class)($s['manager'], $s['property'], $order, $s['manager']);
    expect($again->model->prebill_print_count)->toBe(2)
        ->and(Activity::query()->where('event', 'pos.prebill_reprinted')->sole()->properties['approved_by_user_id'])->toBe($s['manager']->id);

    $s['outlet']->forceFill(['require_reprint_pin' => false])->save();
    expect(app(PrintPreBill::class)($s['manager'], $s['property'], $order)->model->prebill_print_count)->toBe(3);
});

it('has nothing to bill on an empty order and cannot print for a closed order', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);

    expect(app(PrintPreBill::class)($s['manager'], $s['property'], $order)->refusal)->toBe('order_not_settleable');

    posAddLine($s, $order, $s['steak']);
    $order->forceFill(['status' => PosOrderStatus::Voided])->save();
    expect(app(PrintPreBill::class)($s['manager'], $s['property'], $order)->refusal)->toBe('order_not_open');
});

it('voids a draft line without a manager and prices the order again', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $steak = posAddLine($s, $order, $s['steak']);
    $mocha = posAddLine($s, $order, $s['mocha']);

    $result = app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $mocha, posVoidReason($s), 'Wrong item');

    expect($result->model->kitchen_status)->toBe(PosKitchenStatus::Voided)
        ->and($result->model->void_reason_text)->toBe('Wrong item')
        ->and($result->model->voided_by_user_id)->toBe($s['manager']->id)
        ->and($order->fresh()->grand_total)->toBe('116.00')
        ->and($steak->fresh()->kitchen_status)->toBe(PosKitchenStatus::Draft);
});

it('needs a manager to void a fired line or any line after a pre-bill, and keeps both names', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $reason = posVoidReason($s);

    expect(app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, $reason)->refusal)->toBe('approval_required')
        ->and($line->fresh()->kitchen_status)->toBe(PosKitchenStatus::Fired);

    $cashier = User::factory()->for($s['property'])->withPermissions(['pos.sell'])->create();
    $done = app(VoidOrderLine::class)($cashier, $s['property'], $order, $line, $reason, null, null, $s['manager']);

    expect($done->model->voided_by_user_id)->toBe($cashier->id)
        ->and($done->model->void_approved_by_user_id)->toBe($s['manager']->id)
        ->and($order->fresh()->grand_total)->toBe('0.00')
        ->and(Activity::query()->where('event', 'pos.line_voided')->sole()->properties['approved_by_user_id'])->toBe($s['manager']->id);

    $second = posNewOrder($s);
    $draft = posAddLine($s, $second, $s['mocha']);
    app(PrintPreBill::class)($s['manager'], $s['property'], $second);
    $extra = posAddLine($s, $second, $s['mocha']);

    expect(app(VoidOrderLine::class)($s['manager'], $s['property'], $second, $extra, $reason)->refusal)->toBe('approval_required')
        ->and(app(VoidOrderLine::class)($s['manager'], $s['property'], $second, $draft, $reason, null, null, $s['manager'])->isRefused())->toBeFalse();
});

it('returns the stock of a fired line when asked, and by the reason default', function () {
    $s = posOrderSetup();
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 10);
    $order = posNewOrder($s);
    $a = posAddLine($s, $order, $s['mocha'], 2);
    $b = posAddLine($s, $order, $s['mocha'], 3);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $stock = fn () => PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown;

    expect($stock())->toBe(5);

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $a, posVoidReason($s), null, true, $s['manager']);
    expect($stock())->toBe(7);

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $b, posVoidReason($s, ['label' => 'Spoiled']), null, false, $s['manager']);
    expect($stock())->toBe(7);

    $c = posNewOrder($s);
    $line = posAddLine($s, $c, $s['mocha'], 1);
    app(FireOrderLines::class)($s['manager'], $s['property'], $c);
    app(VoidOrderLine::class)($s['manager'], $s['property'], $c, $line, posVoidReason($s, ['label' => 'Remade', 'default_return_stock' => true]), null, null, $s['manager']);
    expect($stock())->toBe(7);
});

it('refuses a void with no valid reason, on the packaging line, on a settled order and for another property', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $s['takeaway']->forceFill(['packaging_fee' => '1.00'])->save();
    $order = posNewOrder($s, $s['takeaway']);
    $line = posAddLine($s, $order, $s['mocha']);
    $packaging = $order->lines()->where('line_kind', 'packaging_fee')->sole();
    $reason = posVoidReason($s);

    expect(app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, posVoidReason($other))->refusal)->toBe('void_reason_invalid')
        ->and(app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, posVoidReason($s, ['label' => 'Off', 'active' => false]))->refusal)->toBe('void_reason_invalid')
        ->and(app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $packaging, $reason)->refusal)->toBe('line_locked')
        ->and(app(VoidOrderLine::class)($other['manager'], $other['property'], $order, $line, posVoidReason($other, ['label' => 'Other']))->refusal)->toBe('order_invalid');

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, $reason);
    expect(app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, $reason)->refusal)->toBe('line_locked')
        ->and($order->lines()->where('line_kind', 'packaging_fee')->count())->toBe(0);

    $paid = posNewOrder($s);
    $paidLine = posAddLine($s, $paid, $s['mocha']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $paid, [posCash('16.24')]);
    expect(app(VoidOrderLine::class)($s['manager'], $s['property'], $paid, $paidLine, $reason, null, null, $s['manager'])->refusal)->toBe('order_not_open');
});

it('voids a whole draft order without a manager and stamps the open business date', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['mocha']);

    $result = app(VoidPosOrder::class)($s['manager'], $s['property'], $order, posVoidReason($s), 'Guest left');
    $voided = $result->model;

    expect($voided->status)->toBe(PosOrderStatus::Voided)
        ->and($voided->business_date->toDateString())->toBe('2026-10-14')
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->void_reason_text)->toBe('Guest left')
        ->and($voided->voided_by_user_id)->toBe($s['manager']->id)
        ->and($voided->order_number)->toBeNull()
        ->and($order->lines()->pluck('kitchen_status')->unique()->all())->toBe([PosKitchenStatus::Voided]);
});

it('needs a manager to void an order with fired lines, and returns their stock', function () {
    $s = posOrderSetup();
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 10);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha'], 4);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $reason = posVoidReason($s);

    expect(app(VoidPosOrder::class)($s['manager'], $s['property'], $order, $reason)->refusal)->toBe('approval_required')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open);

    $voided = app(VoidPosOrder::class)($s['manager'], $s['property'], $order, $reason, null, true, $s['manager'])->model;

    expect($voided->void_approved_by_user_id)->toBe($s['manager']->id)
        ->and(PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown)->toBe(10);
});

it('cannot void a settled order, an order of another property, or while the date is closing', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    $reason = posVoidReason($s);

    expect(app(VoidPosOrder::class)($other['manager'], $other['property'], $order, posVoidReason($other))->refusal)->toBe('order_invalid');

    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Auditing]);
    expect(app(VoidPosOrder::class)($s['manager'], $s['property'], $order, $reason)->refusal)->toBe('business_date_auditing');

    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Open]);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')]);
    expect(app(VoidPosOrder::class)($s['manager'], $s['property'], $order, $reason, null, null, $s['manager'])->refusal)->toBe('order_not_open');
});

it('changes the dining option and prices again, with a manager after a pre-bill', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    app(ChangePosDiningOption::class)($s['manager'], $s['property'], $order, $s['takeaway']);
    expect($order->fresh()->pos_dining_option_id)->toBe($s['takeaway']->id)->and($order->fresh()->service_charge_total)->toBe('0.00')->and($order->fresh()->grand_total)->toBe('106.00');

    app(PrintPreBill::class)($s['manager'], $s['property'], $order);
    expect(app(ChangePosDiningOption::class)($s['manager'], $s['property'], $order, $s['dineIn'])->refusal)->toBe('approval_required')
        ->and(app(ChangePosDiningOption::class)($s['manager'], $s['property'], $order, $s['dineIn'], $s['manager'])->model->grand_total)->toBe('116.00');
});

it('refuses a dining option of another outlet or one that is off', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    $s['takeaway']->forceFill(['active' => false])->save();

    expect(app(ChangePosDiningOption::class)($s['manager'], $s['property'], $order, $other['takeaway'])->refusal)->toBe('dining_option_invalid')
        ->and(app(ChangePosDiningOption::class)($s['manager'], $s['property'], $order, $s['takeaway'])->refusal)->toBe('dining_option_invalid');
});

it('keeps void reason labels unique per property, ignoring case', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $save = app(SavePosVoidReason::class);

    $first = $save($s['manager'], $s['property'], 'Spoiled', true, true)->model;

    expect($first->default_return_stock)->toBeTrue()
        ->and($save($s['manager'], $s['property'], ' spoiled ')->refusal)->toBe('label_taken')
        ->and($save($s['manager'], $s['property'], '  ')->refusal)->toBe('label_required')
        ->and($save($other['manager'], $other['property'], 'Spoiled')->isRefused())->toBeFalse()
        ->and($save($s['manager'], $s['property'], 'Spoiled', true, false, false, 0, $other['manager']->roles()->exists() ? $first : $first)->model->active)->toBeFalse();

    $foreign = PosVoidReason::factory()->create(['property_id' => $other['property']->id]);
    expect($save($s['manager'], $s['property'], 'Renamed', reason: $foreign)->refusal)->toBe('void_reason_invalid');
});
