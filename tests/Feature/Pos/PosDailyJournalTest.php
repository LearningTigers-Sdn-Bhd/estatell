<?php

use App\Actions\Pos\BeginCloseShift;
use App\Actions\Pos\FinalizeCloseShift;
use App\Actions\Pos\OpenPosShift;
use App\Actions\Pos\RecordCashMovement;
use App\Actions\Pos\RefundPosOrder;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\SubmitBlindCount;
use App\Actions\Pos\VoidPosOrder;
use App\Data\Pos\PosRefundLineInputData;
use App\Data\Pos\PosRefundPayoutInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\BusinessDateStatus;
use App\Enums\PosCashMovementKind;
use App\Enums\PosTenderType;
use App\Features\Pos;
use App\Models\AccountingConnection;
use App\Models\BusinessDate;
use App\Models\PosRegister;
use App\Models\PosVoidReason;
use App\Models\TransactionCode;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\BuildDailyJournal;
use App\Services\Accounting\PosDailyJournalBuilder;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

/**
 * posOrderSetup() with a GL account on every code the journal reads, and an accounting connection.
 *
 * @return array<string, mixed>
 */
function posJournalSetup(bool $meal = false): array
{
    $s = posOrderSetup($meal);
    $accounts = [
        $s['food']->id => '500-000',
        $s['sst']->transaction_code_id => '600-000',
        $s['outlet']->service_charge_transaction_code_id => '510-000',
        $s['outlet']->packaging_transaction_code_id => '520-000',
        $s['outlet']->rounding_transaction_code_id => '530-000',
        $s['outlet']->cash_variance_transaction_code_id => '540-000',
        $s['outlet']->safe_clearing_transaction_code_id => '150-000',
        $s['methods']['cash']->transaction_code_id => '100-000',
        $s['methods']['card']->transaction_code_id => '110-000',
    ];

    foreach ($accounts as $id => $account) {
        TransactionCode::query()->whereKey($id)->update(['gl_account_code' => $account]);
    }

    $s['connection'] = AccountingConnection::factory()->for($s['property'])->create(['guest_ledger_account_code' => '300-000']);
    $s['payoutCode'] = TransactionCode::factory()->create(['property_id' => $s['property']->id, 'gl_account_code' => '620-000']);
    $s['today'] = BusinessDate::query()->where('property_id', $s['property']->id)->where('status', BusinessDateStatus::Open->value)->sole();

    return $s;
}

function posJournalLines(array $s, ?BusinessDate $date = null): array
{
    $journal = app(BuildDailyJournal::class)->forDate($s['property'], $s['connection'], $date ?? $s['today']);

    return $journal === null ? [] : collect($journal->lines)->groupBy('accountCode')->map(fn ($lines): array => ['debit' => number_format($lines->sum(fn ($line): float => (float) $line->debit), 2, '.', ''), 'credit' => number_format($lines->sum(fn ($line): float => (float) $line->credit), 2, '.', '')])->sortKeys()->all();
}

function posJournalBalanced(array $lines): bool
{
    return round(collect($lines)->sum(fn (array $line): float => (float) $line['debit']), 2) === round(collect($lines)->sum(fn (array $line): float => (float) $line['credit']), 2);
}

function posRollTo(array $s, string $date): BusinessDate
{
    BusinessDate::query()->where('property_id', $s['property']->id)->where('status', BusinessDateStatus::Open->value)->update(['status' => BusinessDateStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id]);

    return BusinessDate::factory()->create(['property_id' => $s['property']->id, 'date' => $date, 'status' => BusinessDateStatus::Open]);
}

it('books a cash sale: debit cash clearing, credit revenue, service charge and SST', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00')]);

    $lines = posJournalLines($s);

    expect($lines)->toBe([
        '100-000' => ['debit' => '116.00', 'credit' => '0.00'],
        '500-000' => ['debit' => '0.00', 'credit' => '100.00'],
        '510-000' => ['debit' => '0.00', 'credit' => '10.00'],
        '600-000' => ['debit' => '0.00', 'credit' => '6.00'],
    ])->and(posJournalBalanced($lines))->toBeTrue();
});

it('books a cash sale rounded to 5 sen: the rounding goes to its own code', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')]);

    $lines = posJournalLines($s);

    expect($lines['100-000']['debit'])->toBe('16.25')
        ->and($lines['500-000']['credit'])->toBe('14.00')
        ->and($lines['510-000']['credit'])->toBe('1.40')
        ->and($lines['600-000']['credit'])->toBe('0.84')
        ->and($lines['530-000'])->toBe(['debit' => '0.00', 'credit' => '0.01'])
        ->and(posJournalBalanced($lines))->toBeTrue();
});

