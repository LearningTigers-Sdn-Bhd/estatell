<?php

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\ReopenFolio;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an Active company with 30-day terms and an Open Master folio paid by it
 * that holds one 100.00 charge. The DIRECT_BILL code exists.
 *
 * @return array{property: Property, actor: User, company: Company, folio: Folio}
 */
function cityLedgerSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    $company = Company::factory()->for($property)->create(['payment_terms_days' => 30]);
    $folio = Folio::factory()->master()->create(['property_id' => $property->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '100.00', 'unit_amount' => '100.00', 'posting_date' => today()->toDateString()]);

    return ['property' => $property, 'actor' => User::factory()->create(['property_id' => $property->id]), 'company' => $company, 'folio' => $folio];
}

function settle(array $setup): ArInvoiceResult
{
    return app(SettleToCityLedger::class)($setup['actor'], $setup['folio']);
}

test('it posts the City ledger line, closes the folio and makes the invoice', function () {
    $setup = cityLedgerSetup();

    $result = settle($setup);

    $folio = $setup['folio']->fresh();
    $line = FolioLine::query()->where('idempotency_key', 'city-ledger:'.$folio->id)->sole();
    $invoice = $result->invoice;

    expect($result->isRefused())->toBeFalse()
        ->and($line->amount)->toBe('-100.00')
        ->and($line->transactionCode->system_key)->toBe(SystemTransactionCode::DirectBillPayment->value)
        ->and($folio->status)->toBe(FolioStatus::Closed)
        ->and($folio->invoice_number)->not->toBeNull()
        ->and($invoice->number)->toBe($folio->invoice_number)
        ->and($invoice->type)->toBe(ArInvoiceType::Invoice)
        ->and($invoice->status)->toBe(ArInvoiceStatus::Open)
        ->and($invoice->company_id)->toBe($setup['company']->id)
        ->and($invoice->folio_id)->toBe($folio->id)
        ->and($invoice->amount)->toBe('100.00')
        ->and($invoice->issued_on->toDateString())->toBe(BusinessDate::query()->sole()->date->toDateString())
        ->and($invoice->due_on->toDateString())->toBe($invoice->issued_on->addDays(30)->toDateString())
        ->and(Activity::query()->where('event', 'ar_invoice.created')->sole()->subject_id)->toBe($setup['company']->id);
});

test('a company without payment terms is due the same day', function () {
    $setup = cityLedgerSetup();
    $setup['company']->update(['payment_terms_days' => null]);

    $invoice = settle($setup)->invoice;

    expect($invoice->due_on->toDateString())->toBe($invoice->issued_on->toDateString());
});

test('it refuses and writes nothing when the folio cannot be settled', function (string $refusal, Closure $break) {
    $setup = cityLedgerSetup();
    $break($setup);
    $lines = FolioLine::query()->count();

    $result = settle($setup);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(ArInvoice::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'closed folio' => [ArInvoiceResult::REFUSAL_FOLIO_NOT_OPEN, fn (array $setup) => $setup['folio']->update(['status' => FolioStatus::Closed])],
    'no payer' => [ArInvoiceResult::REFUSAL_NO_COMPANY_PAYER, fn (array $setup) => $setup['folio']->update(['payer_company_id' => null])],
    'another property company' => [ArInvoiceResult::REFUSAL_NO_COMPANY_PAYER, fn (array $setup) => $setup['folio']->forceFill(['payer_company_id' => Company::factory()->create()->id])->save()],
    'suspended company' => [ArInvoiceResult::REFUSAL_DIRECT_BILL_NOT_ALLOWED, fn (array $setup) => $setup['company']->update(['status' => 'suspended'])],
    'direct billing off' => [ArInvoiceResult::REFUSAL_DIRECT_BILL_NOT_ALLOWED, fn (array $setup) => $setup['company']->update(['direct_bill_enabled' => false])],
    'nothing owed' => [ArInvoiceResult::REFUSAL_NOTHING_TO_SETTLE, fn (array $setup) => FolioLine::factory()->payment()->create(['folio_id' => $setup['folio']->id, 'posting_date' => today()->toDateString()])],
    'no business date' => [ArInvoiceResult::REFUSAL_NO_BUSINESS_DATE, fn () => BusinessDate::query()->delete()],
]);

test('settling twice makes one invoice', function () {
    $setup = cityLedgerSetup();

    settle($setup);
    $again = settle($setup);

    expect($again->refusal)->toBe(ArInvoiceResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(ArInvoice::query()->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'city-ledger:%')->count())->toBe(1);
});

test('a folio with a live AR invoice cannot reopen, and a void one can', function () {
    $setup = cityLedgerSetup();
    $invoice = settle($setup)->invoice;

    $refused = app(ReopenFolio::class)($setup['actor'], $setup['folio']);

    expect($refused->refusal)->toBe(FolioStatusResult::REFUSAL_HAS_AR_INVOICE)
        ->and($setup['folio']->fresh()->status)->toBe(FolioStatus::Closed);

    $invoice->update(['status' => ArInvoiceStatus::Void]);

    expect(app(ReopenFolio::class)($setup['actor'], $setup['folio'])->isRefused())->toBeFalse();
});

test('the database keeps one live invoice for a folio and rejects a badly shaped one', function () {
    $setup = cityLedgerSetup();
    $invoice = settle($setup)->invoice;

    expect(fn () => DB::transaction(fn () => ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $setup['folio']->id, 'company_id' => $setup['company']->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '-5.00'])))
        ->toThrow(QueryException::class);

    $invoice->update(['status' => ArInvoiceStatus::Void]);

    expect(ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $setup['folio']->id, 'company_id' => $setup['company']->id, 'number' => $invoice->number])->exists)->toBeTrue();
});

test('the City ledger permissions go to Owner, Manager and Finance only', function (DefaultRoleEnum $role, bool $granted) {
    expect(in_array(PermissionEnum::CityLedgerInvoice, $role->permissions(), true))->toBe($granted)
        ->and(in_array(PermissionEnum::CityLedgerCreditNote, $role->permissions(), true))->toBe($granted);
})->with([
    [DefaultRoleEnum::Owner, true],
    [DefaultRoleEnum::Manager, true],
    [DefaultRoleEnum::Finance, true],
    [DefaultRoleEnum::FrontDesk, false],
    [DefaultRoleEnum::Housekeeping, false],
]);
