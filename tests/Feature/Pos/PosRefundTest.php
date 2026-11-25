<?php

use App\Actions\Pos\ApplyPosDiscount;
use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\RefundPosOrder;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosRefundLineInputData;
use App\Data\Pos\PosRefundPayoutInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\FolioStatus;
use App\Enums\PosDiscountType;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Enums\PosPaymentDirection;
use App\Enums\PosTenderType;
use App\Enums\TransactionKind;
use App\Features\Pos;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\MealRedemption;
use App\Models\PosOrder;
use App\Models\PosOrderFolioLink;
use App\Models\PosOrderLine;
use App\Models\PosOrderPayment;
use App\Models\PosOutletItem;
use App\Services\Folio\FolioBalance;
use App\Services\Pos\PosShiftCashTotals;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posRefund(array $s, PosOrder $order, array $lines, ?array $payouts = null, array $options = []): PosActionResult
{
    return app(RefundPosOrder::class)(...array_merge([
        'actor' => $s['manager'],
        'property' => $s['property'],
        'original' => $order,
        'lines' => $lines,
        'reason' => 'Guest returned the dish',
        'clientUuid' => (string) Str::uuid(),
        'approvedBy' => $s['manager'],
        'shift' => null,
        'payouts' => $payouts,
    ], $options));
}

function posSettledCash(array $s, array $variants = []): PosOrder
{
    $order = posNewOrder($s);

    foreach ($variants ?: [$s['steak']] as $variant) {
        posAddLine($s, $order, $variant);
    }

    return app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;
}

it('refunds a whole line: its exact net, service charge and tax go back as a refund order', function () {
    $s = posOrderSetup();
    $order = posSettledCash($s, [$s['steak'], $s['mocha']]);
    $steak = $order->lines()->where('item_name', 'Steak')->sole();

    $result = posRefund($s, $order, [new PosRefundLineInputData($steak->id, 1)]);
    $refund = $result->model;
    $line = $refund->lines()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($refund->order_type)->toBe(PosOrderType::Refund)
        ->and($refund->status)->toBe(PosOrderStatus::Settled)
        ->and($refund->refund_for_order_id)->toBe($order->id)
        ->and($refund->business_date->toDateString())->toBe('2026-10-14')
        ->and($refund->order_number)->toEndWith('1000002')
        ->and($refund->subtotal)->toBe('100.00')
        ->and($refund->service_charge_total)->toBe('10.00')
        ->and($refund->tax_total)->toBe('6.00')
        ->and($refund->grand_total)->toBe('116.00')
        ->and($refund->order_note)->toBe('Guest returned the dish')
        ->and($line->refund_of_order_line_id)->toBe($steak->id)
        ->and($line->quantity)->toBe('1.000')
        ->and($line->net_amount)->toBe('100.00')
        ->and($line->line_total)->toBe('116.00')
        ->and($line->taxes()->sole()->tax_amount)->toBe('6.00')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Settled);

    $payout = $refund->payments()->sole();
    expect($payout->direction)->toBe(PosPaymentDirection::Out)
        ->and($payout->tender_type)->toBe(PosTenderType::Cash)
        ->and($payout->refund_of_payment_id)->toBe($order->payments()->sole()->id)
        ->and($payout->amount)->toBe('116.00')
        ->and($payout->approved_by_user_id)->toBe($s['manager']->id)
        ->and($payout->idempotency_key)->toBe('refund:'.$refund->id.':0');
});

it('refunds the spec pasta in cash from a cash and room bill: RM 44.00 and a RM 256.00 expected drawer', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), new PosTenderInputData(PosTenderType::RoomCharge, '60.00', reservationUnitId: $s['unit']->id)], guestSigned: true);
    $cash = $order->payments()->where('tender_type', 'cash')->sole();
    $pasta = $order->lines()->where('item_name', 'Pasta')->sole();

    $refund = posRefund($s, $order, [new PosRefundLineInputData($pasta->id, 1)], [new PosRefundPayoutInputData($cash->id, '44.00')])->model;

    expect($refund->subtotal)->toBe('37.93')
        ->and($refund->service_charge_total)->toBe('3.79')
        ->and($refund->tax_total)->toBe('2.28')
        ->and($refund->grand_total)->toBe('44.00')
        ->and($refund->folio_posted_amount)->toBe('0.00')
        ->and(app(PosShiftCashTotals::class)->for($s['shift'])['expected_cash'])->toBe('256.00');
});

