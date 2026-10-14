<?php

use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\ArPaymentResult;
use App\Actions\CityLedger\AutoAllocateArPayment;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\VoidArInvoice;
use App\Data\CityLedger\NewArPaymentData;
use App\Enums\ArInvoiceStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an actor, a company and a Card payment method.
 *
 * @return array{property: Property, actor: User, company: Company, method: PaymentMethod}
 */
function arPaySetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'company' => Company::factory()->for($property)->create(),
        'method' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function arPayData(array $setup, array $overrides = []): NewArPaymentData
{
    return NewArPaymentData::from([
        'paymentMethodId' => $setup['method']->id,
        'amount' => '500.00',
        'idempotencyKey' => fake()->uuid(),
        'reference' => 'TRF-1',
        ...$overrides,
    ]);
}

function arInvoice(array $setup, string $amount, string $dueOn, array $overrides = []): ArInvoice
{
    return ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => $amount, 'due_on' => $dueOn, ...$overrides]);
}

function arRecord(array $setup, array $overrides = [], bool $allocate = false): ArPaymentResult
{
    return app(RecordArPayment::class)($setup['actor'], $setup['company'], arPayData($setup, $overrides), $allocate);
}

test('it records a payment with a receipt number and logs it', function () {
    $setup = arPaySetup();

    $result = arRecord($setup, ['notes' => ' Paid by cheque ']);
    $payment = $result->payment;
    $activity = Activity::query()->where('event', 'ar_payment.recorded')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($payment->amount)->toBe('500.00')
        ->and($payment->company_id)->toBe($setup['company']->id)
        ->and($payment->property_id)->toBe($setup['property']->id)
        ->and($payment->received_on->toDateString())->toBe(BusinessDate::query()->sole()->date->toDateString())
        ->and($payment->reference)->toBe('TRF-1')
        ->and($payment->notes)->toBe('Paid by cheque')
        ->and($payment->receipt_number)->not->toBeEmpty()
        ->and($payment->received_by)->toBe($setup['actor']->id)
        ->and(app(ArPaymentBalance::class)->unallocated($payment))->toBe('500.00')
        ->and($activity->subject_id)->toBe($setup['company']->id)
        ->and($activity->properties['receipt_number'])->toBe($payment->receipt_number);
});

test('receipt numbers run in one series and a repeated key returns the same payment', function () {
    $setup = arPaySetup();
    $first = arRecord($setup, ['idempotencyKey' => 'key-1']);
    $again = arRecord($setup, ['idempotencyKey' => 'key-1']);
    $second = arRecord($setup);

    expect($again->replayed)->toBeTrue()
        ->and($again->payment->id)->toBe($first->payment->id)
        ->and(ArPayment::query()->count())->toBe(2)
        ->and($second->payment->receipt_number)->not->toBe($first->payment->receipt_number);
});

test('it takes an earlier received date and refuses a future one', function () {
    $setup = arPaySetup();
    $businessDate = CarbonImmutable::parse(BusinessDate::query()->sole()->date->toDateString());

    $earlier = arRecord($setup, ['receivedOn' => $businessDate->subDays(3)]);

    expect($earlier->payment->received_on->toDateString())->toBe($businessDate->subDays(3)->toDateString())
        ->and(arRecord($setup, ['receivedOn' => $businessDate->addDay()])->refusal)->toBe(ArPaymentResult::REFUSAL_DATE_IN_FUTURE);
});

