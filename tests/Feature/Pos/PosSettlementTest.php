<?php

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\ApplyPosDiscount;
use App\Actions\Pos\ChangePosPaymentMethod;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosOrderLineInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\BusinessDateStatus;
use App\Enums\PosDiscountType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Features\NativePos;
use App\Models\BusinessDate;
use App\Models\DocumentSequence;
use App\Models\PosOrderPayment;
use App\Models\PosOutletItem;
use App\Models\Tax;
use App\Services\Pos\PosShiftCashTotals;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posSettle(array $s, $order, array $tenders, array $options = []): PosActionResult
{
    return app(SettlePosOrder::class)($s['manager'], $s['property'], $order, $tenders, ...$options);
}

it('settles a cash order, keeps the change apart and stamps the business date and number', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    $result = posSettle($s, $order, [posCash('116.00', '120.00')]);
    $settled = $result->model;
    $payment = PosOrderPayment::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($settled->status)->toBe(PosOrderStatus::Settled)
        ->and($settled->business_date->toDateString())->toBe('2026-10-14')
        ->and($settled->settled_at)->not->toBeNull()
        ->and($settled->closed_by_user_id)->toBe($s['manager']->id)
        ->and($settled->order_number)->toEndWith('1000001')
        ->and($settled->receipt_claim_token)->toHaveLength(32)
        ->and($settled->folio_posted_amount)->toBe('0.00')
        ->and($settled->settled_on_folio)->toBeFalse()
        ->and($settled->rounding_adjustment)->toBe('0.00')
        ->and($payment->amount)->toBe('116.00')
        ->and($payment->tendered_amount)->toBe('120.00')
        ->and($payment->change_amount)->toBe('4.00')
        ->and($payment->payment_method_id)->toBe($s['methods']['cash']->id)
        ->and($payment->pos_shift_id)->toBe($s['shift']->id)
        ->and($payment->status)->toBe(PosPaymentStatus::Captured)
        ->and($payment->idempotency_key)->toBe('settle:'.$settled->id.':0')
        ->and($payment->acted_by_user_id)->toBe($s['manager']->id)
        ->and(Activity::query()->where('event', 'pos.order_settled')->count())->toBe(1);
});

it('keeps RM 100 handed over for a RM 44 bill as RM 44 and RM 56 change', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['pasta']);

    $payment = (posSettle($s, $order, [posCash('44.00', '100.00')]))->model->payments()->sole();

    expect($payment->amount)->toBe('44.00')->and($payment->tendered_amount)->toBe('100.00')->and($payment->change_amount)->toBe('56.00');
});

it('rounds cash to 5 sen and keeps the adjustment on the cash row and the order', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    $result = posSettle($s, $order, [posCash('16.24')]);
    $payment = $result->model->payments()->sole();

    expect($order->fresh()->grand_total)->toBe('16.24')
        ->and($payment->amount)->toBe('16.24')
        ->and($payment->rounding_adjustment)->toBe('0.01')
        ->and($payment->tendered_amount)->toBe('16.25')
        ->and($payment->change_amount)->toBe('0.00')
        ->and($result->model->rounding_adjustment)->toBe('0.01')
        ->and(app(PosShiftCashTotals::class)->for($s['shift'])['cash_payments'])->toBe('16.25');
});

it('needs the cash handed over to cover the rounded bill', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(posSettle($s, $order, [posCash('16.24', '16.20')])->refusal)->toBe('tendered_cash_insufficient')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and(posSettle($s, $order, [posCash('16.24', '16.25')])->isRefused())->toBeFalse();
});

it('takes a split between cash and card without rounding the card share', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);

    $settled = posSettle($s, $order, [posCash('100.00'), posCard('60.00')])->model;
    $cash = $settled->payments()->where('tender_type', 'cash')->sole();
    $card = $settled->payments()->where('tender_type', 'card')->sole();

    expect($settled->grand_total)->toBe('160.00')
        ->and($cash->rounding_adjustment)->toBe('0.00')
        ->and($card->payment_method_id)->toBe($s['methods']['card']->id)
        ->and($card->reference)->toBe('AUTH123')
        ->and($card->tendered_amount)->toBeNull()
        ->and($card->change_amount)->toBeNull()
        ->and($settled->folio_posted_amount)->toBe('0.00')
        ->and($settled->payments()->pluck('idempotency_key')->all())->toBe(['settle:'.$settled->id.':0', 'settle:'.$settled->id.':1']);
});

it('rounds the whole bill under WholeBillWhenCashPresent', function () {
    $s = posOrderSetup();
    $s['outlet']->forceFill(['rounding_policy' => 'whole_bill_when_cash_present'])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    $settled = posSettle($s, $order, [posCash('10.00'), posCard('6.24')])->model;

    expect($settled->payments()->where('tender_type', 'cash')->sole()->rounding_adjustment)->toBe('0.01')
        ->and($settled->rounding_adjustment)->toBe('0.01');
});

