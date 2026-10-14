<?php

use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\ArPaymentResult;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\ReverseAllocation;
use App\Actions\CityLedger\VoidArPayment;
use App\Data\CityLedger\NewArPaymentData;
use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentMethodType;
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
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an actor, a company, a 300.00 invoice and a 300.00 payment fully allocated to it.
 *
 * @return array{property: Property, actor: User, company: Company, invoice: ArInvoice, payment: ArPayment, allocation: ArAllocation}
 */
function reversalSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $actor = User::factory()->create(['property_id' => $property->id]);
    $company = Company::factory()->for($property)->create();
    $method = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]);
    $invoice = ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '300.00']);
    $payment = app(RecordArPayment::class)($actor, $company, NewArPaymentData::from(['paymentMethodId' => $method->id, 'amount' => '300.00', 'idempotencyKey' => fake()->uuid()]))->payment;
    $allocation = app(AllocateArPayment::class)($actor, $payment, [$invoice->id => '300.00'])->allocations[0];

    return compact('property', 'actor', 'company', 'invoice', 'payment', 'allocation');
}

test('reversing an allocation frees the money and reopens a settled invoice', function () {
    $setup = reversalSetup();

    expect($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Settled);

    $result = app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], ' Wrong invoice ');

    $allocation = $setup['allocation']->fresh();
    $activity = Activity::query()->where('event', 'ar_allocation.reversed')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($allocation->reversed_at)->not->toBeNull()
        ->and($allocation->reversed_by)->toBe($setup['actor']->id)
        ->and($allocation->reversal_reason)->toBe('Wrong invoice')
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Open)
        ->and(app(ArInvoiceBalance::class)->forInvoice($setup['invoice']->fresh()))->toBe('300.00')
        ->and(app(ArPaymentBalance::class)->unallocated($setup['payment']))->toBe('300.00')
        ->and($activity->subject_id)->toBe($setup['company']->id)
        ->and($activity->properties['reason'])->toBe('Wrong invoice');
});

test('a reversed allocation can be allocated again', function () {
    $setup = reversalSetup();
    app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], 'Mistake');

    $again = app(AllocateArPayment::class)($setup['actor'], $setup['payment'], [$setup['invoice']->id => '300.00']);

    expect($again->isRefused())->toBeFalse()
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Settled);
});

test('it refuses to reverse without a reason or twice and writes nothing', function (string $refusal, string $reason, bool $reverseFirst) {
    $setup = reversalSetup();

    if ($reverseFirst) {
        app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], 'First');
    }

    Activity::query()->delete();
    $before = $setup['allocation']->fresh()->reversal_reason;

    $result = app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], $reason);

    expect($result->refusal)->toBe($refusal)
        ->and($setup['allocation']->fresh()->reversal_reason)->toBe($before)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'no reason' => [ArPaymentResult::REFUSAL_REASON_REQUIRED, '   ', false],
    'already reversed' => [ArPaymentResult::REFUSAL_ALREADY_REVERSED, 'Again', true],
]);

test('a payment with no active allocations can be voided', function () {
    $setup = reversalSetup();
    app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], 'Mistake');

    $result = app(VoidArPayment::class)($setup['actor'], $setup['payment']);

    $payment = $setup['payment']->fresh();

    expect($result->isRefused())->toBeFalse()
        ->and($payment->voided_at)->not->toBeNull()
        ->and($payment->voided_by)->toBe($setup['actor']->id)
        ->and(app(ArPaymentBalance::class)->unallocated($payment))->toBe('0.00')
        ->and(Activity::query()->where('event', 'ar_payment.voided')->sole()->properties['receipt_number'])->toBe($payment->receipt_number);
});

test('it refuses to void a payment that has allocations or is already void', function (string $refusal, bool $voidFirst) {
    $setup = reversalSetup();

    if ($voidFirst) {
        app(ReverseAllocation::class)($setup['actor'], $setup['allocation'], 'Mistake');
        app(VoidArPayment::class)($setup['actor'], $setup['payment']);
    }

    Activity::query()->delete();

    $result = app(VoidArPayment::class)($setup['actor'], $setup['payment']);

    expect($result->refusal)->toBe($refusal)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'has allocations' => [ArPaymentResult::REFUSAL_HAS_ALLOCATIONS, false],
    'already void' => [ArPaymentResult::REFUSAL_ALREADY_VOID, true],
]);
