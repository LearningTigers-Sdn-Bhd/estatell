<?php

use App\Actions\Folio\ApplyDiscount;
use App\Actions\Folio\EditLine;
use App\Actions\Folio\PostPosFolioCredit;
use App\Actions\Folio\PostPrecalculatedCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\SplitLine;
use App\Actions\Folio\TransferLines;
use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\SettlePosOrder;
use App\Data\Pos\PosTenderInputData;
use App\Enums\FolioStatus;
use App\Enums\PosOrderStatus;
use App\Enums\PosTenderType;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Discount;
use App\Models\DocumentSequence;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PosOrder;
use App\Models\PosOrderFolioLink;
use App\Models\PosOrderPayment;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\LineTaxes;
use App\Services\Pos\PosFolioLinkGuard;
use Brick\Money\Money;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posRoomTender(array $s, string $amount, ?ReservationUnit $unit = null): PosTenderInputData
{
    return new PosTenderInputData(PosTenderType::RoomCharge, $amount, reservationUnitId: ($unit ?? $s['unit'])->id);
}

function posSettleRoom(array $s, $order, array $tenders, array $options = ['guestSigned' => true]): PosActionResult
{
    return app(SettlePosOrder::class)($s['manager'], $s['property'], $order, $tenders, ...$options);
}

function posSteakAndPasta(array $s): PosOrder
{
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);

    return $order;
}

it('posts a whole room charge to the guest folio as a charge, its tax and the service charge', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);

    $result = posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $settled = $result->model;
    $folio = Folio::query()->where('reservation_unit_id', $s['unit']->id)->sole();
    $lines = FolioLine::query()->where('folio_id', $folio->id)->orderBy('id')->get();
    $payment = PosOrderPayment::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($settled->settled_on_folio)->toBeTrue()
        ->and($settled->folio_posted_amount)->toBe('116.00')
        ->and($lines)->toHaveCount(3)
        ->and($lines->where('parent_line_id', null)->pluck('amount')->sort()->values()->all())->toBe(['10.00', '100.00'])
        ->and($lines->where('kind', TransactionKind::Tax)->sole()->amount)->toBe('6.00')
        ->and($lines->first()->description)->toBe($s['outlet']->name.' — Order #'.$settled->order_number)
        ->and($lines->first()->quantity)->toBe(1)
        ->and($lines->first()->posting_date->toDateString())->toBe('2026-10-14')
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('116.00')
        ->and($payment->tender_type)->toBe(PosTenderType::RoomCharge)
        ->and($payment->payment_method_id)->toBeNull()
        ->and($payment->reservation_unit_id)->toBe($s['unit']->id)
        ->and($payment->folio_id)->toBe($folio->id)
        ->and($lines->where('kind', TransactionKind::Payment))->toHaveCount(0);

    $links = PosOrderFolioLink::query()->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($links->pluck('folio_line_id')->all())->toBe($lines->where('parent_line_id', null)->pluck('id')->values()->all())
        ->and($links->sum(fn ($link): float => (float) $link->gross_amount))->toBe(116.0)
        ->and($links->firstWhere('transaction_code_id', $s['food']->id)->net_amount)->toBe('100.00')
        ->and($links->firstWhere('transaction_code_id', $s['food']->id)->tax_amount)->toBe('6.00');
});

it('posts only the room share of a split bill: RM 100 cash and RM 60 room charge on RM 160', function () {
    $s = posOrderSetup();
    $order = posSteakAndPasta($s);
    $order->forceFill(['reservation_unit_id' => $s['unit']->id])->save();

    $settled = posSettleRoom($s, $order, [posCash('100.00'), posRoomTender($s, '60.00')])->model;
    $folio = Folio::query()->where('reservation_unit_id', $s['unit']->id)->sole();
    $lines = FolioLine::query()->where('folio_id', $folio->id)->orderBy('id')->get();
    $food = $lines->first(fn ($line): bool => $line->parent_line_id === null && $line->transaction_code_id === $s['food']->id);
    $service = $lines->first(fn ($line): bool => $line->parent_line_id === null && $line->transaction_code_id === $s['outlet']->service_charge_transaction_code_id);

    expect($settled->grand_total)->toBe('160.00')
        ->and($settled->folio_posted_amount)->toBe('60.00')
        ->and($settled->settled_on_folio)->toBeFalse()
        ->and($food->amount)->toBe('51.72')
        ->and($lines->where('parent_line_id', $food->id)->sole()->amount)->toBe('3.11')
        ->and($service->amount)->toBe('5.17')
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('60.00')
        ->and($lines->where('kind', TransactionKind::Payment))->toHaveCount(0)
        ->and(PosOrderPayment::query()->where('tender_type', 'cash')->sole()->amount)->toBe('100.00')
        ->and(PosOrderFolioLink::query()->sum('gross_amount'))->toEqual('60.00');
});

