<?php

use App\Enums\FolioStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\SelfCheckInPayment;

require_once __DIR__.'/../Support/WalkInSetup.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
});

/**
 * A booked unit with 16.00 of Collect at check-in tax and a 100.00 deposit (116.00 due) and its empty unit folio.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: ReservationUnit, 1: Folio}
 */
function selfCheckInUnit(array $settings = []): array
{
    $setup = walkInPaymentSetup($settings);
    test()->actingAs($setup['user'])->post(route('reservations.store', ['property' => $setup['property']->code]), walkInPaymentBody($setup))->assertSessionHasNoErrors();

    $unit = ReservationUnit::query()->orderBy('id')->firstOrFail();
    $folio = Folio::factory()->forUnit($unit)->create(['property_id' => $unit->property_id, 'reservation_id' => $unit->reservation_id]);

    return [$unit, $folio];
}

function selfCheckInPayment(Folio $folio, string $amount, array $attributes = []): Payment
{
    return Payment::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'purpose' => PaymentPurpose::Advance, ...$attributes]);
}

test('a unit with nothing paid is short by the taxes plus the deposit', function () {
    [$unit] = selfCheckInUnit();

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeFalse()
        ->and(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('116.00');
});

test('an Advance that covers the taxes and the deposit is enough, counted by amount', function () {
    [$unit, $folio] = selfCheckInUnit();
    selfCheckInPayment($folio, '116.00');

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeTrue()
        ->and(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('0.00');
});

test('payments in parts add up and an overpayment is enough', function () {
    [$unit, $folio] = selfCheckInUnit();
    selfCheckInPayment($folio, '100.00');

    expect(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('16.00');

    selfCheckInPayment($folio, '300.00');

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeTrue();
});

test('a refund, a pending or failed payment and a payment on another folio do not count', function () {
    [$unit, $folio] = selfCheckInUnit();
    $paid = selfCheckInPayment($folio, '116.00');
    selfCheckInPayment($folio, '50.00', ['status' => PaymentStatus::Pending]);
    selfCheckInPayment($folio, '50.00', ['status' => PaymentStatus::Failed]);
    selfCheckInPayment(Folio::factory()->master()->create(['property_id' => $unit->property_id, 'reservation_id' => $unit->reservation_id]), '500.00');
    selfCheckInPayment($folio, '40.00', ['refund_of_id' => $paid->id, 'receipt_number' => null]);

    expect(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('40.00');
});

test('a voided payment counts as nothing', function () {
    [$unit, $folio] = selfCheckInUnit();
    $paid = selfCheckInPayment($folio, '116.00');
    selfCheckInPayment($folio, '116.00', ['refund_of_id' => $paid->id, 'voided' => true, 'receipt_number' => null]);

    expect(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('116.00');
});

test('a payment on a voided folio does not count', function () {
    [$unit, $folio] = selfCheckInUnit();
    selfCheckInPayment($folio, '116.00');
    $folio->forceFill(['status' => FolioStatus::Voided])->save();

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeFalse();
});

test('a deposit already held does not also pay the taxes', function () {
    [$unit, $folio] = selfCheckInUnit();
    selfCheckInPayment($folio, '100.00', ['purpose' => PaymentPurpose::SecurityDeposit]);

    expect(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('16.00');

    selfCheckInPayment($folio, '16.00');

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeTrue();
});

test('nothing is due when the property sets no deposit and no tax is flagged', function () {
    [$unit] = selfCheckInUnit(['security_deposit_amount' => '0.00']);
    $unit->property->taxes()->update(['collect_at_check_in' => false]);

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeTrue();
});

test('with no flagged tax only the deposit is due', function () {
    [$unit, $folio] = selfCheckInUnit();
    $unit->property->taxes()->update(['collect_at_check_in' => false]);

    expect(app(SelfCheckInPayment::class)->shortfall($unit))->toBe('100.00');

    selfCheckInPayment($folio, '100.00');

    expect(app(SelfCheckInPayment::class)->isPaidEnough($unit))->toBeTrue();
});