it('books a rounding down to a debit on the rounding code', function () {
    $s = posJournalSetup();
    $s['mocha']->forceFill(['price' => '13.99'])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    $total = $order->fresh()->grand_total;
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($total)]);
    $lines = posJournalLines($s);

    expect(posJournalBalanced($lines))->toBeTrue()
        ->and(array_key_exists('530-000', $lines))->toBeTrue();
});

it('books card on its own clearing account and a split on both', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), posCard('60.00')]);

    $lines = posJournalLines($s);

    expect($lines['100-000']['debit'])->toBe('100.00')
        ->and($lines['110-000']['debit'])->toBe('60.00')
        ->and($lines['500-000']['credit'])->toBe('137.93')
        ->and($lines['510-000']['credit'])->toBe('13.79')
        ->and($lines['600-000']['credit'])->toBe('8.28')
        ->and(posJournalBalanced($lines))->toBeTrue();
});

it('makes a journal for a cafe day with no room activity and needs no guest ledger account', function () {
    $s = posJournalSetup();
    $s['connection']->forceFill(['guest_ledger_account_code' => null])->save();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00')]);

    $journal = app(BuildDailyJournal::class)->forDate($s['property'], $s['connection']->fresh(), $s['today']);

    expect($journal)->not->toBeNull()
        ->and(collect($journal->lines)->pluck('accountCode')->all())->not->toContain('300-000');
});

it('books only the walk-in share of a mixed bill and leaves the room share to the folio', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), new PosTenderInputData(PosTenderType::RoomCharge, '60.00', reservationUnitId: $s['unit']->id)], guestSigned: true);

    $builder = app(PosDailyJournalBuilder::class);
    $pos = collect($builder->rows($s['property'], $s['today']))->mapWithKeys(fn (array $row): array => [$row['account'] => $row['net']->__toString()])->sortKeys()->all();

    expect($pos)->toBe(['100-000' => '-100.00', '500-000' => '86.21', '510-000' => '8.62', '600-000' => '5.17']);

    $lines = posJournalLines($s);
    expect($lines['500-000']['credit'])->toBe('137.93')
        ->and($lines['510-000']['credit'])->toBe('13.79')
        ->and($lines['600-000']['credit'])->toBe('8.28')
        ->and($lines['300-000']['debit'])->toBe('60.00')
        ->and(posJournalBalanced($lines))->toBeTrue();
});

it('has no POS journal for an order paid wholly on the room', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: $s['unit']->id)], guestSigned: true);

    expect(app(PosDailyJournalBuilder::class)->rows($s['property'], $s['today']))->toBe([]);
});

it('leaves a voided order out', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(VoidPosOrder::class)($s['manager'], $s['property'], $order, PosVoidReason::factory()->create(['property_id' => $s['property']->id]));

    expect(app(BuildDailyJournal::class)->forDate($s['property'], $s['connection'], $s['today']))->toBeNull();
});

it('books a payout, a safe drop and a float in against cash', function () {
    $s = posJournalSetup();
    $record = fn (PosCashMovementKind $kind, string $amount, string $key, ?int $code = null) => app(RecordCashMovement::class)($s['manager'], $s['property'], $s['shift'], $kind, $amount, 'Reason', $key, $code ?? $s['payoutCode']->id);

    $record(PosCashMovementKind::Payout, '12.50', 'p1');
    $record(PosCashMovementKind::SafeDrop, '100.00', 's1', $s['outlet']->safe_clearing_transaction_code_id);
    $record(PosCashMovementKind::FloatIn, '30.00', 'f1', $s['outlet']->safe_clearing_transaction_code_id);
    app(RecordCashMovement::class)($s['cashier'] ?? $s['manager'], $s['property'], $s['shift'], PosCashMovementKind::NoSaleOpen, '0.00', 'Change', 'n1', null, $s['manager']);

    $lines = posJournalLines($s);

    // Safe drop (Dr 100) and float in (Cr 30) share one account, so it nets to a RM 70.00 debit; cash nets to a RM 82.50 credit.
    expect($lines['620-000'])->toBe(['debit' => '12.50', 'credit' => '0.00'])
        ->and($lines['150-000'])->toBe(['debit' => '70.00', 'credit' => '0.00'])
        ->and($lines['100-000'])->toBe(['debit' => '0.00', 'credit' => '82.50'])
        ->and(posJournalBalanced($lines))->toBeTrue();
});