it('refunds in parts and the last part takes exactly what is left', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha'], 3);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('48.72')])->model;
    $line = $order->lines()->sole();

    $totals = [];

    foreach ([1, 1, 1] as $_) {
        $totals[] = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->model->lines()->sole();
    }

    expect(collect($totals)->pluck('net_amount')->all())->toBe(['14.00', '14.00', '14.00'])
        ->and(collect($totals)->sum(fn ($part): float => (float) $part->line_total))->toBe(48.72)
        ->and(posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->refusal)->toBe('exceeds_refundable_balance');
});

it('shares the last sen across parts so a three way split of one line adds back to the original', function () {
    $s = posOrderSetup();
    $s['mocha']->forceFill(['price' => '9.99'])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha'], 3);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;
    $line = $order->lines()->sole();
    $parts = collect(range(1, 3))->map(fn () => posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->model->lines()->sole());

    expect($parts->sum(fn ($part): float => round((float) $part->net_amount * 100)))->toBe(round((float) $line->net_amount * 100))
        ->and($parts->sum(fn ($part): float => round((float) $part->service_charge_amount * 100)))->toBe(round((float) $line->service_charge_amount * 100))
        ->and($parts->sum(fn ($part): float => round((float) $part->tax_amount * 100)))->toBe(round((float) $line->tax_amount * 100))
        ->and($parts->sum(fn ($part): float => round((float) $part->line_total * 100)))->toBe(round((float) $line->line_total * 100));
});

it('refunds two identical dishes at their own discounts', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $a = posAddLine($s, $order, $s['steak']);
    $b = posAddLine($s, $order, $s['steak']);
    app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '10', $a);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;

    $first = posRefund($s, $order, [new PosRefundLineInputData($a->id, 1)])->model->lines()->sole();
    $second = posRefund($s, $order, [new PosRefundLineInputData($b->id, 1)])->model->lines()->sole();

    expect($first->refund_of_order_line_id)->toBe($a->id)->and($first->net_amount)->toBe('90.00')->and($first->discount_amount)->toBe('10.00')->and($first->line_total)->toBe('104.40')
        ->and($second->refund_of_order_line_id)->toBe($b->id)->and($second->net_amount)->toBe('100.00')->and($second->discount_amount)->toBe('0.00')->and($second->line_total)->toBe('116.00');
});

it('shares a refund over the original payments in proportion, never above what each has left', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), posCard('60.00')])->model;
    $steak = $order->lines()->where('item_name', 'Steak')->sole();

    $refund = posRefund($s, $order, [new PosRefundLineInputData($steak->id, 1)])->model;
    $payouts = $refund->payments()->orderBy('id')->get();

    expect($payouts->pluck('amount')->all())->toBe(['72.50', '43.50'])
        ->and($payouts->pluck('tender_type')->map->value->all())->toBe(['cash', 'card'])
        ->and($payouts->sum(fn ($payout): float => (float) $payout->amount))->toBe(116.0);
});

