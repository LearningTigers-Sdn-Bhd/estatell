<?php

use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundFolioCredit;
use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\VoidPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, the Refund code, a unit, a staff user who may refund to another method,
 * one who may only refund to the payment's own method, and a Cash, Card and Bank transfer method.
 *
 * @return array{property: Property, reservation: Reservation, unit: ReservationUnit, manager: User, clerk: User, cash: PaymentMethod, card: PaymentMethod, bank: PaymentMethod}
 */
function rmSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    setPermissionsTeamId($property->id);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $method = fn (PaymentMethodType $type): PaymentMethod => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => $type, 'requires_reference' => false]);

    return [
        'property' => $property,
        'reservation' => $reservation,
        'unit' => ReservationUnit::factory()->create(['reservation_id' => $reservation->id]),
        'manager' => User::factory()->for($property)->withPermissions([PermissionEnum::PaymentRefund, PermissionEnum::PaymentRefundOtherMethod])->create(),
        'clerk' => User::factory()->for($property)->withPermissions([PermissionEnum::PaymentRefund])->create(),
        'cash' => $method(PaymentMethodType::Cash),
        'card' => $method(PaymentMethodType::Card),
        'bank' => $method(PaymentMethodType::BankTransfer),
    ];
}

function rmPay(array $setup, PaymentMethod $method, string $amount = '100.00'): Payment
{
    return app(RecordPayment::class)($setup['manager'], $setup['reservation'], null, $setup['unit'], NewPaymentData::from([
        'paymentMethodId' => $method->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => $amount,
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
    ]))->payment;
}

test('a refund to another method takes the new method and keeps the reason', function () {
    $setup = rmSetup();
    $payment = rmPay($setup, $setup['card']);

    $result = app(RefundPayment::class)($setup['manager'], $payment, '40.00', null, fake()->uuid(), methodId: $setup['bank']->id, reason: ' Guest asked for a transfer ');

    expect($result->isRefused())->toBeFalse()
        ->and($result->payment->payment_method_id)->toBe($setup['bank']->id)
        ->and($result->payment->refund_reason)->toBe('Guest asked for a transfer')
        ->and($result->payment->voided)->toBeFalse()
        ->and(Activity::query()->where('event', 'payment.refunded')->latest('id')->first()->properties['reason'])->toBe('Guest asked for a transfer');
});

test('a cash payment can be refunded to a bank transfer, and the own method needs no right or reason', function () {
    $setup = rmSetup();
    $payment = rmPay($setup, $setup['cash']);

    $other = app(RefundPayment::class)($setup['manager'], $payment, '10.00', null, fake()->uuid(), methodId: $setup['bank']->id, reason: 'Transfer');
    $own = app(RefundPayment::class)($setup['clerk'], $payment, '10.00', null, fake()->uuid(), methodId: $setup['cash']->id);

    expect($other->isRefused())->toBeFalse()
        ->and($own->isRefused())->toBeFalse()
        ->and($own->payment->payment_method_id)->toBe($setup['cash']->id)
        ->and($own->payment->refund_reason)->toBeNull();
});

test('a refund to another method is refused and writes nothing', function (string $refusal, Closure $arrange) {
    $setup = rmSetup();
    $payment = rmPay($setup, $setup['card']);
    [$actor, $methodId, $reason] = $arrange($setup);
    $before = Payment::query()->count() + FolioLine::query()->count();
    $logged = Activity::query()->count();

    $result = app(RefundPayment::class)($actor, $payment, '10.00', null, fake()->uuid(), methodId: $methodId, reason: $reason);

    expect($result->refusal)->toBe($refusal)
        ->and(Payment::query()->count() + FolioLine::query()->count())->toBe($before)
        ->and(Activity::query()->count())->toBe($logged);
})->with([
    'no right' => [PaymentResult::REFUSAL_OTHER_METHOD_NOT_ALLOWED, fn (array $setup) => [$setup['clerk'], $setup['bank']->id, 'Transfer']],
    'no reason' => [PaymentResult::REFUSAL_REASON_REQUIRED, fn (array $setup) => [$setup['manager'], $setup['bank']->id, '  ']],
    'cash for a card payment' => [PaymentResult::REFUSAL_REFUND_METHOD_MISMATCH, fn (array $setup) => [$setup['manager'], $setup['cash']->id, 'Guest wants cash']],
    'inactive method' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, function (array $setup) {
        $setup['bank']->forceFill(['active' => false])->save();

        return [$setup['manager'], $setup['bank']->id, 'Transfer'];
    }],
    'method of another property' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => [$setup['manager'], PaymentMethod::factory()->create(['type' => PaymentMethodType::BankTransfer])->id, 'Transfer']],
    'City ledger method' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => [$setup['manager'], PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::DirectBill])->id, 'Ledger']],
]);