it('books a cash shortage and an overage against the variance code', function () {
    $s = posJournalSetup();
    app(BeginCloseShift::class)($s['manager'], $s['property'], $s['shift']);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $s['shift'], '198.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $s['shift']);

    $short = posJournalLines($s);
    expect($short['540-000'])->toBe(['debit' => '2.00', 'credit' => '0.00'])
        ->and($short['100-000'])->toBe(['debit' => '0.00', 'credit' => '2.00'])
        ->and(posJournalBalanced($short))->toBeTrue();

    $register = PosRegister::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id]);
    $second = app(OpenPosShift::class)($s['manager'], $s['property'], $register, '100.00')->model;
    app(BeginCloseShift::class)($s['manager'], $s['property'], $second);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $second, '103.50');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $second);

    $both = posJournalLines($s);
    // The RM 2.00 shortage and the RM 3.50 overage net on the one variance account: a RM 1.50 credit, with cash a RM 1.50 debit.
    expect($both['540-000'])->toBe(['debit' => '0.00', 'credit' => '1.50'])
        ->and($both['100-000'])->toBe(['debit' => '1.50', 'credit' => '0.00'])
        ->and(posJournalBalanced($both))->toBeTrue();
});

it('passes the cross-midnight acceptance: mixed bill, partial cash refund after 2 AM, RM 2 short', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $settled = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('100.00'), new PosTenderInputData(PosTenderType::RoomCharge, '60.00', reservationUnitId: $s['unit']->id)], guestSigned: true)->model;
    $day1 = $s['today'];

    $day1Lines = posJournalLines($s, $day1);
    expect($day1Lines['100-000']['debit'])->toBe('100.00')
        ->and($day1Lines['300-000']['debit'])->toBe('60.00')
        ->and($day1Lines['500-000']['credit'])->toBe('137.93')
        ->and($day1Lines['510-000']['credit'])->toBe('13.79')
        ->and($day1Lines['600-000']['credit'])->toBe('8.28')
        ->and(posJournalBalanced($day1Lines))->toBeTrue();

    $day2 = posRollTo($s, '2026-10-15');
    $cash = $settled->payments()->where('tender_type', 'cash')->sole();
    $pasta = $settled->lines()->where('item_name', 'Pasta')->sole();
    $refund = app(RefundPosOrder::class)($s['manager'], $s['property'], $settled, [new PosRefundLineInputData($pasta->id, 1)], 'Guest returned the pasta', (string) Str::uuid(), $s['manager'], null, [new PosRefundPayoutInputData($cash->id, '44.00')])->model;

    app(BeginCloseShift::class)($s['manager'], $s['property'], $s['shift']);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $s['shift'], '254.00');
    $closed = app(FinalizeCloseShift::class)($s['manager'], $s['property'], $s['shift'])->model;

    expect($refund->business_date->toDateString())->toBe('2026-10-15')
        ->and($closed->expected_cash)->toBe('256.00')
        ->and($closed->cash_variance)->toBe('-2.00')
        ->and($closed->closed_business_date->toDateString())->toBe('2026-10-15');

    $day2Lines = posJournalLines($s, $day2);

    expect($day2Lines)->toBe([
        '100-000' => ['debit' => '0.00', 'credit' => '46.00'],
        '500-000' => ['debit' => '37.93', 'credit' => '0.00'],
        '510-000' => ['debit' => '3.79', 'credit' => '0.00'],
        '540-000' => ['debit' => '2.00', 'credit' => '0.00'],
        '600-000' => ['debit' => '2.28', 'credit' => '0.00'],
    ])->and(posJournalBalanced($day2Lines))->toBeTrue()
        ->and(array_key_exists('300-000', $day2Lines))->toBeFalse();

    // Book cash moved by the two days equals what is physically left: +100.00 - 46.00 = +54.00 = 254.00 - 200.00.
    expect(round((float) $day1Lines['100-000']['debit'] - (float) $day2Lines['100-000']['credit'], 2))->toBe(54.0);
});

it('inverts the rounding sign on a rounded cash refund', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['mocha']);
    $settled = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('16.24')])->model;
    app(RefundPosOrder::class)($s['manager'], $s['property'], $settled, [new PosRefundLineInputData($settled->lines()->sole()->id, 1)], 'Returned', (string) Str::uuid(), $s['manager']);

    $lines = posJournalLines($s);

    expect($lines)->toBe([]);
});

it('still exports POS days after Native POS was turned off', function () {
    $s = posJournalSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00')]);
    Feature::for($s['property'])->deactivate(Pos::class);

    expect(posJournalLines($s)['100-000']['debit'])->toBe('116.00');
});

it('refuses to build when a POS code has no account', function () {
    $s = posJournalSetup();
    TransactionCode::query()->whereKey($s['methods']['cash']->transaction_code_id)->update(['gl_account_code' => null]);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00')]);

    app(BuildDailyJournal::class)->forDate($s['property'], $s['connection'], $s['today']);
})->throws(AccountingException::class, 'No accounting account is set for');

it('keeps other properties out of the journal', function () {
    $s = posJournalSetup();
    $other = posJournalSetup();
    $order = posNewOrder($other);
    posAddLine($other, $order, $other['steak']);
    app(SettlePosOrder::class)($other['manager'], $other['property'], $order, [posCash('116.00')]);

    expect(app(BuildDailyJournal::class)->forDate($s['property'], $s['connection'], $s['today']))->toBeNull();
});
