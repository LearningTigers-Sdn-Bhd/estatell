<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Payments\PaymentReceipt;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date, an owner, the system Refund code, a reservation with one checked-in
 * unit that has a Unit folio, and a Cash method with no reference.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, folio: Folio, cash: PaymentMethod}
 */
function fpSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'folio' => app(CreateFolio::class)($reservation, $unit),
        'cash' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'position' => 1]),
    ];
}

/** @param array<string, mixed> $extra */
function fpUrl(array $setup, string $route, array $extra = []): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$extra]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fpPayload(PaymentMethod $method, array $overrides = []): array
{
    return [
        'payment_method_id' => $method->id,
        'purpose' => PaymentPurpose::Settlement->value,
        'amount' => '80.00',
        'reference' => '',
        'idempotency_key' => fake()->uuid(),
        ...$overrides,
    ];
}

function fpPay(array $setup, string $amount = '80.00', ?string $reference = null): Payment
{
    return app(RecordPayment::class)($setup['user'], $setup['reservation'], $setup['folio'], null, NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id,
        'purpose' => PaymentPurpose::Settlement,
        'amount' => $amount,
        'reference' => $reference,
        'idempotencyKey' => fake()->uuid(),
    ]))->payment;
}

function fpStaff(array $setup, DefaultRoleEnum $role): User
{
    return User::factory()->withRole($role)->create(['property_id' => $setup['property']->id]);
}

test('recording a payment on a folio posts it, shows the receipt in the toast and stays on the folio', function () {
    $setup = fpSetup();

    $this->actingAs($setup['user'])
        ->post(fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $setup['folio']->id]), fpPayload($setup['cash']))
        ->assertRedirect(fpUrl($setup, 'reservations.folio', ['folio' => $setup['folio']->id]))
        ->assertInertiaFlash('toast.type', 'success');

    $payment = Payment::query()->sole();

    expect($payment->folio_id)->toBe($setup['folio']->id)
        ->and($payment->amount)->toBe('80.00')
        ->and($payment->purpose)->toBe(PaymentPurpose::Settlement);
});

test('the same idempotency key records once', function () {
    $setup = fpSetup();
    $payload = fpPayload($setup['cash']);
    $url = fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->post($url, $payload)->assertSessionHasNoErrors();
    $this->actingAs($setup['user'])->post($url, $payload)->assertSessionHasNoErrors();

    expect(Payment::query()->count())->toBe(1);
});

test('a payment on a not-started unit creates its folio and opens it', function () {
    $setup = fpSetup();
    $unit = ReservationUnit::factory()->withNights()->create(['reservation_id' => $setup['reservation']->id]);

    $response = $this->actingAs($setup['user'])
        ->post(fpUrl($setup, 'reservations.units.payments.store', ['unit' => $unit->id]), fpPayload($setup['cash'], ['purpose' => 'advance']));

    $folio = Folio::query()->where('reservation_unit_id', $unit->id)->sole();

    $response->assertRedirect(fpUrl($setup, 'reservations.folio', ['folio' => $folio->id]));
    expect($folio->payments()->sole()->purpose)->toBe(PaymentPurpose::Advance);
});

test('a payment refusal comes back as an error on its field and writes nothing', function (Closure $payload, string $field) {
    $setup = fpSetup();

    $this->actingAs($setup['user'])
        ->post(fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $setup['folio']->id]), $payload($setup))
        ->assertSessionHasErrors($field);

    expect(Payment::query()->count())->toBe(0);
})->with([
    'missing reference' => [fn (array $setup) => fpPayload(PaymentMethod::factory()->create(['property_id' => $setup['property']->id])), 'reference'],
    'zero amount' => [fn (array $setup) => fpPayload($setup['cash'], ['amount' => '0.00']), 'amount'],
    'badly formed amount' => [fn (array $setup) => fpPayload($setup['cash'], ['amount' => '12,50']), 'amount'],
    'city ledger' => [fn (array $setup) => fpPayload(PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::DirectBill])), 'payment_method_id'],
    'closed folio' => [function (array $setup) {
        app(CloseFolio::class)($setup['user'], $setup['folio']);

        return fpPayload($setup['cash']);
    }, 'form'],
]);

test('the quote shows the surcharge, its tax and the total, or a message', function () {
    $setup = fpSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id]);
    Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'is_inclusive' => false])->applicableTransactionCodes()->attach($item->transaction_code_id);
    $card = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'surcharge_type' => SurchargeType::Percent, 'surcharge_value' => '2.50', 'surcharge_charge_item_id' => $item->id]);
    $url = fpUrl($setup, 'reservations.payments.quote');

    $this->actingAs($setup['user'])->postJson($url, ['payment_method_id' => $card->id, 'amount' => '100.00'])
        ->assertOk()
        ->assertExactJson(['amount' => '100.00', 'surcharge' => '2.50', 'surchargeTax' => '0.20', 'total' => '102.70', 'error' => null]);

    $this->actingAs($setup['user'])->postJson($url, ['payment_method_id' => $card->id, 'amount' => 'abc'])
        ->assertOk()
        ->assertJsonPath('total', null)
        ->assertJsonPath('error', 'Enter an amount greater than 0.');

    $this->actingAs($setup['user'])->postJson($url, ['payment_method_id' => PaymentMethod::factory()->create()->id, 'amount' => '10.00'])->assertNotFound();
});