it('refuses a payout above what an original payment has left and one that does not add up', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), posCard('60.00')])->model;
    $cash = $order->payments()->where('tender_type', 'cash')->sole();
    $card = $order->payments()->where('tender_type', 'card')->sole();
    $steak = $order->lines()->where('item_name', 'Steak')->sole();
    $lines = [new PosRefundLineInputData($steak->id, 1)];

    expect(posRefund($s, $order, $lines, [new PosRefundPayoutInputData($card->id, '116.00')])->refusal)->toBe('exceeds_refundable_balance')
        ->and(posRefund($s, $order, $lines, [new PosRefundPayoutInputData($cash->id, '100.00')])->refusal)->toBe('refund_payout_mismatch')
        ->and(posRefund($s, $order, $lines, [new PosRefundPayoutInputData($cash->id, '58.00'), new PosRefundPayoutInputData($cash->id, '58.00')])->refusal)->toBe('refund_payout_invalid')
        ->and(posRefund($s, $order, $lines, [new PosRefundPayoutInputData(999999, '116.00')])->refusal)->toBe('refund_payout_invalid')
        ->and(PosOrder::query()->where('order_type', 'refund')->count())->toBe(0);

    posRefund($s, $order, $lines, [new PosRefundPayoutInputData($cash->id, '100.00'), new PosRefundPayoutInputData($card->id, '16.00')]);
    $pasta = $order->lines()->where('item_name', 'Pasta')->sole();

    expect(posRefund($s, $order, [new PosRefundLineInputData($pasta->id, 1)], [new PosRefundPayoutInputData($cash->id, '44.00')])->refusal)->toBe('exceeds_refundable_balance');
});

it('rounds a cash refund as the sale was rounded', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')])->model;
    $line = $order->lines()->sole();

    $refund = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->model;

    expect($refund->payments()->sole()->rounding_adjustment)->toBe('0.01')
        ->and($refund->rounding_adjustment)->toBe('0.01')
        ->and(app(PosShiftCashTotals::class)->for($s['shift'])['cash_refunds'])->toBe('16.25')
        ->and(app(PosShiftCashTotals::class)->for($s['shift'])['expected_cash'])->toBe('200.00');
});

it('refunds the packaging fee with the last item', function () {
    $s = posOrderSetup();
    $s['takeaway']->forceFill(['packaging_fee' => '1.00'])->save();
    $order = posNewOrder($s, $s['takeaway']);
    posAddLine($s, $order, $s['mocha'], 2);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;
    $line = $order->lines()->where('line_kind', 'item')->sole();

    $part = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->model;
    expect($part->lines()->count())->toBe(1)->and($part->grand_total)->toBe('14.84');

    $last = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->model;
    $refunded = PosOrder::query()->where('order_type', 'refund')->get()->sum(fn ($refund): float => (float) $refund->grand_total);

    expect($last->lines()->count())->toBe(2)
        ->and($last->lines()->where('line_kind', 'packaging_fee')->sole()->net_amount)->toBe('1.00')
        ->and(round($refunded, 2))->toBe((float) $order->grand_total);
});

it('puts the stock back when asked and gives the included meal back', function () {
    $s = posOrderSetup(true);
    app(UpdateStockCountdown::class)($s['manager'], $s['property'], $s['outlet'], $s['mocha'], 10);
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['breakfast'], 2, ['coverWithEntitlement' => true]);
    posAddLine($s, $order, $s['mocha'], 2);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('32.48')], mealConfirmed: true)->model;
    $mocha = $order->lines()->where('item_name', 'Iced Mocha')->sole();
    $breakfast = $order->lines()->where('is_entitlement_covered', true)->orderBy('id')->first();
    $stock = fn () => PosOutletItem::query()->where('pos_item_variant_id', $s['mocha']->id)->sole()->stock_countdown;

    expect($stock())->toBe(8);

    posRefund($s, $order, [new PosRefundLineInputData($mocha->id, 1, returnStock: true), new PosRefundLineInputData($breakfast->id, 1, restoreMealEntitlement: true)]);

    expect($stock())->toBe(9)
        ->and(MealRedemption::query()->active()->count())->toBe(1)
        ->and($breakfast->mealRedemption->fresh()->undone_at)->not->toBeNull();

    posRefund($s, $order, [new PosRefundLineInputData($mocha->id, 1)]);
    expect($stock())->toBe(9);
});

it('refunds a covered breakfast for nothing and moves no money', function () {
    $s = posOrderSetup(true);
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['breakfast'], 1, ['coverWithEntitlement' => true]);
    posAddLine($s, $order, $s['mocha']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')], mealConfirmed: true)->model;
    $breakfast = $order->lines()->where('is_entitlement_covered', true)->sole();

    $result = posRefund($s, $order, [new PosRefundLineInputData($breakfast->id, 1)]);

    expect($result->model->grand_total)->toBe('0.00')
        ->and($result->model->payments()->count())->toBe(0);
});

