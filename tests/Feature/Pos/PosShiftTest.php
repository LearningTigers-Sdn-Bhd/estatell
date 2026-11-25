<?php

use App\Actions\Platform\SetPropertyFeature;
use App\Actions\Pos\BeginCloseShift;
use App\Actions\Pos\CancelCloseShift;
use App\Actions\Pos\FinalizeCloseShift;
use App\Actions\Pos\OpenPosShift;
use App\Actions\Pos\RecordCashMovement;
use App\Actions\Pos\SubmitBlindCount;
use App\Enums\BusinessDateStatus;
use App\Enums\PlatformFeature;
use App\Enums\PosCashMovementKind;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Features\Pos;
use App\Models\BusinessDate;
use App\Models\DocumentSequence;
use App\Models\PosCashMovement;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Pos\PosShiftCashTotals;
use Illuminate\Database\QueryException;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosShiftSetup.php';

it('opens a shift on the open business date with a numbered reference', function () {
    $s = posShiftSetup();

    $result = app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '200.00');
    $shift = $result->model;

    expect($result->isRefused())->toBeFalse()
        ->and($shift->status)->toBe(PosShiftStatus::Open)
        ->and($shift->opening_cash)->toBe('200.00')
        ->and($shift->opened_business_date->toDateString())->toBe('2026-10-14')
        ->and($shift->closed_business_date)->toBeNull()
        ->and($shift->opened_by_user_id)->toBe($s['manager']->id)
        ->and($shift->shift_number)->toEndWith('1100001')
        ->and(Activity::query()->where('event', 'pos.shift_opened')->count())->toBe(1);

    $other = PosRegister::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id]);
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $other, '0')->model->shift_number)->toEndWith('1100002')
        ->and(DocumentSequence::query()->where('property_id', $s['property']->id)->where('type', 'pos_shift')->sole()->last_number)->toBe(2);
});

it('starts with no cash the first time and with the last count after that', function () {
    $s = posShiftSetup();

    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'])->model->opening_cash)->toBe('0.00');
});

it('refuses a second open shift on the same register, and a register of another hotel', function () {
    $s = posShiftSetup();
    $other = posShiftSetup();
    posOpenShift($s);

    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '10.00')->refusal)->toBe('shift_already_open')
        ->and(app(OpenPosShift::class)($s['manager'], $s['property'], $other['register'], '10.00')->refusal)->toBe('register_invalid')
        ->and(PosShift::query()->count())->toBe(1);
});

it('refuses a register that is off, an outlet that is not native and Native POS being off', function () {
    $s = posShiftSetup();
    $s['register']->forceFill(['active' => false])->save();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('register_invalid');

    $s['register']->forceFill(['active' => true])->save();
    $s['outlet']->forceFill(['pos_enabled' => false])->save();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('register_invalid');

    $s['outlet']->forceFill(['pos_enabled' => true])->save();
    Feature::for($s['property'])->deactivate(Pos::class);
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('pos_disabled');
});

it('refuses to open without the GL codes the journal needs', function () {
    $s = posShiftSetup(['cash_variance_transaction_code_id' => null]);
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('cash_variance_code_required');

    $s['outlet']->forceFill(['cash_variance_transaction_code_id' => $s['counter']->id, 'rounding_transaction_code_id' => null])->save();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('rounding_code_required');

    $s['outlet']->forceFill(['rounding_mode' => 'none'])->save();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->isRefused())->toBeFalse();
});

it('refuses an opening float that is not an amount', function (string $cash) {
    $s = posShiftSetup();

    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], $cash)->refusal)->toBe('opening_cash_invalid');
})->with(['-5.00', 'abc', '10.123', '']);

it('waits out the Night Audit and needs a business date', function () {
    $s = posShiftSetup();
    $date = BusinessDate::query()->where('property_id', $s['property']->id)->sole();

    $date->forceFill(['status' => BusinessDateStatus::Auditing])->save();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('business_date_auditing');

    $date->delete();
    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '0')->refusal)->toBe('no_business_date');
});

it('books a float that differs from the last count as a safe drop on the last shift', function () {
    $s = posShiftSetup();
    $first = posOpenShift($s, '200.00');
    app(BeginCloseShift::class)($s['manager'], $s['property'], $first);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $first, '254.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $first);

    $second = app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '200.00')->model;
    $drop = PosCashMovement::query()->sole();

    expect($second->opening_cash)->toBe('200.00')
        ->and($drop->kind)->toBe(PosCashMovementKind::SafeDrop)
        ->and($drop->amount)->toBe('54.00')
        ->and($drop->pos_shift_id)->toBe($first->id)
        ->and($drop->counter_transaction_code_id)->toBe($s['outlet']->safe_clearing_transaction_code_id)
        ->and($drop->idempotency_key)->toBe('handover:'.$second->id)
        ->and($drop->business_date->toDateString())->toBe('2026-10-14');
});