test('voiding a payment taken today refunds all of it to its own method and marks the refund', function () {
    $setup = rmSetup();
    $payment = rmPay($setup, $setup['card'], '75.00');

    $result = app(VoidPayment::class)($setup['clerk'], $payment, fake()->uuid());

    expect($result->isRefused())->toBeFalse()
        ->and($result->payment->voided)->toBeTrue()
        ->and($result->payment->amount)->toBe('75.00')
        ->and($result->payment->payment_method_id)->toBe($setup['card']->id)
        ->and($result->lines[0]->description)->toBe('Void payment')
        ->and(app(FolioBalance::class)->forFolio($payment->folio))->toBe('0.00')
        ->and(Activity::query()->where('event', 'payment.voided')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'payment.refunded')->count())->toBe(0);
});

test('a void is refused on a later day or when the payment already has a refund', function () {
    $setup = rmSetup();
    $later = rmPay($setup, $setup['card']);
    $refunded = rmPay($setup, $setup['card']);
    app(RefundPayment::class)($setup['manager'], $refunded, '1.00', null, fake()->uuid());
    $key = fake()->uuid();
    $first = app(VoidPayment::class)($setup['clerk'], rmPay($setup, $setup['cash']), $key);
    $again = app(VoidPayment::class)($setup['clerk'], $first->payment->refundOf, $key);

    $hasRefund = app(VoidPayment::class)($setup['clerk'], $refunded, fake()->uuid());
    BusinessDate::query()->update(['date' => today()->addDay()]);
    $oldDay = app(VoidPayment::class)($setup['clerk'], $later, fake()->uuid());

    expect($hasRefund->refusal)->toBe(PaymentResult::REFUSAL_VOID_HAS_REFUND)
        ->and($oldDay->refusal)->toBe(PaymentResult::REFUSAL_VOID_NOT_SAME_DAY)
        ->and($again->replayed)->toBeTrue()
        ->and($again->payment->id)->toBe($first->payment->id)
        ->and(Payment::query()->where('voided', true)->count())->toBe(1);
});

test('only a refund can carry a reason or a void mark', function () {
    $setup = rmSetup();

    expect(fn () => Payment::factory()->create(['folio_id' => rmPay($setup, $setup['cash'])->folio_id, 'voided' => true]))->toThrow(QueryException::class);
});

test('refund credit gives the credit back from the payments, deposits first, and a repeat changes nothing', function () {
    $setup = rmSetup();
    $advance = rmPay($setup, $setup['cash'], '60.00');
    $deposit = app(RecordPayment::class)($setup['manager'], $setup['reservation'], $advance->folio, null, NewPaymentData::from([
        'paymentMethodId' => $setup['card']->id, 'purpose' => PaymentPurpose::SecurityDeposit, 'amount' => '50.00', 'reference' => null, 'idempotencyKey' => fake()->uuid(),
    ]))->payment;
    $key = fake()->uuid();

    $result = app(RefundFolioCredit::class)($setup['clerk'], $advance->folio, '70.00', null, null, $key);
    $again = app(RefundFolioCredit::class)($setup['clerk'], $advance->folio, '70.00', null, null, $key);

    expect($result->refusal)->toBeNull()
        ->and($again->isRefused())->toBeFalse()
        ->and($deposit->refunds()->sole()->amount)->toBe('50.00')
        ->and($advance->refunds()->sole()->amount)->toBe('20.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(2)
        ->and(app(FolioBalance::class)->forFolio($advance->folio))->toBe('-40.00');
});

test('refund credit refuses an amount above the credit and rolls back when a part is refused', function () {
    $setup = rmSetup();
    $card = rmPay($setup, $setup['card'], '60.00');

    $tooMuch = app(RefundFolioCredit::class)($setup['manager'], $card->folio, '60.01', null, null, fake()->uuid());
    $noRight = app(RefundFolioCredit::class)($setup['clerk'], $card->folio, '30.00', $setup['bank']->id, 'Transfer', fake()->uuid());
    $cash = app(RefundFolioCredit::class)($setup['manager'], $card->folio, '30.00', $setup['cash']->id, 'Cash', fake()->uuid());

    expect($tooMuch->refusal)->toBe(PaymentResult::REFUSAL_CREDIT_NOT_REFUNDABLE)
        ->and($noRight->refusal)->toBe(PaymentResult::REFUSAL_OTHER_METHOD_NOT_ALLOWED)
        ->and($cash->refusal)->toBe(PaymentResult::REFUSAL_REFUND_METHOD_MISMATCH)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('every refund right is on the right default roles', function () {
    $roles = collect(DefaultRoleEnum::cases())->mapWithKeys(fn (DefaultRoleEnum $role): array => [$role->value => $role->permissions()]);

    expect($roles['front_desk'])->toContain(PermissionEnum::PaymentRefund)->not->toContain(PermissionEnum::PaymentRefundOtherMethod)
        ->and($roles['finance'])->toContain(PermissionEnum::PaymentRefund, PermissionEnum::PaymentRefundOtherMethod)
        ->and($roles['manager'])->toContain(PermissionEnum::PaymentRefund, PermissionEnum::PaymentRefundOtherMethod)
        ->and($roles['housekeeping'])->not->toContain(PermissionEnum::PaymentRefund);
});
