<?php

use App\Actions\Commissions\GenerateDraftVouchers;
use App\Enums\CommissionEntryType;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

function voucherEntry(Property $property, string $amount, string $dueDate): CommissionEntry
{
    return CommissionEntry::factory()->create(['property_id' => $property->id, 'amount' => $amount, 'due_date' => $dueDate]);
}

function runVouchers(string $runOn): int
{
    return app(GenerateDraftVouchers::class)(CarbonImmutable::parse($runOn));
}

test('a voucher takes the unvouchered entries due up to the 15th', function () {
    $property = Property::factory()->create();
    $early = voucherEntry($property, '120.00', '2026-10-02');
    $onCutOff = voucherEntry($property, '-20.00', '2026-10-15');
    $late = voucherEntry($property, '300.00', '2026-10-16');

    expect(runVouchers('2026-10-16'))->toBe(1);

    $voucher = PayoutVoucher::query()->sole();
    expect($voucher->number)->toBe('PV-202610-'.$property->code)
        ->and($voucher->period_start->toDateString())->toBe('2026-09-16')
        ->and($voucher->period_end->toDateString())->toBe('2026-10-15')
        ->and($voucher->due_on->toDateString())->toBe('2026-11-02')
        ->and($voucher->amount)->toBe('100.00')
        ->and($voucher->status)->toBe(PayoutVoucherStatus::Draft)
        ->and($early->fresh()->payout_voucher_id)->toBe($voucher->id)
        ->and($onCutOff->fresh()->payout_voucher_id)->toBe($voucher->id)
        ->and($late->fresh()->payout_voucher_id)->toBeNull();
});

test('the payout is on the 1st when it is a weekday', function () {
    voucherEntry(Property::factory()->create(), '50.00', '2026-11-10');

    runVouchers('2026-11-16');

    expect(PayoutVoucher::query()->sole()->due_on->toDateString())->toBe('2026-12-01');
});

test('running again for the same cycle makes nothing', function () {
    $property = Property::factory()->create();
    voucherEntry($property, '50.00', '2026-10-10');

    runVouchers('2026-10-16');
    voucherEntry($property, '70.00', '2026-10-12');

    expect(runVouchers('2026-10-16'))->toBe(0)
        ->and(PayoutVoucher::query()->count())->toBe(1);
});

test('an entry recorded after the run goes into the next voucher', function () {
    $property = Property::factory()->create();
    voucherEntry($property, '50.00', '2026-10-10');
    runVouchers('2026-10-16');
    $late = voucherEntry($property, '70.00', '2026-10-15');

    runVouchers('2026-11-16');

    expect($late->fresh()->payoutVoucher->period_end->toDateString())->toBe('2026-11-15');
});

test('a property with nothing due gets no voucher', function () {
    voucherEntry(Property::factory()->create(), '50.00', '2026-10-20');

    expect(runVouchers('2026-10-16'))->toBe(0);
});

test('a small negative total is carried into the next voucher', function () {
    $property = Property::factory()->create();
    voucherEntry($property, '-30.00', '2026-10-10');

    runVouchers('2026-10-16');

    $voucher = PayoutVoucher::query()->sole();
    expect($voucher->status)->toBe(PayoutVoucherStatus::CarriedForward)
        ->and($voucher->amount)->toBe('-30.00')
        ->and($voucher->entries()->sum('amount'))->toEqual(0);

    voucherEntry($property, '100.00', '2026-11-05');
    runVouchers('2026-11-16');

    $next = PayoutVoucher::query()->where('period_end', '2026-11-15')->sole();
    expect($next->amount)->toBe('70.00')
        ->and($next->status)->toBe(PayoutVoucherStatus::Draft)
        ->and($next->entries()->where('type', CommissionEntryType::CarriedForward)->value('amount'))->toBe('-30.00');
});

test('a negative total of RM 50 or more stays a draft', function () {
    voucherEntry(Property::factory()->create(), '-50.00', '2026-10-10');

    runVouchers('2026-10-16');

    expect(PayoutVoucher::query()->sole()->status)->toBe(PayoutVoucherStatus::Draft);
});

test('after two carried vouchers in a row, the third is a draft', function () {
    $property = Property::factory()->create();
    voucherEntry($property, '-10.00', '2026-10-10');
    runVouchers('2026-10-16');
    voucherEntry($property, '-5.00', '2026-11-10');
    runVouchers('2026-11-16');
    voucherEntry($property, '-5.00', '2026-12-10');
    runVouchers('2026-12-16');

    expect(PayoutVoucher::query()->orderBy('period_end')->get()->map(fn (PayoutVoucher $voucher): array => [$voucher->status, $voucher->amount])->all())->toBe([
        [PayoutVoucherStatus::CarriedForward, '-10.00'],
        [PayoutVoucherStatus::CarriedForward, '-15.00'],
        [PayoutVoucherStatus::Draft, '-20.00'],
    ]);
});

test('an entry cannot be changed, deleted or moved to another voucher', function (Closure $change) {
    $property = Property::factory()->create();
    $entry = voucherEntry($property, '50.00', '2026-10-10');
    runVouchers('2026-10-16');

    $change($entry->fresh(), PayoutVoucher::factory()->create(['property_id' => $property->id, 'number' => 'PV-OTHER', 'period_end' => '2026-08-15', 'period_start' => '2026-07-16', 'due_on' => '2026-09-01']));
})->with([
    'amount' => [fn (CommissionEntry $entry) => CommissionEntry::query()->whereKey($entry->id)->update(['amount' => '60.00'])],
    'delete' => [fn (CommissionEntry $entry) => CommissionEntry::query()->whereKey($entry->id)->delete()],
    'voucher' => [fn (CommissionEntry $entry, PayoutVoucher $other) => CommissionEntry::query()->whereKey($entry->id)->update(['payout_voucher_id' => $other->id])],
])->throws(QueryException::class, 'immutable ledger');

test('an unvouchered entry can only be linked, not changed at the same time', function () {
    $property = Property::factory()->create();
    $entry = voucherEntry($property, '50.00', '2026-10-10');
    $voucher = PayoutVoucher::factory()->create(['property_id' => $property->id]);

    CommissionEntry::query()->whereKey($entry->id)->update(['payout_voucher_id' => $voucher->id, 'amount' => '60.00']);
})->throws(QueryException::class, 'immutable ledger');