it('books a bigger float as a float in, and nothing when the float matches', function () {
    $s = posShiftSetup();
    $first = posOpenShift($s, '200.00');
    app(BeginCloseShift::class)($s['manager'], $s['property'], $first);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $first, '254.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $first);

    $second = app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '260.00')->model;
    expect(PosCashMovement::query()->sole()->kind)->toBe(PosCashMovementKind::FloatIn)->and(PosCashMovement::query()->sole()->amount)->toBe('6.00');

    app(BeginCloseShift::class)($s['manager'], $s['property'], $second);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $second, '260.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $second);
    app(OpenPosShift::class)($s['manager'], $s['property'], $s['register']);

    expect(PosCashMovement::query()->count())->toBe(1);
});

it('refuses a different float when the outlet has no safe clearing code', function () {
    $s = posShiftSetup(['safe_clearing_transaction_code_id' => null]);
    $first = posOpenShift($s, '200.00');
    app(BeginCloseShift::class)($s['manager'], $s['property'], $first);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $first, '254.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $first);

    expect(app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], '200.00')->refusal)->toBe('safe_code_required')
        ->and(PosShift::query()->count())->toBe(1);
});

it('records float in, safe drop and payout and keeps the shift paid in and paid out', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    $record = fn (PosCashMovementKind $kind, string $amount, string $key) => app(RecordCashMovement::class)($s['manager'], $s['property'], $shift, $kind, $amount, 'Reason', $key, $s['counter']->id);

    expect($record(PosCashMovementKind::FloatIn, '50.00', 'a')->isRefused())->toBeFalse();
    $record(PosCashMovementKind::SafeDrop, '100.00', 'b');
    $record(PosCashMovementKind::Payout, '12.50', 'c');

    $shift->refresh();
    expect($shift->paid_in)->toBe('50.00')->and($shift->paid_out)->toBe('112.50')
        ->and(PosCashMovement::query()->count())->toBe(3)
        ->and(PosCashMovement::query()->first()->business_date->toDateString())->toBe('2026-10-14');
});

it('returns the same movement for the same idempotency key', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    $record = fn () => app(RecordCashMovement::class)($s['manager'], $s['property'], $shift, PosCashMovementKind::Payout, '5.00', 'Milk', 'key-1', $s['counter']->id);

    $first = $record();
    $second = $record();

    expect($second->replayed)->toBeTrue()->and($second->model->id)->toBe($first->model->id)
        ->and(PosCashMovement::query()->count())->toBe(1)
        ->and($shift->fresh()->paid_out)->toBe('5.00');
});

it('refuses movements with no counter code, a bad amount or no reason', function () {
    $s = posShiftSetup();
    $other = posShiftSetup();
    $shift = posOpenShift($s);
    $record = fn (array $args) => app(RecordCashMovement::class)(...array_replace([$s['manager'], $s['property'], $shift, PosCashMovementKind::Payout, '5.00', 'Milk', 'k'.random_int(1, 99999), $s['counter']->id], $args));

    expect($record([7 => null])->refusal)->toBe('counter_code_required')
        ->and($record([7 => $other['counter']->id])->refusal)->toBe('counter_code_required')
        ->and($record([4 => '0.00'])->refusal)->toBe('amount_invalid')
        ->and($record([4 => '-1.00'])->refusal)->toBe('amount_invalid')
        ->and($record([4 => 'abc'])->refusal)->toBe('amount_invalid')
        ->and($record([5 => '  '])->refusal)->toBe('reason_required')
        ->and(PosCashMovement::query()->count())->toBe(0);
});

it('records a no-sale drawer opening only with an approver, and it moves no cash', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    $record = fn (?User $approver) => app(RecordCashMovement::class)($s['cashier'], $s['property'], $shift, PosCashMovementKind::NoSaleOpen, '0.00', 'Change for a guest', 'ns-'.random_int(1, 99999), null, $approver);

    expect($record(null)->refusal)->toBe('approval_required');

    $movement = $record($s['manager'])->model;
    expect($movement->amount)->toBe('0.00')->and($movement->counter_transaction_code_id)->toBeNull()
        ->and($movement->approved_by_user_id)->toBe($s['manager']->id)
        ->and($movement->user_id)->toBe($s['cashier']->id)
        ->and($shift->fresh()->paid_out)->toBe('0.00');
});