it('refuses a refund on an order that is open, voided, a refund itself or of another property', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $open = posNewOrder($s);
    $line = posAddLine($s, $open, $s['steak']);
    $settled = posSettledCash($s);
    $settledLine = $settled->lines()->sole();
    $refundOrder = posRefund($s, $settled, [new PosRefundLineInputData($settledLine->id, 1)])->model;

    expect(posRefund($s, $open, [new PosRefundLineInputData($line->id, 1)])->refusal)->toBe('order_not_refundable')
        ->and(posRefund($s, $refundOrder, [new PosRefundLineInputData($refundOrder->lines()->sole()->id, 1)])->refusal)->toBe('order_not_refundable')
        ->and(posRefund($other, $settled, [new PosRefundLineInputData($settledLine->id, 1)])->refusal)->toBe('order_not_refundable');
});

it('refuses a refund with no reason, no lines, a foreign line, or a quantity above what is left', function () {
    $s = posOrderSetup();
    $other = posSettledCash(posOrderSetup());
    $order = posSettledCash($s, [$s['mocha']]);
    $line = $order->lines()->sole();

    expect(posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)], null, ['reason' => '  '])->refusal)->toBe('reason_required')
        ->and(posRefund($s, $order, [])->refusal)->toBe('refund_lines_invalid')
        ->and(posRefund($s, $order, [new PosRefundLineInputData($other->lines()->sole()->id, 1)])->refusal)->toBe('refund_lines_invalid')
        ->and(posRefund($s, $order, [new PosRefundLineInputData($line->id, 1), new PosRefundLineInputData($line->id, 1)])->refusal)->toBe('refund_lines_invalid')
        ->and(posRefund($s, $order, [new PosRefundLineInputData($line->id, 2)])->refusal)->toBe('exceeds_refundable_balance')
        ->and(posRefund($s, $order, [new PosRefundLineInputData($line->id, 0)])->refusal)->toBe('exceeds_refundable_balance');
});

it('needs an open shift and an open business date', function () {
    $s = posOrderSetup();
    $order = posSettledCash($s, [$s['mocha']]);
    $line = $order->lines()->sole();

    $s['shift']->forceFill(['status' => 'closing_control'])->save();
    expect(posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->refusal)->toBe('shift_not_open');

    $s['shift']->forceFill(['status' => 'open'])->save();
    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => 'auditing']);
    expect(posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)])->refusal)->toBe('business_date_auditing');
});

it('returns the same refund for the same client uuid and writes nothing twice', function () {
    $s = posOrderSetup();
    $order = posSettledCash($s, [$s['mocha']]);
    $line = $order->lines()->sole();
    $uuid = (string) Str::uuid();

    $first = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)], null, ['clientUuid' => $uuid]);
    $again = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)], null, ['clientUuid' => $uuid]);

    expect($again->replayed)->toBeTrue()
        ->and($again->model->id)->toBe($first->model->id)
        ->and(PosOrder::query()->where('order_type', 'refund')->count())->toBe(1)
        ->and(PosOrderPayment::query()->where('direction', 'out')->count())->toBe(1);
});

it('refuses while Native POS is off', function () {
    $s = posOrderSetup();
    $order = posSettledCash($s, [$s['mocha']]);
    Feature::for($s['property'])->deactivate(Pos::class);

    expect(posRefund($s, $order, [new PosRefundLineInputData($order->lines()->sole()->id, 1)])->refusal)->toBe('pos_disabled');
});