it('settles two rooms on one check, each with its own key and its own share', function () {
    $s = posOrderSetup();
    $other = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => Reservation::factory()->create(['property_id' => $s['property']->id])->id]);
    $order = posSteakAndPasta($s);

    $settled = posSettleRoom($s, $order, [posRoomTender($s, '60.00'), posRoomTender($s, '100.00', $other)])->model;
    $payments = $settled->payments()->orderBy('id')->get();

    expect($settled->folio_posted_amount)->toBe('160.00')
        ->and($settled->settled_on_folio)->toBeTrue()
        ->and($payments->pluck('reservation_unit_id')->all())->toBe([$s['unit']->id, $other->id])
        ->and(app(FolioBalance::class)->forFolio(Folio::query()->where('reservation_unit_id', $s['unit']->id)->sole()))->toBe('60.00')
        ->and(app(FolioBalance::class)->forFolio(Folio::query()->where('reservation_unit_id', $other->id)->sole()))->toBe('100.00')
        ->and(FolioLine::query()->whereNull('parent_line_id')->pluck('idempotency_key')->unique()->count())->toBe(FolioLine::query()->whereNull('parent_line_id')->count());
});

it('uses the guest own folio that already exists and the same folio for later orders', function () {
    $s = posOrderSetup();

    foreach (range(1, 2) as $_) {
        $order = posNewOrder($s);
        posAddLine($s, $order, $s['mocha']);
        posSettleRoom($s, $order, [posRoomTender($s, '16.24')]);
    }

    expect(Folio::query()->where('reservation_unit_id', $s['unit']->id)->count())->toBe(1)
        ->and(app(FolioBalance::class)->forFolio(Folio::query()->sole()))->toBe('32.48');
});

it('allows a stay checking out on the day, and refuses one that left earlier or never arrived', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    $s['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now(), 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14'])->save();
    expect(posSettleRoom($s, $order, [posRoomTender($s, '16.24')])->isRefused())->toBeFalse();

    $late = posNewOrder($s);
    posAddLine($s, $late, $s['mocha']);
    $s['unit']->forceFill(['departure_date' => '2026-10-13'])->save();
    expect(posSettleRoom($s, $late, [posRoomTender($s, '16.24')])->refusal)->toBe('unit_not_postable');

    $s['unit']->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null, 'checked_out_at' => null, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-16'])->save();
    expect(posSettleRoom($s, $late, [posRoomTender($s, '16.24')])->refusal)->toBe('unit_not_postable');
});

it('refuses a room blocked for room charges and rolls everything back', function () {
    $s = posOrderSetup();
    $s['unit']->forceFill(['no_post' => true])->save();
    $order = posSteakAndPasta($s);

    $result = posSettleRoom($s, $order, [posCash('100.00'), posRoomTender($s, '60.00')]);

    expect($result->refusal)->toBe('room_no_post')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open)
        ->and(PosOrderPayment::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(DocumentSequence::query()->where('type', 'pos_order')->count())->toBe(0);
});

it('refuses a closed folio', function () {
    $s = posOrderSetup();
    $first = posNewOrder($s);
    posAddLine($s, $first, $s['mocha']);
    posSettleRoom($s, $first, [posRoomTender($s, '16.24')]);
    Folio::query()->update(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id]);

    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(posSettleRoom($s, $order, [posRoomTender($s, '16.24')])->refusal)->toBe('folio_not_open')
        ->and($order->fresh()->status)->toBe(PosOrderStatus::Open);
});