it('adds no rounding when the outlet has it off', function () {
    $s = posOrderSetup();
    $s['outlet']->forceFill(['rounding_mode' => 'none'])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(posSettle($s, $order, [posCash('16.24')])->model->rounding_adjustment)->toBe('0.00');
});

it('enforces exact tenders: no underpayment and no overpayment of the bill', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    expect(posSettle($s, $order, [posCash('115.99')])->refusal)->toBe('insufficient_payment')
        ->and(posSettle($s, $order, [posCash('100.00'), posCard('15.00')])->refusal)->toBe('insufficient_payment')
        ->and(posSettle($s, $order, [posCash('116.01')])->refusal)->toBe('overpayment_not_allowed')
        ->and(posSettle($s, $order, [posCash('100.00'), posCard('16.01')])->refusal)->toBe('overpayment_not_allowed')
        ->and(posSettle($s, $order, [])->refusal)->toBe('insufficient_payment')
        ->and(posSettle($s, $order, [posCash('0.00'), posCash('116.00')])->refusal)->toBe('tender_invalid')
        ->and(posSettle($s, $order, [posCash('-5.00'), posCash('121.00')])->refusal)->toBe('tender_invalid')
        ->and(posSettle($s, $order, [posCash('abc')])->refusal)->toBe('tender_invalid')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and(PosOrderPayment::query()->count())->toBe(0)
        ->and(DocumentSequence::query()->where('type', 'pos_order')->count())->toBe(0);
});

it('refuses a tender the outlet does not take', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    expect(posSettle($s, $order, [new PosTenderInputData(PosTenderType::EWallet, '116.00')])->refusal)->toBe('payment_method_invalid')
        ->and(posSettle($s, $order, [new PosTenderInputData(PosTenderType::Card, '116.00', paymentMethodId: $s['methods']['cash']->id)])->refusal)->toBe('payment_method_invalid')
        ->and(posSettle($s, $order, [new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: 999999)])->refusal)->toBe('unit_not_eligible');
});

it('settles an order once and returns it when settled again', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    $first = posSettle($s, $order, [posCash('116.00')]);
    $again = posSettle($s, $order, [posCash('116.00')]);

    expect($again->replayed)->toBeTrue()
        ->and($again->model->id)->toBe($first->model->id)
        ->and($again->model->order_number)->toBe($first->model->order_number)
        ->and(PosOrderPayment::query()->count())->toBe(1)
        ->and(DocumentSequence::query()->where('type', 'pos_order')->sole()->last_number)->toBe(1);
});

it('numbers each settled order from its own sequence', function () {
    $s = posOrderSetup();
    $numbers = [];

    foreach (range(1, 3) as $_) {
        $order = posNewOrder($s);
        posAddLine($s, $order, $s['steak']);
        $numbers[] = posSettle($s, $order, [posCash('116.00')])->model->order_number;
    }

    expect(array_map(fn (string $number): string => substr($number, -7), $numbers))->toBe(['1000001', '1000002', '1000003'])
        ->and(DocumentSequence::query()->where('type', 'pos_shift')->sole()->last_number)->toBe(1);
});

it('refuses an order with no lines, a closing shift and Native POS being off', function () {
    $s = posOrderSetup();
    $empty = posNewOrder($s);
    expect(posSettle($s, $empty, [posCash('0.01')])->refusal)->toBe('order_not_settleable');

    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    $s['shift']->forceFill(['status' => PosShiftStatus::ClosingControl])->save();
    expect(posSettle($s, $order, [posCash('116.00')])->refusal)->toBe('shift_not_open');

    $s['shift']->forceFill(['status' => PosShiftStatus::Open])->save();
    Feature::for($s['property'])->deactivate(NativePos::class);
    expect(posSettle($s, $order, [posCash('116.00')])->refusal)->toBe('native_pos_disabled');
});

it('refuses an order of another property', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    expect(app(SettlePosOrder::class)($other['manager'], $other['property'], $order, [posCash('116.00')])->refusal)->toBe('order_invalid');
});

it('refuses with the calculator reason and leaves the order untouched when SST has no number', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    Tax::query()->where('code', 'SST')->update(['registration_number' => null]);

    expect(posSettle($s, $order, [posCash('116.00')])->refusal)->toBe('sst_registration_missing')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and($order->lines()->first()->kitchen_status)->toBe(PosKitchenStatus::Draft);
});

it('fires the draft lines and takes stock when it settles', function () {
    $s = posOrderSetup();
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 5);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha'], 2);

    posSettle($s, $order, [posCash('32.48')]);

    expect($order->lines()->sole()->kitchen_status)->toBe(PosKitchenStatus::Fired)
        ->and(PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown)->toBe(3);
});