test('it refuses and writes nothing for a bad payment', function (string $refusal, array $overrides, ?Closure $break) {
    $setup = arPaySetup();
    $break?->__invoke($setup);

    $result = arRecord($setup, $overrides);

    expect($result->refusal)->toBe($refusal)
        ->and(ArPayment::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'zero amount' => [ArPaymentResult::REFUSAL_AMOUNT_INVALID, ['amount' => '0'], null],
    'three decimals' => [ArPaymentResult::REFUSAL_AMOUNT_INVALID, ['amount' => '1.005'], null],
    'not a number' => [ArPaymentResult::REFUSAL_AMOUNT_INVALID, ['amount' => 'abc'], null],
    'unknown method' => [ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, ['paymentMethodId' => 999999], null],
    'inactive method' => [ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, [], fn (array $setup) => $setup['method']->update(['active' => false])],
    'city ledger method' => [ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, [], fn (array $setup) => $setup['method']->update(['type' => PaymentMethodType::DirectBill])],
    'ota method' => [ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE, [], fn (array $setup) => $setup['method']->update(['type' => PaymentMethodType::OtaCollected])],
    'reference required' => [ArPaymentResult::REFUSAL_REFERENCE_REQUIRED, ['reference' => '  '], fn (array $setup) => $setup['method']->update(['requires_reference' => true])],
    'no business date' => [ArPaymentResult::REFUSAL_NO_BUSINESS_DATE, [], fn () => BusinessDate::query()->delete()],
]);

test('a method of another property is not available', function () {
    $setup = arPaySetup();
    $foreign = PaymentMethod::factory()->create();

    expect(arRecord($setup, ['paymentMethodId' => $foreign->id])->refusal)->toBe(ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
});

test('it allocates a payment to several invoices and settles the paid ones', function () {
    $setup = arPaySetup();
    $payment = arRecord($setup)->payment;
    $first = arInvoice($setup, '300.00', '2026-11-01');
    $second = arInvoice($setup, '400.00', '2026-11-02');

    $result = app(AllocateArPayment::class)($setup['actor'], $payment, [$first->id => '300.00', $second->id => '150.00']);

    $balance = app(ArInvoiceBalance::class);

    expect($result->isRefused())->toBeFalse()
        ->and($result->allocations)->toHaveCount(2)
        ->and($balance->forInvoice($first->fresh()))->toBe('0.00')
        ->and($first->fresh()->status)->toBe(ArInvoiceStatus::Settled)
        ->and($balance->forInvoice($second->fresh()))->toBe('250.00')
        ->and($second->fresh()->status)->toBe(ArInvoiceStatus::Open)
        ->and(app(ArPaymentBalance::class)->unallocated($payment))->toBe('50.00')
        ->and(Activity::query()->where('event', 'ar_payment.allocated')->sole()->properties['allocations'])->toHaveCount(2);
});

test('it refuses a bad allocation and writes none of its lines', function (string $refusal, Closure $amounts) {
    $setup = arPaySetup();
    $payment = arRecord($setup, ['amount' => '200.00'])->payment;
    $invoice = arInvoice($setup, '300.00', '2026-11-01');
    $other = arInvoice($setup, '100.00', '2026-11-02');
    Activity::query()->delete();

    $result = app(AllocateArPayment::class)($setup['actor'], $payment, $amounts($invoice, $other, $setup));

    expect($result->refusal)->toBe($refusal)
        ->and(ArAllocation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'more than the invoice balance' => [ArPaymentResult::REFUSAL_EXCEEDS_INVOICE_BALANCE, fn ($invoice, $other) => [$other->id => '100.01']],
    'more than unallocated' => [ArPaymentResult::REFUSAL_EXCEEDS_UNALLOCATED, fn ($invoice, $other) => [$invoice->id => '150.00', $other->id => '60.00']],
    'zero' => [ArPaymentResult::REFUSAL_AMOUNT_INVALID, fn ($invoice) => [$invoice->id => '0']],
    'nothing' => [ArPaymentResult::REFUSAL_AMOUNT_INVALID, fn () => []],
    'unknown invoice' => [ArPaymentResult::REFUSAL_INVOICE_NOT_ALLOCATABLE, fn () => [999999 => '10.00']],
    'another company' => [ArPaymentResult::REFUSAL_INVOICE_NOT_ALLOCATABLE, fn ($invoice, $other, $setup) => [ArInvoice::factory()->create(['property_id' => $setup['property']->id])->id => '10.00']],
    'void invoice' => [ArPaymentResult::REFUSAL_INVOICE_NOT_ALLOCATABLE, fn ($invoice, $other) => tap([$other->id => '10.00'], fn () => $other->update(['status' => ArInvoiceStatus::Void]))],
]);

test('a voided payment cannot be allocated', function () {
    $setup = arPaySetup();
    $payment = ArPayment::factory()->voided()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id]);
    $invoice = arInvoice($setup, '100.00', '2026-11-01');

    expect(app(AllocateArPayment::class)($setup['actor'], $payment, [$invoice->id => '10.00'])->refusal)->toBe(ArPaymentResult::REFUSAL_PAYMENT_NOT_ALLOCATABLE)
        ->and(app(AutoAllocateArPayment::class)($setup['actor'], $payment)->refusal)->toBe(ArPaymentResult::REFUSAL_PAYMENT_NOT_ALLOCATABLE);
});

test('auto allocation fills the oldest invoices first and keeps the rest as credit', function () {
    $setup = arPaySetup();
    $newer = arInvoice($setup, '300.00', '2026-12-01');
    $oldest = arInvoice($setup, '200.00', '2026-11-01');
    $sameDue = arInvoice($setup, '100.00', '2026-11-01');
    arInvoice($setup, '100.00', '2026-10-01', ['status' => ArInvoiceStatus::Void]);
    $payment = arRecord($setup, ['amount' => '450.00'])->payment;

    $result = app(AutoAllocateArPayment::class)($setup['actor'], $payment);

    $balance = app(ArInvoiceBalance::class);

    expect($result->allocations)->toHaveCount(3)
        ->and($balance->forInvoice($oldest->fresh()))->toBe('0.00')
        ->and($balance->forInvoice($sameDue->fresh()))->toBe('0.00')
        ->and($balance->forInvoice($newer->fresh()))->toBe('150.00')
        ->and(app(ArPaymentBalance::class)->unallocated($payment))->toBe('0.00');

    $bigger = arRecord($setup, ['amount' => '1000.00'])->payment;
    app(AutoAllocateArPayment::class)($setup['actor'], $bigger);

    expect(app(ArPaymentBalance::class)->unallocated($bigger))->toBe('850.00')
        ->and($newer->fresh()->status)->toBe(ArInvoiceStatus::Settled);
});

test('recording with allocate matches the payment in the same transaction', function () {
    $setup = arPaySetup();
    $invoice = arInvoice($setup, '120.00', '2026-11-01');

    $result = arRecord($setup, ['amount' => '200.00'], allocate: true);

    expect($result->allocations)->toHaveCount(1)
        ->and(app(ArInvoiceBalance::class)->forInvoice($invoice->fresh()))->toBe('0.00')
        ->and(app(ArPaymentBalance::class)->unallocated($result->payment))->toBe('80.00');

    $none = arRecord($setup, ['amount' => '10.00'], allocate: true);

    expect($none->allocations)->toBe([])
        ->and(app(ArPaymentBalance::class)->unallocated($none->payment))->toBe('10.00');
});

test('an invoice with an active allocation cannot be voided, but a reversed one can', function () {
    $setup = arPaySetup();
    $invoice = arInvoice($setup, '100.00', '2026-11-01');
    $payment = arRecord($setup, ['amount' => '100.00'])->payment;
    $allocation = ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '40.00']);

    expect(app(VoidArInvoice::class)($setup['actor'], $invoice)->refusal)->not->toBeNull();

    $allocation->update(['reversed_at' => now(), 'reversed_by' => $setup['actor']->id, 'reversal_reason' => 'Mistake']);

    expect(app(VoidArInvoice::class)($setup['actor'], $invoice)->isRefused())->toBeFalse();
});

test('the database rejects a non-positive amount and an allocation reversed without a reason', function () {
    $setup = arPaySetup();
    $invoice = arInvoice($setup, '100.00', '2026-11-01');
    $payment = arRecord($setup)->payment;

    expect(fn () => DB::transaction(fn () => ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '0.00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '-1.00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'reversed_at' => now(), 'reversed_by' => $setup['actor']->id])))->toThrow(QueryException::class);
});

test('the AR payment permissions go to Owner, Manager and Finance only', function (DefaultRoleEnum $role, bool $granted) {
    expect(in_array(PermissionEnum::CityLedgerPayment, $role->permissions(), true))->toBe($granted)
        ->and(in_array(PermissionEnum::CityLedgerAllocate, $role->permissions(), true))->toBe($granted);
})->with([
    [DefaultRoleEnum::Owner, true],
    [DefaultRoleEnum::Manager, true],
    [DefaultRoleEnum::Finance, true],
    [DefaultRoleEnum::FrontDesk, false],
    [DefaultRoleEnum::Housekeeping, false],
]);
