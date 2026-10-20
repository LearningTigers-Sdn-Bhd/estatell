<?php

use App\Actions\CityLedger\AddOldSystemCredit;
use App\Actions\CityLedger\AddOldSystemInvoice;
use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\ArPaymentResult;
use App\Enums\ArInvoiceStatus;
use App\Enums\CompanyStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
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
use App\Services\CityLedger\CreditExposure;
use App\Services\CityLedger\StatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A property that went live on 2026-10-10 and trades on 2026-10-14 (the frozen day) with a 60 day migration window, a
 * Manager, an Active company with a 1,000.00 credit limit and the Old system payment method.
 *
 * @return array{property: Property, user: User, company: Company}
 */
function oldBalanceSetup(int $windowDays = 60, DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['migration_window_days' => $windowDays]);
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-10']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::OldSystem, 'name' => 'Old system']);

    return ['property' => $property, 'user' => $user, 'company' => Company::factory()->for($property)->create(['credit_limit' => '1000.00'])];
}

/**
 * @param  array{property: Property, user: User, company: Company}  $setup
 */
function addOldInvoice(array $setup, string $number = 'INV-2026-0912', string $issuedOn = '2026-09-12', string $dueOn = '2026-10-12', string $amount = '4850.00'): ArInvoiceResult
{
    return app(AddOldSystemInvoice::class)($setup['user'], $setup['company'], $number, CarbonImmutable::parse($issuedOn), CarbonImmutable::parse($dueOn), $amount, 'Migrated from old PMS');
}

/**
 * @param  array{property: Property, user: User, company: Company}  $setup
 */
function addOldCredit(array $setup, string $reference = 'OR-2026-0345', string $receivedOn = '2026-09-05', string $amount = '2000.00', ?string $key = null): ArPaymentResult
{
    return app(AddOldSystemCredit::class)($setup['user'], $setup['company'], $reference, CarbonImmutable::parse($receivedOn), $amount, 'Advance paid in old PMS', $key ?? fake()->uuid());
}

function oldBalanceUrl(array $setup, string $kind): string
{
    return "/p/{$setup['property']->code}/finance/city-ledger/companies/{$setup['company']->id}/old-system/{$kind}";
}

test('an old invoice is an open migrated invoice with no folio and counts in the exposure', function () {
    $setup = oldBalanceSetup();

    $result = addOldInvoice($setup);
    $invoice = $result->invoice;

    expect($result->isRefused())->toBeFalse()
        ->and($invoice->migrated)->toBeTrue()
        ->and($invoice->folio_id)->toBeNull()
        ->and($invoice->status)->toBe(ArInvoiceStatus::Open)
        ->and($invoice->number)->toBe('INV-2026-0912')
        ->and($invoice->issued_on->toDateString())->toBe('2026-09-12')
        ->and($invoice->due_on->toDateString())->toBe('2026-10-12')
        ->and($invoice->amount)->toBe('4850.00')
        ->and(app(ArInvoiceBalance::class)->forInvoice($invoice))->toBe('4850.00')
        ->and(app(CreditExposure::class)->forCompany($setup['company'])['openAr'])->toBe('4850.00');

    $log = Activity::query()->where('event', 'city_ledger.old_invoice_added')->sole();
    expect($log->subject_id)->toBe($setup['company']->id)
        ->and($log->properties['number'])->toBe('INV-2026-0912');
});

test('an old invoice is refused with a code and writes nothing', function (string $code, Closure $attempt) {
    $setup = oldBalanceSetup();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'number' => 'TAKEN-1']);

    $result = $attempt($setup);

    expect($result->refusal)->toBe($code)
        ->and(ArInvoice::query()->where('migrated', true)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'city_ledger.old_invoice_added')->count())->toBe(0);
})->with([
    'issued on go-live' => ['issued_after_go_live', fn (array $setup) => addOldInvoice($setup, issuedOn: '2026-10-10', dueOn: '2026-10-20')],
    'due before issue' => ['due_before_issue', fn (array $setup) => addOldInvoice($setup, issuedOn: '2026-09-12', dueOn: '2026-09-11')],
    'zero amount' => ['amount_invalid', fn (array $setup) => addOldInvoice($setup, amount: '0.00')],
    'three decimals' => ['amount_invalid', fn (array $setup) => addOldInvoice($setup, amount: '10.123')],
    'number taken' => ['number_taken', fn (array $setup) => addOldInvoice($setup, number: 'TAKEN-1')],
    'empty number' => ['number_required', fn (array $setup) => addOldInvoice($setup, number: '  ')],
]);