test('refunding a payment posts the refund and a refund over what is left is an error on the amount', function () {
    $setup = fpSetup();
    $payment = fpPay($setup);
    $url = fpUrl($setup, 'reservations.folios.payments.refunds.store', ['folio' => $setup['folio']->id, 'payment' => $payment->id]);

    $this->actingAs($setup['user'])->post($url, ['amount' => '30.00', 'reference' => 'RF-1', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');
    $this->actingAs($setup['user'])->post($url, ['amount' => '50.01', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('amount');

    expect($payment->refunds()->sole()->amount)->toBe('30.00');
});

test('payment rows carry the reference, the amount left and the refund action only when allowed', function () {
    $setup = fpSetup();
    $payment = fpPay($setup, '80.00', 'SLIP-7');
    $refund = app(RefundPayment::class)($setup['user'], $payment, '30.00', null, fake()->uuid())->payment;
    $full = fpPay($setup, '10.00');
    app(RefundPayment::class)($setup['user'], $full, '10.00', null, fake()->uuid());
    $url = fpUrl($setup, 'reservations.folio', ['folio' => $setup['folio']->id]);
    $row = fn (array $rows, int $lineId): array => collect($rows)->firstWhere('lineId', $lineId);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canRecordPayment', true)
        ->where('folio.paymentUnitId', null)
        ->where('folio.rows', fn ($rows) => $row($rows->all(), $payment->folio_line_id)['note'] === 'SLIP-7'
            && $row($rows->all(), $payment->folio_line_id)['paymentId'] === $payment->id
            && $row($rows->all(), $payment->folio_line_id)['refundable'] === '50.00'
            && $row($rows->all(), $payment->folio_line_id)['actions'] === ['refund']
            && $row($rows->all(), $refund->folio_line_id)['tags'] === ['Refund']
            && $row($rows->all(), $refund->folio_line_id)['actions'] === []
            && $row($rows->all(), $full->folio_line_id)['refundable'] === '0.00'
            && $row($rows->all(), $full->folio_line_id)['actions'] === []));

    $this->actingAs(fpStaff($setup, DefaultRoleEnum::FrontDesk))->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canRecordPayment', true)
        ->where('folio.rows', fn ($rows) => collect($rows)->every(fn ($row) => ! in_array('refund', $row['actions'], true))));
});

test('a not-started entry offers Record payment for its unit, and a closed folio offers neither action', function () {
    $setup = fpSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);
    $unit = ReservationUnit::factory()->withNights()->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get(fpUrl($setup, 'reservations.folio', ['folio' => 'u'.$unit->id]))->assertInertia(fn (Assert $page) => $page
        ->where('folio.kind', 'not_started')
        ->where('folio.canRecordPayment', true)
        ->where('folio.paymentUnitId', $unit->id));

    $payment = fpPay($setup, '0.01');
    app(RefundPayment::class)($setup['user'], $payment, '0.01', null, fake()->uuid());
    app(CloseFolio::class)($setup['user'], $setup['folio']);

    $this->actingAs($setup['user'])->get(fpUrl($setup, 'reservations.folio', ['folio' => $setup['folio']->id]))->assertInertia(fn (Assert $page) => $page
        ->where('folio.canRecordPayment', false)
        ->where('folio.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['actions'] === []))
        ->where('folio.rows', fn ($rows) => collect($rows)->firstWhere('lineId', $payment->folio_line_id)['hasReceipt'] === true
            && collect($rows)->where('tags', ['Refund'])->every(fn ($row) => $row['hasReceipt'] === false)));
});

test('the payment routes need their permission and stay inside the property and the reservation', function () {
    $setup = fpSetup();
    $payment = fpPay($setup);
    $refundUrl = fpUrl($setup, 'reservations.folios.payments.refunds.store', ['folio' => $setup['folio']->id, 'payment' => $payment->id]);

    $this->actingAs(fpStaff($setup, DefaultRoleEnum::FrontDesk))->post($refundUrl, ['amount' => '1.00', 'idempotency_key' => fake()->uuid()])->assertForbidden();
    $this->actingAs(fpStaff($setup, DefaultRoleEnum::Housekeeping))->post(fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $setup['folio']->id]), fpPayload($setup['cash']))->assertForbidden();
    $this->actingAs(fpStaff($setup, DefaultRoleEnum::Housekeeping))->postJson(fpUrl($setup, 'reservations.payments.quote'), ['payment_method_id' => $setup['cash']->id, 'amount' => '1.00'])->assertForbidden();

    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreignFolio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id]);
    $foreignUnit = ReservationUnit::factory()->create(['reservation_id' => $other->id]);
    $foreignPayment = Payment::factory()->create(['folio_id' => $foreignFolio->id]);

    $this->actingAs($setup['user'])->post(fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $foreignFolio->id]), fpPayload($setup['cash']))->assertNotFound();
    $this->actingAs($setup['user'])->post(fpUrl($setup, 'reservations.units.payments.store', ['unit' => $foreignUnit->id]), fpPayload($setup['cash']))->assertNotFound();
    $this->actingAs($setup['user'])->post(fpUrl($setup, 'reservations.folios.payments.refunds.store', ['folio' => $setup['folio']->id, 'payment' => $foreignPayment->id]), ['amount' => '1.00', 'idempotency_key' => fake()->uuid()])->assertNotFound();

    $outsider = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $this->actingAs($outsider)->post(fpUrl($setup, 'reservations.folios.payments.store', ['folio' => $setup['folio']->id]), fpPayload($setup['cash']))->assertNotFound();

    expect(Payment::query()->count())->toBe(2);
});