it('keeps the fired state off when a refusal rolls the settlement back', function () {
    $s = posOrderSetup();
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 5);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    posSettle($s, $order, [posCash('1.00')]);

    expect($order->lines()->sole()->kitchen_status)->toBe(PosKitchenStatus::Draft)
        ->and(PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown)->toBe(5);
});

it('settles a discounted bill at the discounted total', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '10');

    expect(posSettle($s, $order, [posCash('104.00')])->refusal)->toBe('insufficient_payment')
        ->and(posSettle($s, $order, [posCash('104.40')])->model->grand_total)->toBe('104.40');
});

it('settles an order opened before the audit onto the new business date', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id]);
    BusinessDate::factory()->create(['property_id' => $s['property']->id, 'date' => '2026-10-15', 'status' => BusinessDateStatus::Open]);

    $settled = posSettle($s, $order, [posCash('116.00')])->model;

    expect($settled->business_date->toDateString())->toBe('2026-10-15')
        ->and($settled->opened_at->toDateString())->toBe('2026-10-14')
        ->and($s['shift']->fresh()->opened_business_date->toDateString())->toBe('2026-10-14');
});

it('waits out the audit and needs a business date, without leaving anything behind', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Auditing]);
    expect(posSettle($s, $order, [posCash('116.00')])->refusal)->toBe('business_date_auditing')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and(PosOrderPayment::query()->count())->toBe(0);
});

it('does not take new lines on a settled order and never prices it again', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettle($s, $order, [posCash('116.00')]);
    $s['steak']->forceFill(['price' => '999.00'])->save();

    expect(app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData($s['mocha']->id))->refusal)->toBe('order_not_open')
        ->and(app(FireOrderLines::class)($s['manager'], $s['property'], $order)->refusal)->toBe('order_not_open')
        ->and($order->fresh()->grand_total)->toBe('116.00');
});

it('counts the cash of settled orders in the shift expected cash', function () {
    $s = posOrderSetup();

    foreach (['116.00', '44.00'] as $price) {
        $order = posNewOrder($s);
        posAddLine($s, $order, $price === '116.00' ? $s['steak'] : $s['pasta']);
        posSettle($s, $order, [posCash($price)]);
    }

    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettle($s, $order, [posCard('116.00')]);

    expect(app(PosShiftCashTotals::class)->for($s['shift'])['expected_cash'])->toBe('360.00');
});

it('swaps a cash tender for card while the shift and business date are open, keeping the replaced row', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24', '20.00')]);
    $payment = $order->refresh()->payments()->sole();

    expect(app(ChangePosPaymentMethod::class)($s['manager'], $s['property'], $order, $payment, PosTenderType::Card)->refusal)->toBe('approval_required');

    $result = app(ChangePosPaymentMethod::class)($s['manager'], $s['property'], $order, $payment, PosTenderType::Card, null, 'AUTH9', $s['manager']);
    $new = $result->model;

    expect($result->isRefused())->toBeFalse()
        ->and($payment->refresh()->status)->toBe(PosPaymentStatus::Replaced)->and($payment->replaced_by_payment_id)->toBe($new->id)
        ->and($new->tender_type)->toBe(PosTenderType::Card)->and($new->amount)->toBe($payment->amount)->and($new->rounding_adjustment)->toBe('0.00')
        ->and($order->refresh()->rounding_adjustment)->toBe('0.00')
        ->and(app(ChangePosPaymentMethod::class)($s['manager'], $s['property'], $order, $new, PosTenderType::RoomCharge, null, null, $s['manager'])->refusal)->toBe('tender_swap_not_allowed');
});

it('refuses a tender swap once the shift is closed or the payment has a refund', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCard('16.24')]);
    $payment = $order->refresh()->payments()->sole();

    $s['shift']->forceFill(['status' => 'closing_control'])->save();

    expect(app(ChangePosPaymentMethod::class)($s['manager'], $s['property'], $order, $payment, PosTenderType::Cash, null, null, $s['manager'])->refusal)->toBe('tender_swap_not_allowed');

    $s['shift']->forceFill(['status' => 'open'])->save();
    $refund = $payment->replicate(['idempotency_key', 'status']);
    $refund->idempotency_key = 'refund-test';
    $refund->direction = PosPaymentDirection::Out;
    $refund->refund_of_payment_id = $payment->id;
    $refund->status = PosPaymentStatus::Captured;
    $refund->save();

    expect(app(ChangePosPaymentMethod::class)($s['manager'], $s['property'], $order, $payment, PosTenderType::Cash, null, null, $s['manager'])->refusal)->toBe('payment_has_refunds');
});