it('refuses cash movements once closing has begun and for a shift of another hotel', function () {
    $s = posShiftSetup();
    $other = posShiftSetup();
    $shift = posOpenShift($s);
    $otherShift = posOpenShift($other);
    $record = fn (PosShift $target) => app(RecordCashMovement::class)($s['manager'], $s['property'], $target, PosCashMovementKind::Payout, '5.00', 'Milk', 'k'.random_int(1, 99999), $s['counter']->id);

    expect($record($otherShift)->refusal)->toBe('shift_invalid');

    app(BeginCloseShift::class)($s['manager'], $s['property'], $shift);
    expect($record($shift)->refusal)->toBe('shift_not_open');
});

it('works out the expected cash from payments, refunds, rounding and movements', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s, '200.00');
    posCashPayment($shift, '100.00', ['rounding_adjustment' => '0.02']);
    posCashPayment($shift, '50.00');
    posCashPayment($shift, '44.00', ['direction' => PosPaymentDirection::Out, 'rounding_adjustment' => '-0.02']);
    posCashPayment($shift, '70.00', ['tender_type' => PosTenderType::Card]);
    posCashPayment($shift, '33.00', ['status' => PosPaymentStatus::Replaced]);
    posCashPayment($shift, '33.00', ['status' => PosPaymentStatus::Voided]);
    posCashPayment(posOpenShift(posShiftSetup()), '999.00');
    app(RecordCashMovement::class)($s['manager'], $s['property'], $shift, PosCashMovementKind::FloatIn, '20.00', 'Float', 'f1', $s['counter']->id);
    app(RecordCashMovement::class)($s['manager'], $s['property'], $shift, PosCashMovementKind::Payout, '5.00', 'Milk', 'p1', $s['counter']->id);

    expect(app(PosShiftCashTotals::class)->for($shift->fresh()))->toBe([
        'cash_payments' => '150.02',
        'cash_refunds' => '43.98',
        'paid_in' => '20.00',
        'paid_out' => '5.00',
        'expected_cash' => '321.04',
    ]);
});

it('starts closing: refuses while orders are open, then freezes trading and stores the totals', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    posCashPayment($shift, '100.00');
    PosOrder::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'pos_shift_id' => $shift->id]);

    expect(app(BeginCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('open_orders_exist')
        ->and($shift->fresh()->status)->toBe(PosShiftStatus::Open);

    PosOrder::query()->where('pos_shift_id', $shift->id)->update(['status' => 'settled']);

    $result = app(BeginCloseShift::class)($s['manager'], $s['property'], $shift);

    expect($result->model->status)->toBe(PosShiftStatus::ClosingControl)
        ->and($result->model->cash_payments)->toBe('100.00')
        ->and($result->model->expected_cash)->toBe('300.00')
        ->and(app(BeginCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('shift_not_open');
});

it('lets staff cancel closing and trade again, clearing the count', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    app(BeginCloseShift::class)($s['manager'], $s['property'], $shift);
    app(SubmitBlindCount::class)($s['manager'], $s['property'], $shift, '150.00');

    $result = app(CancelCloseShift::class)($s['manager'], $s['property'], $shift);

    expect($result->model->status)->toBe(PosShiftStatus::Open)
        ->and($result->model->counted_cash)->toBeNull()
        ->and($result->model->recount_count)->toBe(0)
        ->and(app(CancelCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('shift_not_closing')
        ->and(app(RecordCashMovement::class)($s['manager'], $s['property'], $shift, PosCashMovementKind::Payout, '5.00', 'Milk', 'z', $s['counter']->id)->isRefused())->toBeFalse();
});

it('takes a blind count and keeps the cashier from recounting without a manager', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s, '200.00');
    posCashPayment($shift, '100.00');
    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);

    $count = fn (string $amount, ?User $approver = null) => app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, $amount, $approver);

    $first = $count('295.00');
    expect($first->model->counted_cash)->toBe('295.00')->and($first->model->cash_variance)->toBe('-5.00')->and($first->model->recount_count)->toBe(1);

    expect($count('300.00')->refusal)->toBe('recount_requires_approval')
        ->and($shift->fresh()->counted_cash)->toBe('295.00');

    $second = $count('300.00', $s['manager']);
    expect($second->model->counted_cash)->toBe('300.00')->and($second->model->cash_variance)->toBe('0.00')->and($second->model->recount_count)->toBe(2);

    expect($count('310.00', $s['manager'])->refusal)->toBe('recount_limit_reached')
        ->and(Activity::query()->whereIn('event', ['pos.shift_counted', 'pos.shift_recounted'])->count())->toBe(2);
});