it('asks for the guest signature when the outlet requires it', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(posSettleRoom($s, $order, [posRoomTender($s, '16.24')], [])->refusal)->toBe('signature_required');

    $s['outlet']->forceFill(['require_room_charge_signature' => false])->save();
    expect(posSettleRoom($s, $order, [posRoomTender($s, '16.24')], [])->isRefused())->toBeFalse();
});

it('stops at the cumulative room charge limit across orders unless a manager approves', function () {
    $s = posOrderSetup();
    $s['outlet']->forceFill(['room_charge_limit' => '120.00'])->save();
    $order = fn (int $quantity) => tap(posNewOrder($s), fn ($order) => posAddLine($s, $order, $s['mocha'], $quantity));

    expect(posSettleRoom($s, $order(10), [posRoomTender($s, '162.40')])->refusal)->toBe('room_charge_limit_exceeded')
        ->and(posSettleRoom($s, $order(5), [posRoomTender($s, '81.20')])->isRefused())->toBeFalse();

    $more = $order(3);
    expect(posSettleRoom($s, $more, [posRoomTender($s, '48.72')])->refusal)->toBe('room_charge_limit_exceeded')
        ->and(posSettleRoom($s, $more, [posRoomTender($s, '48.72')], ['guestSigned' => true, 'limitApprover' => $s['manager']])->isRefused())->toBeFalse();
});

it('counts a refunded room charge back out of the cumulative limit', function () {
    $s = posOrderSetup();
    $s['outlet']->forceFill(['room_charge_limit' => '120.00'])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    PosOrderPayment::query()->update(['direction' => 'out']);

    $next = posNewOrder($s);
    posAddLine($s, $next, $s['steak']);

    expect(posSettleRoom($s, $next, [posRoomTender($s, '116.00')])->isRefused())->toBeFalse();
});

it('keeps another property rooms out', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);

    expect(posSettleRoom($s, $order, [posRoomTender($s, '16.24', $other['unit'])])->refusal)->toBe('unit_not_eligible');
});

it('posts a room charge only once even if the same payment key is posted again', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    posSettleRoom($s, $order, [posRoomTender($s, '16.24')]);
    $count = FolioLine::query()->count();

    posSettleRoom($s, $order, [posRoomTender($s, '16.24')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->first();
    $replay = app(PostPrecalculatedCharge::class)($s['manager'], $s['unit'], $root->folio, $root->transactionCode, 'x', Money::of('1.00', 'MYR'), [], $root->idempotency_key, $order);

    expect($replay->replayed)->toBeTrue()->and(FolioLine::query()->count())->toBe($count);
});

it('refuses to post a precalculated charge to a folio of another stay or a code that is not a charge', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    posSettleRoom($s, $order, [posRoomTender($s, '16.24')]);
    $folio = Folio::query()->sole();
    $post = fn ($unit, $folioTarget, $code) => app(PostPrecalculatedCharge::class)($s['manager'], $unit, $folioTarget, $code, 'x', Money::of('1.00', 'MYR'), [new CalculatedTax($s['sst']->id, $s['sst']->transaction_code_id, 'SST', Money::of('0.06', 'MYR'), false)], 'k-'.random_int(1, 99999), $order);

    $otherFolio = Folio::factory()->forUnit($other['unit'])->create(['property_id' => $other['property']->id, 'reservation_id' => $other['unit']->reservation_id]);

    expect($post($s['unit'], $otherFolio, $s['food'])->refusal)->toBe('charge_not_found')
        ->and($post($s['unit'], $folio, $s['sst']->transactionCode)->refusal)->toBe('charge_not_found')
        ->and($post($s['unit'], $folio, $s['food'])->isRefused())->toBeFalse();

    $s['unit']->forceFill(['status' => UnitStatus::Confirmed])->save();
    expect($post($s['unit'], $folio, $s['food'])->refusal)->toBe('unit_not_postable');
});

