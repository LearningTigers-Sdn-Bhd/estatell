<?php

use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, the system Refund code, a Confirmed unit with no folio, an actor and
 * an active Cash method with no surcharge and no reference.
 *
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, cash: PaymentMethod}
 */
function paySetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'reservation' => $reservation,
        'unit' => ReservationUnit::factory()->create(['reservation_id' => $reservation->id]),
        'cash' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]),
    ];
}

/** @param array<string, mixed> $attributes */
function payData(PaymentMethod $method, array $attributes = []): NewPaymentData
{
    return NewPaymentData::from([
        'paymentMethodId' => $method->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => '100.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
        ...$attributes,
    ]);
}

/**
 * A Card method with a surcharge posted through a fee charge item that carries an 8% tax.
 */
function paySurchargeMethod(Property $property, SurchargeType $type, string $value): PaymentMethod
{
    $item = ChargeItem::factory()->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($item->transaction_code_id);

    return PaymentMethod::factory()->create([
        'property_id' => $property->id,
        'surcharge_type' => $type,
        'surcharge_value' => $value,
        'surcharge_charge_item_id' => $item->id,
    ]);
}

/**
 * @param  array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, cash: PaymentMethod}  $setup
 * @param  array<string, mixed>  $attributes
 */
function payDeposit(array $setup, array $attributes = []): PaymentResult
{
    return app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], payData($setup['cash'], $attributes));
}

function payBalance(Folio $folio): string
{
    return app(FolioBalance::class)->forFolio($folio);
}

function payWritesCount(): int
{
    return Payment::query()->count() + FolioLine::query()->count() + Folio::query()->count();
}

test('a deposit on a unit with no folio creates the unit folio, a receipt number and a negative balance', function () {
    $setup = paySetup();

    $result = payDeposit($setup, ['reference' => '  slip 12  ']);

    $payment = $result->payment;
    $line = $result->lines[0];

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(1)
        ->and($payment->folio->type)->toBe(FolioType::Unit)
        ->and($payment->folio->reservation_unit_id)->toBe($setup['unit']->id)
        ->and($payment->amount)->toBe('100.00')
        ->and($payment->purpose)->toBe(PaymentPurpose::Advance)
        ->and($payment->status)->toBe(PaymentStatus::Completed)
        ->and($payment->reference)->toBe('slip 12')
        ->and($payment->receipt_number)->not->toBeEmpty()
        ->and($payment->surcharge_line_id)->toBeNull()
        ->and($payment->folio_line_id)->toBe($line->id)
        ->and($line->kind)->toBe(TransactionKind::Payment)
        ->and($line->transaction_code_id)->toBe($setup['cash']->transaction_code_id)
        ->and($line->description)->toBe($setup['cash']->name)
        ->and($line->amount)->toBe('-100.00')
        ->and($line->idempotency_key)->toStartWith('payment:')
        ->and($line->posting_date->toDateString())->toBe(today()->toDateString())
        ->and(payBalance($payment->folio))->toBe('-100.00')
        ->and(Activity::query()->where('event', 'payment.recorded')->count())->toBe(1);
});

test('a second payment on the unit uses its folio, and receipt numbers follow the sequence', function () {
    $setup = paySetup();

    $first = payDeposit($setup)->payment;
    $second = payDeposit($setup)->payment;

    expect($second->folio_id)->toBe($first->folio_id)
        ->and(Folio::query()->count())->toBe(1)
        ->and($second->receipt_number)->not->toBe($first->receipt_number)
        ->and(payBalance($first->folio))->toBe('-200.00');
});

test('a payment on a given folio posts there', function () {
    $setup = paySetup();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $result = app(RecordPayment::class)($setup['actor'], $setup['reservation'], $folio, null, payData($setup['cash'], ['purpose' => PaymentPurpose::Settlement]));

    expect($result->payment->folio_id)->toBe($folio->id)
        ->and(payBalance($folio))->toBe('-100.00');
});

test('a surcharge posts as a taxed charge on the same folio and the balance falls by the amount entered', function (SurchargeType $type, string $value, string $fee, string $tax, string $total) {
    $setup = paySetup();
    $card = paySurchargeMethod($setup['property'], $type, $value);

    $result = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], payData($card, ['reference' => 'AUTH-1']));

    [$surcharge, $surchargeTax, $line] = $result->lines;
    $payment = $result->payment;

    expect($result->lines)->toHaveCount(3)
        ->and($surcharge->kind)->toBe(TransactionKind::Charge)
        ->and($surcharge->amount)->toBe($fee)
        ->and($surcharge->folio_id)->toBe($payment->folio_id)
        ->and($surcharge->idempotency_key)->toBe($line->idempotency_key.':surcharge')
        ->and($surchargeTax->kind)->toBe(TransactionKind::Tax)
        ->and($surchargeTax->amount)->toBe($tax)
        ->and($surchargeTax->parent_line_id)->toBe($surcharge->id)
        ->and($line->amount)->toBe('-'.$total)
        ->and($payment->amount)->toBe($total)
        ->and($payment->surcharge_line_id)->toBe($surcharge->id)
        ->and(payBalance($payment->folio))->toBe('-100.00');
})->with([
    'fixed' => [SurchargeType::Fixed, '5.00', '5.00', '0.40', '105.40'],
    'percent' => [SurchargeType::Percent, '2.50', '2.50', '0.20', '102.70'],
]);