test('an old invoice needs an open window and an active company', function () {
    $closed = oldBalanceSetup(0);
    $suspended = oldBalanceSetup();
    $suspended['company']->update(['status' => CompanyStatus::Suspended]);

    expect(addOldInvoice($closed)->refusal)->toBe('migration_window_closed')
        ->and(addOldInvoice($suspended)->refusal)->toBe('company_not_available')
        ->and(addOldCredit($closed)->refusal)->toBe('migration_window_closed')
        ->and(addOldCredit($suspended)->refusal)->toBe('company_not_available');
});

test('a number used by a void invoice can be entered again', function () {
    $setup = oldBalanceSetup();
    ArInvoice::factory()->void()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'number' => 'INV-2026-0912']);

    expect(addOldInvoice($setup)->isRefused())->toBeFalse();
});

test('old credit is an unallocated Old system payment with no receipt number', function () {
    $setup = oldBalanceSetup();

    $result = addOldCredit($setup);
    $payment = $result->payment;

    expect($result->isRefused())->toBeFalse()
        ->and($payment->receipt_number)->toBeNull()
        ->and($payment->reference)->toBe('OR-2026-0345')
        ->and($payment->received_on->toDateString())->toBe('2026-09-05')
        ->and($payment->paymentMethod->type)->toBe(PaymentMethodType::OldSystem)
        ->and($payment->displayNumber())->toBe('Old system · OR-2026-0345')
        ->and(app(ArPaymentBalance::class)->unallocated($payment))->toBe('2000.00')
        ->and(Activity::query()->where('event', 'city_ledger.old_credit_added')->count())->toBe(1);
});

test('old credit repeated with the same key returns the same payment', function () {
    $setup = oldBalanceSetup();
    $key = fake()->uuid();

    $first = addOldCredit($setup, key: $key);
    $second = addOldCredit($setup, key: $key);

    expect($second->replayed)->toBeTrue()
        ->and($second->payment->id)->toBe($first->payment->id)
        ->and(ArPayment::query()->count())->toBe(1);
});

test('old credit is refused for a date on or after go-live, a bad amount or no reference', function () {
    $setup = oldBalanceSetup();

    expect(addOldCredit($setup, receivedOn: '2026-10-10')->refusal)->toBe('date_not_before_go_live')
        ->and(addOldCredit($setup, amount: '-5.00')->refusal)->toBe('amount_invalid')
        ->and(addOldCredit($setup, reference: ' ')->refusal)->toBe('reference_required')
        ->and(ArPayment::query()->count())->toBe(0);
});

test('a payment without a receipt number needs a reference', function () {
    $setup = oldBalanceSetup();
    $payment = addOldCredit($setup)->payment;

    expect(fn () => DB::table('ar_payments')->where('id', $payment->id)->update(['reference' => null]))->toThrow(QueryException::class);
});

test('the routes need folio.manual and add the balance', function () {
    $front = oldBalanceSetup(role: DefaultRoleEnum::FrontDesk);
    $setup = oldBalanceSetup();
    $invoiceBody = ['number' => 'INV-77', 'issued_on' => '2026-09-12', 'due_on' => '2026-10-12', 'amount' => '850.00', 'reason' => 'Migrated from old PMS'];
    $creditBody = ['reference' => 'OR-9', 'received_on' => '2026-09-05', 'amount' => '300.00', 'reason' => 'Advance', 'idempotency_key' => fake()->uuid()];

    $this->actingAs($front['user'])->post(oldBalanceUrl($front, 'invoices'), $invoiceBody)->assertForbidden();
    $this->actingAs($front['user'])->post(oldBalanceUrl($front, 'credits'), $creditBody)->assertForbidden();

    $this->actingAs($setup['user'])->post(oldBalanceUrl($setup, 'invoices'), $invoiceBody)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Old invoice INV-77 added.']);
    $this->actingAs($setup['user'])->post(oldBalanceUrl($setup, 'credits'), $creditBody)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Old credit OR-9 added.']);

    expect(ArInvoice::query()->where('migrated', true)->count())->toBe(1)
        ->and(ArPayment::query()->count())->toBe(1);
});

