<?php

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\IssueCreditNote;
use App\Actions\CityLedger\VoidArInvoice;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an actor and an Open invoice of 500.00.
 *
 * @return array{property: Property, actor: User, invoice: ArInvoice}
 */
function arSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'invoice' => ArInvoice::factory()->create(['property_id' => $property->id]),
    ];
}

function credit(array $setup, string $amount = '100.00', string $reason = 'Wrong rate'): ArInvoiceResult
{
    return app(IssueCreditNote::class)($setup['actor'], $setup['invoice'], $amount, $reason);
}

function invoiceBalanceOf(ArInvoice $invoice): string
{
    return app(ArInvoiceBalance::class)->forInvoice($invoice->fresh());
}

test('a credit note reduces the balance and takes its own number', function () {
    $setup = arSetup();

    $result = credit($setup);
    $note = $result->invoice;
    $activity = Activity::query()->where('event', 'ar_invoice.credit_note_issued')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($note->type)->toBe(ArInvoiceType::CreditNote)
        ->and($note->amount)->toBe('-100.00')
        ->and($note->credit_for_invoice_id)->toBe($setup['invoice']->id)
        ->and($note->status)->toBe(ArInvoiceStatus::Settled)
        ->and($note->reason)->toBe('Wrong rate')
        ->and($note->number)->not->toBe($setup['invoice']->number)
        ->and($note->due_on)->toBeNull()
        ->and($note->folio_id)->toBeNull()
        ->and(invoiceBalanceOf($setup['invoice']))->toBe('400.00')
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Open)
        ->and($activity->causer_id)->toBe($setup['actor']->id)
        ->and($activity->properties['amount'])->toBe('-100.00');
});

test('credit notes get consecutive numbers and a full credit settles the invoice', function () {
    $setup = arSetup();

    $first = credit($setup, '200.00')->invoice;
    $second = credit($setup, '300.00')->invoice;

    expect($first->number)->not->toBe($second->number)
        ->and(invoiceBalanceOf($setup['invoice']))->toBe('0.00')
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Settled);
});

test('it refuses and writes nothing for a bad credit note', function (string $refusal, string $amount, string $reason, ?Closure $break) {
    $setup = arSetup();
    $break?->__invoke($setup);

    $result = credit($setup, $amount, $reason);

    expect($result->refusal)->toBe($refusal)
        ->and(ArInvoice::query()->where('type', ArInvoiceType::CreditNote)->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'more than the balance' => [ArInvoiceResult::REFUSAL_CREDIT_EXCEEDS_BALANCE, '500.01', 'Why', null],
    'zero' => [ArInvoiceResult::REFUSAL_AMOUNT_INVALID, '0', 'Why', null],
    'three decimals' => [ArInvoiceResult::REFUSAL_AMOUNT_INVALID, '1.005', 'Why', null],
    'not a number' => [ArInvoiceResult::REFUSAL_AMOUNT_INVALID, 'abc', 'Why', null],
    'no reason' => [ArInvoiceResult::REFUSAL_REASON_REQUIRED, '10.00', '   ', null],
    'void invoice' => [ArInvoiceResult::REFUSAL_NOT_AN_OPEN_INVOICE, '10.00', 'Why', fn (array $setup) => $setup['invoice']->update(['status' => ArInvoiceStatus::Void])],
    'no business date' => [ArInvoiceResult::REFUSAL_NO_BUSINESS_DATE, '10.00', 'Why', fn () => BusinessDate::query()->delete()],
]);

test('a credit note cannot be credited', function () {
    $setup = arSetup();
    $note = credit($setup)->invoice;

    $result = app(IssueCreditNote::class)($setup['actor'], $note, '10.00', 'Why');

    expect($result->refusal)->toBe(ArInvoiceResult::REFUSAL_NOT_AN_OPEN_INVOICE);
});

test('an Open invoice with no credit notes can be voided and its number is free again', function () {
    $setup = arSetup();

    $result = app(VoidArInvoice::class)($setup['actor'], $setup['invoice']);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Void)
        ->and(Activity::query()->where('event', 'ar_invoice.voided')->sole()->properties['number'])->toBe($setup['invoice']->number)
        ->and(ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['invoice']->company_id, 'number' => $setup['invoice']->number])->exists)->toBeTrue();
});

test('it refuses to void an invoice that has a credit note, is settled or is already void', function (string $case) {
    $setup = arSetup();

    match ($case) {
        'credit note' => credit($setup),
        'settled' => $setup['invoice']->update(['status' => ArInvoiceStatus::Settled]),
        'void' => $setup['invoice']->update(['status' => ArInvoiceStatus::Void]),
    };
    Activity::query()->delete();

    $result = app(VoidArInvoice::class)($setup['actor'], $setup['invoice']);

    expect($result->refusal)->toBe($case === 'void' ? ArInvoiceResult::REFUSAL_ALREADY_VOID : ArInvoiceResult::REFUSAL_NOT_VOIDABLE)
        ->and(Activity::query()->count())->toBe(0);
})->with(['credit note', 'settled', 'void']);

test('voiding a credit note reopens a settled invoice and frees the credit', function () {
    $setup = arSetup();
    $note = credit($setup, '500.00')->invoice;

    expect($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Settled);

    $result = app(VoidArInvoice::class)($setup['actor'], $note);

    expect($result->isRefused())->toBeFalse()
        ->and($note->fresh()->status)->toBe(ArInvoiceStatus::Void)
        ->and($setup['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Open)
        ->and(invoiceBalanceOf($setup['invoice']))->toBe('500.00')
        ->and(credit($setup, '500.00')->isRefused())->toBeFalse();
});