test('a repeated key returns the existing payment and writes nothing', function () {
    $setup = paySetup();
    $data = payData($setup['cash']);
    $first = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], $data);
    $before = payWritesCount();

    $again = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], $data);

    expect($again->replayed)->toBeTrue()
        ->and($again->payment->id)->toBe($first->payment->id)
        ->and(payWritesCount())->toBe($before)
        ->and(Activity::query()->where('event', 'payment.recorded')->count())->toBe(1);
});

test('a repeated key from another reservation is refused', function () {
    $setup = paySetup();
    $data = payData($setup['cash']);
    payDeposit($setup, ['idempotencyKey' => $data->idempotencyKey]);
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $otherUnit = ReservationUnit::factory()->create(['reservation_id' => $other->id]);

    $result = app(RecordPayment::class)($setup['actor'], $other, null, $otherUnit, $data);

    expect($result->refusal)->toBe(PaymentResult::REFUSAL_PAYMENT_NOT_FOUND);
});

test('a payment refusal writes and logs nothing', function (string $refusal, Closure $arrange) {
    $setup = paySetup();
    $before = payWritesCount();
    $data = $arrange($setup);

    $result = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], $data);

    expect($result->refusal)->toBe($refusal)
        ->and(payWritesCount())->toBe($before)
        ->and(Activity::query()->where('event', 'payment.recorded')->count())->toBe(0);
})->with([
    'no business date' => [PaymentResult::REFUSAL_NO_BUSINESS_DATE, function (array $setup) {
        BusinessDate::query()->delete();

        return payData($setup['cash']);
    }],
    'inactive method' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => payData(PaymentMethod::factory()->inactive()->create(['property_id' => $setup['property']->id]))],
    'another property\'s method' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => payData(PaymentMethod::factory()->create())],
    'city ledger' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => payData(PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::DirectBill]))],
    'ota collected' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, fn (array $setup) => payData(PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::OtaCollected]))],
    'inactive surcharge item' => [PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, function (array $setup) {
        $card = paySurchargeMethod($setup['property'], SurchargeType::Fixed, '5.00');
        $card->surchargeChargeItem->update(['active' => false]);

        return payData($card, ['reference' => 'AUTH-1']);
    }],
    'zero amount' => [PaymentResult::REFUSAL_AMOUNT_INVALID, fn (array $setup) => payData($setup['cash'], ['amount' => '0.00'])],
    'three decimals' => [PaymentResult::REFUSAL_AMOUNT_INVALID, fn (array $setup) => payData($setup['cash'], ['amount' => '10.005'])],
    'missing reference' => [PaymentResult::REFUSAL_REFERENCE_REQUIRED, fn (array $setup) => payData(PaymentMethod::factory()->create(['property_id' => $setup['property']->id]), ['reference' => '   '])],
    'surcharge tax needs a nationality' => ['nationality_required', function (array $setup) {
        $card = paySurchargeMethod($setup['property'], SurchargeType::Fixed, '5.00');
        Tax::factory()->for($setup['property'])->create(['foreign_guests_only' => true])->applicableTransactionCodes()->attach($card->surchargeChargeItem->transaction_code_id);
        $setup['reservation']->booker->update(['nationality_code' => null]);

        return payData($card, ['reference' => 'AUTH-1']);
    }],
]);