test('a refusal goes back as an error on the field', function () {
    $setup = oldBalanceSetup();
    $body = ['number' => 'INV-77', 'issued_on' => '2026-10-10', 'due_on' => '2026-10-20', 'amount' => '850.00', 'reason' => 'x'];

    $this->actingAs($setup['user'])->post(oldBalanceUrl($setup, 'invoices'), $body)
        ->assertSessionHasErrors(['issued_on' => 'The old invoice must be issued before the go-live date.']);

    expect(ArInvoice::query()->count())->toBe(0);
});

test('another property company is a 404', function () {
    $setup = oldBalanceSetup();
    $other = oldBalanceSetup();

    $this->actingAs($setup['user'])->post("/p/{$setup['property']->code}/finance/city-ledger/companies/{$other['company']->id}/old-system/invoices", [])->assertNotFound();
});

test('the company page offers the menu only inside the window to staff with folio.manual', function () {
    $setup = oldBalanceSetup();
    $closed = oldBalanceSetup(0);
    $front = oldBalanceSetup(role: DefaultRoleEnum::FrontDesk);
    $url = fn (array $s) => "/p/{$s['property']->code}/finance/city-ledger/companies/{$s['company']->id}";

    $this->actingAs($setup['user'])->get($url($setup))->assertInertia(fn (Assert $page) => $page->where('canAddOldSystem', true));
    $this->actingAs($closed['user'])->get($url($closed))->assertInertia(fn (Assert $page) => $page->where('canAddOldSystem', false));
    $this->actingAs($front['user'])->get($url($front))->assertInertia(fn (Assert $page) => $page->where('canAddOldSystem', false));
});

test('the invoice page marks it migrated and the PDF is a 404', function () {
    $setup = oldBalanceSetup();
    $invoice = addOldInvoice($setup)->invoice;
    $base = "/p/{$setup['property']->code}/finance/city-ledger/invoices/{$invoice->id}";

    $this->actingAs($setup['user'])->get($base)->assertInertia(fn (Assert $page) => $page->where('invoice.row.migrated', true));
    $this->actingAs($setup['user'])->get("{$base}/pdf")->assertNotFound();
});

test('the receipt of an old credit is a 404 and the payments tab says there is none', function () {
    $setup = oldBalanceSetup();
    $payment = addOldCredit($setup)->payment;
    $base = "/p/{$setup['property']->code}/finance/city-ledger/companies/{$setup['company']->id}";

    $this->actingAs($setup['user'])->get("{$base}/payments/{$payment->id}/receipt")->assertNotFound();
    $this->actingAs($setup['user'])->get("{$base}/payments")->assertInertia(fn (Assert $page) => $page
        ->where('payments.0.receiptNumber', 'Old system · OR-2026-0345')
        ->where('payments.0.hasReceipt', false));
});

test('the statement and aging use the real dates and label the old documents', function () {
    $setup = oldBalanceSetup();
    addOldInvoice($setup);
    addOldCredit($setup);

    $statement = app(StatementBuilder::class)->build($setup['company'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-14'));

    expect(array_map(fn ($line) => [$line->date, $line->kind, $line->number, $line->amount, $line->balance], $statement->lines))->toBe([
        ['2026-09-05', 'payment', 'Old system · OR-2026-0345', '-2000.00', '-2000.00'],
        ['2026-09-12', 'invoice', 'Old system · INV-2026-0912', '4850.00', '2850.00'],
    ])->and($statement->closingBalance)->toBe('2850.00');
});
