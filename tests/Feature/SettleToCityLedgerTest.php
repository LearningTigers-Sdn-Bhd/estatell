<?php

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\ReopenFolio;
use App\Actions\Folio\VoidInvoiceAndReopen;
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
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDetail;
use App\Services\CityLedger\ArInvoicePrinter;
use App\Services\CityLedger\ArInvoiceRefusals;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\InHouseUnits;
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
    $line = FolioLine::query()->where('idempotency_key', 'city-ledger:'.$folio->id.':1')->sole();
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
    'unit in house' => [ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE, fn (array $setup) => ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $setup['folio']->reservation_id])],
]);

test('a Master folio with a unit in house is refused before any line is posted, and the message names the room', function () {
    $setup = cityLedgerSetup();
    ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $setup['folio']->reservation_id]);

    $result = settle($setup);

    expect($result->refusal)->toBe(ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE)
        ->and($result->rooms)->toHaveCount(1)
        ->and(app(ArInvoiceRefusals::class)->message((string) $result->refusal, ['Room 103']))->toBe('Room 103 is still in-house. Check out all units first.')
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'city-ledger:%')->count())->toBe(0);
});

test('a third-party folio with a company payer settles while a unit is in house', function () {
    $setup = cityLedgerSetup();
    ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $setup['folio']->reservation_id]);
    $folio = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id, 'payer_company_id' => $setup['company']->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '40.00', 'unit_amount' => '40.00', 'posting_date' => today()->toDateString()]);

    $result = app(SettleToCityLedger::class)($setup['actor'], $folio);

    expect($result->isRefused())->toBeFalse()
        ->and($result->invoice->amount)->toBe('40.00')
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed);
});

test('the settle route shows the in-house refusal as an error toast', function () {
    $setup = cityLedgerSetup();
    ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $setup['folio']->reservation_id]);
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($owner)
        ->post(route('reservations.folios.city-ledger', ['property' => $setup['property']->code, 'reservation' => $setup['folio']->reservation_id, 'folio' => $setup['folio']->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => app(ArInvoiceRefusals::class)->message(ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE, app(InHouseUnits::class)->roomsFor($setup['folio']))]);

    expect(ArInvoice::query()->count())->toBe(0);
});

test('settling a company over its limit is not refused, issues the invoice and logs no credit override', function () {
    $setup = cityLedgerSetup();
    $setup['company']->update(['credit_limit' => '10.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '600.00']);

    $result = settle($setup);

    expect($result->isRefused())->toBeFalse()
        ->and($result->invoice->amount)->toBe('100.00')
        ->and($setup['folio']->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'credit.overridden')->count())->toBe(0);
});

test('the folio tab carries the over-limit warning for Settle, and none under the limit or for a user without city-ledger.invoice', function () {
    $setup = cityLedgerSetup();
    $setup['company']->update(['name' => 'Acme Sdn Bhd', 'credit_limit' => '500.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '500.00']);
    $currency = PropertySetting::query()->where('property_id', $setup['property']->id)->sole()->currency->value;
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['folio']->reservation_id, 'folio' => $setup['folio']->id]);

    $this->actingAs($owner)->get($url)->assertInertia(fn ($page) => $page
        ->where('folio.canSettleToCityLedger', true)
        ->where('folio.settleCreditWarning', 'Acme Sdn Bhd is over its credit limit by '.$currency.' 100.00. The invoice is still issued.'));

    $this->actingAs($frontDesk)->get($url)->assertInertia(fn ($page) => $page
        ->where('folio.canSettleToCityLedger', false)
        ->where('folio.settleCreditWarning', null));

    $setup['company']->update(['credit_limit' => '5000.00']);

    $this->actingAs($owner)->get($url)->assertInertia(fn ($page) => $page->where('folio.settleCreditWarning', null));
});

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

test('void and reopen leaves the balance owed, and the folio settles again with the same invoice number', function () {
    $setup = cityLedgerSetup();
    $first = settle($setup)->invoice;
    $folio = $setup['folio']->fresh();

    $result = app(VoidInvoiceAndReopen::class)($setup['actor'], $folio);
    $cityLine = FolioLine::query()->where('idempotency_key', 'city-ledger:'.$folio->id.':1')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('reversal_of_id', $cityLine->id)->sole()->amount)->toBe('100.00')
        ->and(app(FolioBalance::class)->forFolio($folio->fresh()))->toBe('100.00');

    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '20.00', 'unit_amount' => '20.00', 'posting_date' => today()->toDateString()]);

    $second = settle($setup)->invoice;

    expect($second->number)->toBe($first->number)
        ->and($second->amount)->toBe('120.00')
        ->and($first->fresh()->status)->toBe(ArInvoiceStatus::Void)
        ->and(ArInvoice::query()->where('folio_id', $folio->id)->where('status', '<>', ArInvoiceStatus::Void)->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'city-ledger:'.$folio->id.':2')->exists())->toBeTrue();

    $lines = collect(app(ArInvoiceDetail::class)->lines($second))->pluck('amount')->all();
    $document = app(ArInvoicePrinter::class)->forInvoice($second);

    expect($lines)->toBe(['100.00', '20.00'])
        ->and($document->payments)->toBe([])
        ->and($document->paid)->toBe('0.00');
});

test('the folio says its invoice is void until it settles again', function () {
    $setup = cityLedgerSetup();
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);
    settle($setup);
    $folio = $setup['folio']->fresh();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    $this->actingAs($user)->get($url)->assertInertia(fn ($page) => $page->where('folio.invoiceVoided', false));

    app(VoidInvoiceAndReopen::class)($setup['actor'], $folio);

    $this->get($url)->assertInertia(fn ($page) => $page->where('folio.invoiceVoided', true));

    settle($setup);

    $this->get($url)->assertInertia(fn ($page) => $page->where('folio.invoiceVoided', false));
});