test('a closed folio refuses a payment', function () {
    $setup = paySetup();
    $folio = Folio::factory()->master()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $result = app(RecordPayment::class)($setup['actor'], $setup['reservation'], $folio, null, payData($setup['cash']));

    expect($result->refusal)->toBe(PaymentResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(Payment::query()->count())->toBe(0);
});

test('a refused payment gives its receipt number back', function () {
    $setup = paySetup();
    $first = payDeposit($setup)->payment;

    try {
        DB::transaction(function () use ($setup): void {
            payDeposit($setup);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    $next = payDeposit($setup)->payment;

    expect((int) substr($next->receipt_number, -4) - (int) substr($first->receipt_number, -4))->toBe(1);
});

test('a refund posts a positive Refund line, copies the purpose and method, and has no receipt number', function () {
    $setup = paySetup();
    $payment = payDeposit($setup)->payment;

    $result = app(RefundPayment::class)($setup['actor'], $payment, '40.00', ' RF-9 ', fake()->uuid());

    $refund = $result->payment;
    $line = $result->lines[0];

    expect($result->isRefused())->toBeFalse()
        ->and($refund->refund_of_id)->toBe($payment->id)
        ->and($refund->folio_id)->toBe($payment->folio_id)
        ->and($refund->purpose)->toBe($payment->purpose)
        ->and($refund->payment_method_id)->toBe($payment->payment_method_id)
        ->and($refund->amount)->toBe('40.00')
        ->and($refund->reference)->toBe('RF-9')
        ->and($refund->receipt_number)->toBeNull()
        ->and($line->amount)->toBe('40.00')
        ->and($line->transactionCode->system_key)->toBe(SystemTransactionCode::Refund->value)
        ->and($line->idempotency_key)->toStartWith('refund:')
        ->and(payBalance($payment->folio))->toBe('-60.00')
        ->and(Activity::query()->where('event', 'payment.refunded')->count())->toBe(1);
});

test('refunds stop at the payment amount, and the surcharge is part of what can be refunded', function () {
    $setup = paySetup();
    $card = paySurchargeMethod($setup['property'], SurchargeType::Fixed, '5.00');
    $payment = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], payData($card, ['reference' => 'AUTH-1']))->payment;
    $refund = app(RefundPayment::class);

    $refund($setup['actor'], $payment, '100.00', null, fake()->uuid());
    $over = $refund($setup['actor'], $payment, '5.41', null, fake()->uuid());
    $rest = $refund($setup['actor'], $payment, '5.40', null, fake()->uuid());

    expect($over->refusal)->toBe(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT)
        ->and($rest->isRefused())->toBeFalse()
        ->and($payment->refunds()->count())->toBe(2);
});

test('a repeated refund key returns the existing refund and writes nothing', function () {
    $setup = paySetup();
    $payment = payDeposit($setup)->payment;
    $key = fake()->uuid();
    $first = app(RefundPayment::class)($setup['actor'], $payment, '10.00', null, $key);
    $before = payWritesCount();

    $again = app(RefundPayment::class)($setup['actor'], $payment, '10.00', null, $key);

    expect($again->replayed)->toBeTrue()
        ->and($again->payment->id)->toBe($first->payment->id)
        ->and(payWritesCount())->toBe($before);
});

test('a refund refusal writes and logs nothing', function (string $refusal, Closure $arrange) {
    $setup = paySetup();
    $payment = payDeposit($setup)->payment;
    [$target, $amount] = $arrange($setup, $payment);
    $before = payWritesCount();
    $logged = Activity::query()->count();

    $result = app(RefundPayment::class)($setup['actor'], $target, $amount, null, fake()->uuid());

    expect($result->refusal)->toBe($refusal)
        ->and(payWritesCount())->toBe($before)
        ->and(Activity::query()->count())->toBe($logged);
})->with([
    'no business date' => [PaymentResult::REFUSAL_NO_BUSINESS_DATE, function (array $setup, Payment $payment) {
        BusinessDate::query()->delete();

        return [$payment, '10.00'];
    }],
    'a refund' => [PaymentResult::REFUSAL_NOT_REFUNDABLE, fn (array $setup, Payment $payment) => [app(RefundPayment::class)($setup['actor'], $payment, '10.00', null, fake()->uuid())->payment, '5.00']],
    'closed folio' => [PaymentResult::REFUSAL_FOLIO_NOT_OPEN, function (array $setup, Payment $payment) {
        $payment->folio->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $setup['actor']->id])->save();

        return [$payment, '10.00'];
    }],
    'invalid amount' => [PaymentResult::REFUSAL_AMOUNT_INVALID, fn (array $setup, Payment $payment) => [$payment, '-5']],
    'more than paid' => [PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT, fn (array $setup, Payment $payment) => [$payment, '100.01']],
]);

test('payment and refund lines cannot be reversed or transferred, but the surcharge charge can', function () {
    $setup = paySetup();
    $card = paySurchargeMethod($setup['property'], SurchargeType::Fixed, '5.00');
    $payment = app(RecordPayment::class)($setup['actor'], $setup['reservation'], null, $setup['unit'], payData($card, ['reference' => 'AUTH-1']))->payment;
    $refund = app(RefundPayment::class)($setup['actor'], $payment, '10.00', null, fake()->uuid())->payment;
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    expect(app(ReverseLine::class)($setup['actor'], $payment->folioLine)->refusal)->toBe(PostingResult::REFUSAL_PAYMENT_LINE)
        ->and(app(ReverseLine::class)($setup['actor'], $refund->folioLine)->refusal)->toBe(PostingResult::REFUSAL_PAYMENT_LINE)
        ->and(app(TransferLines::class)($setup['actor'], [$payment->folioLine], $master)->refusal)->toBe(PostingResult::REFUSAL_PAYMENT_LINE)
        ->and(app(ReverseLine::class)($setup['actor'], $payment->surchargeLine)->isRefused())->toBeFalse();
});

test('a charge posted after a deposit reuses the deposit folio', function () {
    $setup = paySetup();
    $payment = payDeposit($setup)->payment;
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '100.00']);

    $result = app(PostCharge::class)($setup['actor'], $setup['unit']->refresh(), NewChargeData::from([
        'chargeItemId' => $item->id,
        'transactionCodeId' => null,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '100.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]));

    expect($result->refusal)->toBeNull()
        ->and($result->line()->folio_id)->toBe($payment->folio_id)
        ->and(payBalance($payment->folio))->toBe('0.00');
});

test('payment rows are immutable in the database', function () {
    $setup = paySetup();
    $payment = payDeposit($setup)->payment;

    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['reference' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payments')->where('id', $payment->id)->delete())->toThrow(QueryException::class);
});