test('the payment options load on request only and leave out city ledger and OTA collected', function () {
    $setup = fpSetup();
    PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::DirectBill, 'position' => 2]);
    $url = fpUrl($setup, 'reservations.folio');

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page->missing('paymentOptions'));

    $this->actingAs($setup['user'])
        ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'paymentOptions', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])
        ->assertOk()
        ->assertJsonCount(1, 'props.paymentOptions.methods')
        ->assertJsonPath('props.paymentOptions.methods.0.id', $setup['cash']->id)
        ->assertJsonPath('props.paymentOptions.purposes.0.value', 'advance');
});

test('the receipt opens inline as an A4 PDF named after the receipt number', function () {
    $setup = fpSetup();
    $payment = fpPay($setup);

    $response = $this->actingAs($setup['user'])
        ->get(fpUrl($setup, 'reservations.folios.payments.receipt', ['folio' => $setup['folio']->id, 'payment' => $payment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('receipt-'.$payment->receipt_number.'.pdf')
        ->and($response->getContent())->toStartWith('%PDF-');
});

test('the receipt shows the payer, the amounts from the ledger and the property', function () {
    $setup = fpSetup();
    $setup['property']->forceFill(['name' => 'Grand River Homestay', 'tin' => 'C1234567890'])->save();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Card fee']);
    Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'is_inclusive' => false])->applicableTransactionCodes()->attach($item->transaction_code_id);
    $card = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Visa', 'surcharge_type' => SurchargeType::Fixed, 'surcharge_value' => '5.00', 'surcharge_charge_item_id' => $item->id]);
    $payment = app(RecordPayment::class)($setup['user'], $setup['reservation'], $setup['folio'], null, NewPaymentData::from([
        'paymentMethodId' => $card->id, 'purpose' => PaymentPurpose::Settlement, 'amount' => '100.00', 'reference' => 'AUTH-9', 'idempotencyKey' => fake()->uuid(),
    ]))->payment;

    $receipt = app(PaymentReceipt::class)->forPayment($payment);
    $html = view('pdf.receipt', ['receipt' => $receipt])->render();

    expect($receipt->payerName)->toBe($setup['reservation']->booker->full_name)
        ->and($receipt->amountPaid)->toBe('100.00')
        ->and($receipt->surcharge)->toBe('5.00')
        ->and($receipt->surchargeTax)->toBe('0.40')
        ->and($receipt->total)->toBe('105.40')
        ->and($receipt->reference)->toBe('AUTH-9')
        ->and($receipt->registrationLines)->toBe(['TIN C1234567890'])
        ->and($html)->toContain('Grand River Homestay')
        ->toContain($payment->receipt_number)
        ->toContain('MYR 105.40')
        ->toContain('Tax on surcharge')
        ->toContain($setup['folio']->number);
});

test('a receipt without a surcharge leaves the surcharge lines out', function () {
    $setup = fpSetup();
    $payment = fpPay($setup);

    $receipt = app(PaymentReceipt::class)->forPayment($payment);

    expect($receipt->surcharge)->toBeNull()
        ->and($receipt->amountPaid)->toBe('80.00')
        ->and(view('pdf.receipt', ['receipt' => $receipt])->render())->not->toContain('Surcharge');
});

test('a refund has no receipt, and the receipt needs payment.record inside the reservation', function () {
    $setup = fpSetup();
    $payment = fpPay($setup);
    $refund = app(RefundPayment::class)($setup['user'], $payment, '10.00', null, fake()->uuid())->payment;
    $url = fn (Payment $one): string => fpUrl($setup, 'reservations.folios.payments.receipt', ['folio' => $setup['folio']->id, 'payment' => $one->id]);

    $this->actingAs($setup['user'])->get($url($refund))->assertNotFound();
    $this->actingAs(fpStaff($setup, DefaultRoleEnum::Housekeeping))->get($url($payment))->assertForbidden();
    $this->actingAs(fpStaff($setup, DefaultRoleEnum::FrontDesk))->get($url($payment))->assertOk();

    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = Payment::factory()->create(['folio_id' => Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id])->id]);

    $this->actingAs($setup['user'])->get($url($foreign))->assertNotFound();
    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::Owner)->create())->get($url($payment))->assertNotFound();
});