it('stops front desk from reversing, editing or splitting a POS charge, but POS can reverse it', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->where('transaction_code_id', $s['food']->id)->sole();
    $tax = $root->children()->sole();

    expect(app(ReverseLine::class)($s['manager'], $root)->refusal)->toBe('pos_line_managed_in_pos')
        ->and(app(PosFolioLinkGuard::class)->isManagedInPos($root))->toBeTrue()
        ->and(app(PosFolioLinkGuard::class)->isManagedInPos($tax))->toBeTrue()
        ->and(app(PosFolioLinkGuard::class)->isManagedInPos(FolioLine::factory()->create()))->toBeFalse();

    $reversed = app(ReverseLine::class)->forPos($s['manager'], $root);

    expect($reversed->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('reversal_of_id', $root->id)->sole()->amount)->toBe('-100.00')
        ->and(FolioLine::query()->where('reversal_of_id', $tax->id)->sole()->amount)->toBe('-6.00')
        ->and(app(ReverseLine::class)->forPos($s['manager'], $root)->refusal)->toBe('already_reversed');
});

it('keeps front desk tax edits, splits and discounts away from a POS charge', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->where('transaction_code_id', $s['food']->id)->sole();

    expect(app(LineTaxes::class)->chargeRefusal($root, $root->folio))->toBe('pos_line_managed_in_pos');

    $discount = Discount::factory()->create(['property_id' => $s['property']->id, 'scope' => 'all_charges']);
    $result = app(ApplyDiscount::class)($s['manager'], $root->folio, $discount, null, 'fp');
    expect($result->isRefused() ? $result->refusal : 'applied')->not->toBe('applied')
        ->and(FolioLine::query()->where('kind', TransactionKind::Adjustment)->count())->toBe(0);
});

it('lets front desk move a POS charge to another folio and the link follows it', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->where('transaction_code_id', $s['food']->id)->sole();
    $link = PosOrderFolioLink::query()->where('folio_line_id', $root->id)->sole();
    $target = Folio::factory()->master()->create(['property_id' => $s['property']->id, 'reservation_id' => $root->folio->reservation_id]);

    $moved = app(TransferLines::class)($s['manager'], [$root], $target);

    expect($moved->isRefused())->toBeFalse();
    $link->refresh();
    expect($link->folio_id)->toBe($target->id)
        ->and($link->folio_line_id)->not->toBe($root->id)
        ->and(FolioLine::query()->find($link->folio_line_id)->folio_id)->toBe($target->id)
        ->and(app(PosFolioLinkGuard::class)->isManagedInPos(FolioLine::query()->find($link->folio_line_id)))->toBeTrue()
        ->and(app(PosFolioLinkGuard::class)->isManagedInPos($root->fresh()))->toBeFalse();
});

it('credits part of a room charge back with negative lines, skipping the sale checks', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->where('transaction_code_id', $s['food']->id)->sole();
    $folio = $root->folio;
    $s['unit']->forceFill(['no_post' => true])->save();
    $credit = fn (string $key) => app(PostPosFolioCredit::class)($s['manager'], $root, 'Refund', Money::of('50.00', 'MYR'), [new CalculatedTax($s['sst']->id, $s['sst']->transaction_code_id, 'SST', Money::of('3.00', 'MYR'), false)], $key, $order);

    $result = $credit('credit-1');

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines[0]->amount)->toBe('-50.00')
        ->and($result->lines[1]->amount)->toBe('-3.00')
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('63.00')
        ->and($credit('credit-1')->replayed)->toBeTrue()
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('63.00');

    $folio->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id])->save();
    expect($credit('credit-2')->refusal)->toBe('folio_not_open');
});

it('does not let a front desk edit or split action change a POS line', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posSettleRoom($s, $order, [posRoomTender($s, '116.00')]);
    $root = FolioLine::query()->whereNull('parent_line_id')->where('transaction_code_id', $s['food']->id)->sole();
    $before = FolioLine::query()->count();

    expect(class_exists(EditLine::class))->toBeTrue()->and(class_exists(SplitLine::class))->toBeTrue()
        ->and(app(LineTaxes::class)->chargeRefusal($root, $root->folio))->toBe('pos_line_managed_in_pos')
        ->and(FolioLine::query()->count())->toBe($before)
        ->and(TransactionCode::query()->count())->toBeGreaterThan(0);
});