it('reverses a whole untouched room charge once, tax lines included', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: $s['unit']->id)], guestSigned: true)->model;
    $folio = Folio::query()->sole();

    $refund = posRefund($s, $order, [new PosRefundLineInputData($order->lines()->sole()->id, 1)])->model;
    $payout = $refund->payments()->sole();

    expect($payout->tender_type)->toBe(PosTenderType::RoomCharge)
        ->and($payout->payment_method_id)->toBeNull()
        ->and($refund->folio_posted_amount)->toBe('116.00')
        ->and($refund->settled_on_folio)->toBeTrue()
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('0.00')
        ->and(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(3)
        ->and(PosOrderFolioLink::query()->get()->every(fn ($link): bool => $link->refunded_net === $link->net_amount && $link->refunded_tax === $link->tax_amount))->toBeTrue();
});

it('credits part of a room charge with negative net and tax, and the last part takes the rest', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak'], 2);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::RoomCharge, '232.00', reservationUnitId: $s['unit']->id)], guestSigned: true)->model;
    $folio = Folio::query()->sole();
    $line = $order->lines()->sole();

    posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)]);
    $credit = FolioLine::query()->where('folio_id', $folio->id)->whereNull('reversal_of_id')->where('amount', '<', 0)->whereNull('parent_line_id')->get();

    expect($credit->pluck('amount')->sort()->values()->all())->toBe(['-100.00', '-10.00'])
        ->and(FolioLine::query()->where('kind', TransactionKind::Tax)->where('amount', '<', 0)->sole()->amount)->toBe('-6.00')
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('116.00');

    posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)]);

    expect(app(FolioBalance::class)->forFolio($folio))->toBe('0.00')
        ->and(PosOrderFolioLink::query()->get()->every(fn ($link): bool => $link->refunded_net === $link->net_amount && $link->refunded_tax === $link->tax_amount))->toBeTrue();
});

it('splits a mixed cash and room refund so neither original payment goes below zero', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), new PosTenderInputData(PosTenderType::RoomCharge, '60.00', reservationUnitId: $s['unit']->id)], guestSigned: true)->model;
    $cash = $order->payments()->where('tender_type', 'cash')->sole();
    $room = $order->payments()->where('tender_type', 'room_charge')->sole();
    $folio = Folio::query()->sole();
    $steak = $order->lines()->where('item_name', 'Steak')->sole();
    $pasta = $order->lines()->where('item_name', 'Pasta')->sole();

    expect(posRefund($s, $order, [new PosRefundLineInputData($steak->id, 1)], [new PosRefundPayoutInputData($cash->id, '50.00'), new PosRefundPayoutInputData($room->id, '66.00')])->refusal)->toBe('exceeds_refundable_balance');

    posRefund($s, $order, [new PosRefundLineInputData($steak->id, 1)], [new PosRefundPayoutInputData($cash->id, '100.00'), new PosRefundPayoutInputData($room->id, '16.00')]);
    expect(app(FolioBalance::class)->forFolio($folio))->toBe('44.00');

    posRefund($s, $order, [new PosRefundLineInputData($pasta->id, 1)], [new PosRefundPayoutInputData($room->id, '44.00')]);
    expect(app(FolioBalance::class)->forFolio($folio))->toBe('0.00');
});

it('cannot refund a room charge onto a closed folio and leaves everything as it was', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: $s['unit']->id)], guestSigned: true)->model;
    Folio::query()->update(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id]);

    $result = posRefund($s, $order, [new PosRefundLineInputData($order->lines()->sole()->id, 1)]);

    expect($result->refusal)->toBe('folio_closed_credit_note_required')
        ->and(PosOrder::query()->where('order_type', 'refund')->count())->toBe(0)
        ->and(PosOrderPayment::query()->where('direction', 'out')->count())->toBe(0)
        ->and(PosOrderLine::query()->whereNotNull('refund_of_order_line_id')->count())->toBe(0);
});

it('lets two refunds of the last item race and only one succeed', function () {
    $s = posOrderSetup();
    $order = posSettledCash($s, [$s['mocha']]);
    $line = $order->lines()->sole();

    $first = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)]);
    $second = posRefund($s, $order, [new PosRefundLineInputData($line->id, 1)]);

    expect($first->isRefused())->toBeFalse()->and($second->refusal)->toBe('exceeds_refundable_balance')
        ->and(PosOrderLine::query()->whereNotNull('refund_of_order_line_id')->count())->toBe(1);
});