it('needs no recount when the first count matches', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s, '200.00');
    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);
    app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '200.00');

    expect(app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '201.00', $s['manager'])->refusal)->toBe('recount_not_needed');
});

it('counts only while the shift is closing and only real amounts', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);

    expect(app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '10.00')->refusal)->toBe('shift_not_closing');

    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);
    expect(app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '-1')->refusal)->toBe('counted_cash_invalid')
        ->and(app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, 'x')->refusal)->toBe('counted_cash_invalid');
});

it('closes a counted shift with its variance, business date and frozen Z-report', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s, '200.00');
    posCashPayment($shift, '100.00');
    posCashPayment($shift, '44.00', ['direction' => PosPaymentDirection::Out]);
    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);

    expect(app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('count_required');

    app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '254.00');
    $closed = app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift, ' Short by 2 ')->model;

    expect($closed->status)->toBe(PosShiftStatus::Closed)
        ->and($closed->expected_cash)->toBe('256.00')
        ->and($closed->cash_variance)->toBe('-2.00')
        ->and($closed->closed_business_date->toDateString())->toBe('2026-10-14')
        ->and($closed->closed_by_user_id)->toBe($s['manager']->id)
        ->and($closed->closing_note)->toBe('Short by 2')
        ->and($closed->z_report_snapshot['cash']['cash_variance'])->toBe('-2.00')
        ->and($closed->z_report_snapshot['cash']['expected_cash'])->toBe('256.00')
        ->and($closed->z_report_snapshot['shift_number'])->toBe($closed->shift_number)
        ->and($closed->z_report_snapshot['tenders'])->toHaveCount(2)
        ->and(app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('shift_not_closing');
});

it('closes a shift that crossed the Night Audit onto the new business date', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s, '200.00');
    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);
    app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '200.00');

    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Closed, 'closed_at' => now(), 'closed_by' => $s['manager']->id]);
    BusinessDate::factory()->create(['property_id' => $s['property']->id, 'date' => '2026-10-15', 'status' => BusinessDateStatus::Open]);

    $closed = app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift)->model;

    expect($closed->opened_business_date->toDateString())->toBe('2026-10-14')
        ->and($closed->closed_business_date->toDateString())->toBe('2026-10-15');
});

it('cannot close while the Night Audit is closing the date', function () {
    $s = posShiftSetup();
    $shift = posOpenShift($s);
    app(BeginCloseShift::class)($s['cashier'], $s['property'], $shift);
    app(SubmitBlindCount::class)($s['cashier'], $s['property'], $shift, '200.00');
    BusinessDate::query()->where('property_id', $s['property']->id)->update(['status' => BusinessDateStatus::Auditing]);

    expect(app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift)->refusal)->toBe('business_date_auditing')
        ->and($shift->fresh()->status)->toBe(PosShiftStatus::ClosingControl);
});

it('keeps Native POS on while a shift is open and lets it go off once every shift is closed', function () {
    $s = posShiftSetup();
    $admin = User::factory()->admin()->create();
    $shift = posOpenShift($s);

    expect(app(SetPropertyFeature::class)($admin, $s['property'], PlatformFeature::Pos, false))->toBe('open_pos_shifts_exist')
        ->and(Feature::for($s['property'])->active(Pos::class))->toBeTrue();

    app(BeginCloseShift::class)($s['manager'], $s['property'], $shift);
    expect(app(SetPropertyFeature::class)($admin, $s['property'], PlatformFeature::Pos, false))->toBe('open_pos_shifts_exist');

    app(SubmitBlindCount::class)($s['manager'], $s['property'], $shift, '200.00');
    app(FinalizeCloseShift::class)($s['manager'], $s['property'], $shift);

    expect(app(SetPropertyFeature::class)($admin, $s['property'], PlatformFeature::Pos, false))->toBeNull()
        ->and(Feature::for($s['property'])->active(Pos::class))->toBeFalse();
});

it('cannot have two open shifts on one register at the database level', function () {
    $s = posShiftSetup();
    posOpenShift($s);

    expect(fn () => PosShift::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'pos_register_id' => $s['register']->id]))->toThrow(QueryException::class);
});
