<?php

use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\ReverseAllocation;
use App\Data\CityLedger\NewArPaymentData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date and a user with the role, for the invoice pages.
 *
 * @return array{user: User, property: Property}
 */
function invoicePagesSetup(DefaultRoleEnum $role = DefaultRoleEnum::Finance): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return ['user' => $user, 'property' => $property];
}

function invoiceUrl(array $setup, string $suffix = ''): string
{
    return "/p/{$setup['property']->code}/finance/city-ledger/invoices{$suffix}";
}

test('the list shows invoices with balances and flags the overdue ones', function () {
    $setup = invoicePagesSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Borneo Travel']);
    $overdue = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'number' => 'F-1', 'due_on' => today()->subDays(5)->toDateString(), 'amount' => '500.00']);
    $current = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'number' => 'F-2', 'due_on' => today()->addDays(30)->toDateString()]);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $overdue->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-100.00', 'number' => 'CN-1', 'status' => ArInvoiceStatus::Settled]);
    ArInvoice::factory()->create();

    $this->actingAs($setup['user'])->get(invoiceUrl($setup, '?sort=number'))->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/invoices/index')
        ->has('invoices.data', 3)
        ->where('invoices.meta.total', 3)
        ->where('invoices.meta.current_page', 1)
        ->where('invoices.data.0.number', 'CN-1')
        ->where('invoices.data.0.balance', null)
        ->where('invoices.data.1.number', 'F-1')
        ->where('invoices.data.1.balance', '400.00')
        ->where('invoices.data.1.overdue', true)
        ->where('invoices.data.2.number', 'F-2')
        ->where('invoices.data.2.overdue', false));
});

test('the list filters by search, type and status', function () {
    $setup = invoicePagesSetup();
    $acme = Company::factory()->for($setup['property'])->create(['name' => 'Acme']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $acme->id, 'number' => 'F-10']);
    ArInvoice::factory()->settled()->create(['property_id' => $setup['property']->id, 'number' => 'F-11']);

    $this->actingAs($setup['user']);
    $this->get(invoiceUrl($setup, '?filter[search]=acme'))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', 'F-10'));
    $this->get(invoiceUrl($setup, '?filter[search]=F-11'))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
    $this->get(invoiceUrl($setup, '?filter[status]=settled'))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', 'F-11'));
    $this->get(invoiceUrl($setup, '?filter[type]=credit_note'))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
});

test('the list runs the same queries however many invoices there are', function () {
    $setup = invoicePagesSetup();
    $company = Company::factory()->for($setup['property'])->create();
    ArInvoice::factory()->count(2)->create(['property_id' => $setup['property']->id, 'company_id' => $company->id]);
    $this->actingAs($setup['user']);
    $this->get(invoiceUrl($setup));

    DB::enableQueryLog();
    $this->get(invoiceUrl($setup));
    $few = count(DB::getQueryLog());
    ArInvoice::factory()->count(10)->create(['property_id' => $setup['property']->id, 'company_id' => $company->id]);
    DB::flushQueryLog();
    $this->get(invoiceUrl($setup));

    expect(count(DB::getQueryLog()))->toBe($few);
});

test('the pages need company.view and stay inside the property', function () {
    $setup = invoicePagesSetup(DefaultRoleEnum::Housekeeping);
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);
    $other = ArInvoice::factory()->create();

    $this->actingAs($setup['user'])->get(invoiceUrl($setup))->assertForbidden();
    $this->get(invoiceUrl($setup, "/{$invoice->id}"))->assertForbidden();
    $this->get(invoiceUrl($setup, "/{$invoice->id}/pdf"))->assertForbidden();

    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($finance)->get(invoiceUrl($setup))->assertOk();
    $this->get(invoiceUrl($setup, "/{$other->id}"))->assertNotFound();
    $this->get(invoiceUrl($setup, "/{$other->id}/pdf"))->assertNotFound();
});

test('the invoice page sends its credit notes, lines and what the user may do', function () {
    $setup = invoicePagesSetup();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'description' => 'Room charge', 'posting_date' => today()->toDateString()]);
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id]);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $invoice->company_id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $invoice->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-50.00', 'number' => 'CN-9', 'status' => ArInvoiceStatus::Settled]);

    $this->actingAs($setup['user'])->get(invoiceUrl($setup, "/{$invoice->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/invoices/show')
        ->where('invoice.row.balance', '450.00')
        ->where('invoice.credited', '-50.00')
        ->has('invoice.creditNotes', 1)
        ->where('invoice.creditNotes.0.number', 'CN-9')
        ->has('invoice.lines', 1)
        ->where('invoice.lines.0.description', 'Room charge')
        ->where('invoice.folioNumber', $folio->number)
        ->where('invoice.canCreditNote', true)
        ->where('invoice.canVoid', false));
});

test('front desk can see an invoice but not act on it', function () {
    $setup = invoicePagesSetup(DefaultRoleEnum::FrontDesk);
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])->get(invoiceUrl($setup, "/{$invoice->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('invoice.canCreditNote', false)
        ->where('invoice.canVoid', false));
    $this->post(invoiceUrl($setup, "/{$invoice->id}/credit-notes"), ['amount' => '10.00', 'reason' => 'x'])->assertForbidden();
    $this->post(invoiceUrl($setup, "/{$invoice->id}/void"))->assertForbidden();

    expect($invoice->fresh()->status)->toBe(ArInvoiceStatus::Open);
});

test('the PDF streams for an invoice, a credit note and a void invoice', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);
    $note = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $invoice->company_id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $invoice->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-50.00', 'reason' => 'Wrong rate', 'status' => ArInvoiceStatus::Settled]);
    $void = ArInvoice::factory()->void()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user']);

    foreach ([$invoice, $note, $void] as $document) {
        $response = $this->get(invoiceUrl($setup, "/{$document->id}/pdf"))->assertOk();

        expect($response->headers->get('content-type'))->toContain('application/pdf');
    }
});

test('a credit note is issued from the invoice page and a refusal comes back as a field error', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);
    $url = invoiceUrl($setup, "/{$invoice->id}/credit-notes");

    $this->actingAs($setup['user'])->post($url, ['amount' => '120.00', 'reason' => 'Wrong rate'])->assertRedirect()->assertSessionHasNoErrors();

    expect(ArInvoice::query()->where('type', ArInvoiceType::CreditNote)->sole()->amount)->toBe('-120.00');

    $this->post($url, ['amount' => '9999.00', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
    $this->post($url, ['amount' => '10.00', 'reason' => ''])->assertSessionHasErrors('reason');

    expect(ArInvoice::query()->where('type', ArInvoiceType::CreditNote)->count())->toBe(1);
});

test('an invoice and a credit note are voided with their own permission', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])->post(invoiceUrl($setup, "/{$invoice->id}/void"))->assertRedirect()->assertInertiaFlash('toast', ['type' => 'success', 'message' => "{$invoice->number} voided."]);

    expect($invoice->fresh()->status)->toBe(ArInvoiceStatus::Void);

    $this->post(invoiceUrl($setup, "/{$invoice->id}/void"))->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This document is already void.']);
});

test('settling a folio from its route issues the invoice, and a refusal is an error toast', function () {
    $setup = invoicePagesSetup();
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $setup['property']->id]);
    $company = Company::factory()->for($setup['property'])->create();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => today()->toDateString()]);
    $url = route('reservations.folios.city-ledger', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    $this->actingAs($setup['user'])->post($url)->assertRedirect();

    $invoice = ArInvoice::query()->sole();

    expect($invoice->company_id)->toBe($company->id)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'ar_invoice.created')->count())->toBe(1);

    $this->post($url)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This folio is not open, so it cannot be settled.']);

    expect(ArInvoice::query()->count())->toBe(1);
});

test('settling needs city-ledger.invoice', function () {
    $setup = invoicePagesSetup(DefaultRoleEnum::FrontDesk);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])
        ->post(route('reservations.folios.city-ledger', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]))
        ->assertForbidden();
});

test('the folio tab offers Settle to City ledger only to an Open company folio with a balance', function () {
    $setup = invoicePagesSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Borneo Travel']);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'payer_company_id' => $company->id]);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page->where('folio.canSettleToCityLedger', false));

    FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => today()->toDateString()]);

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('folio.canSettleToCityLedger', true)->where('folio.payerCompanyName', 'Borneo Travel'));

    $folio->update(['payer_company_id' => null]);

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('folio.canSettleToCityLedger', false));
});

test('the sidebar lists Invoices after Companies for users who can view companies', function () {
    $setup = invoicePagesSetup();

    $this->actingAs($setup['user'])->get(invoiceUrl($setup))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.1.groups.0.items.1.title', 'Invoices'));
});

/**
 * Pays an invoice with one AR payment per amount and allocates each in full.
 *
 * @param  list<string>  $amounts
 * @return list<ArAllocation>
 */
function payInvoiceInParts(array $setup, ArInvoice $invoice, array $amounts): array
{
    $company = Company::query()->findOrFail($invoice->company_id);
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]);

    return collect($amounts)->map(function (string $amount) use ($setup, $company, $method, $invoice) {
        $payment = app(RecordArPayment::class)($setup['user'], $company, NewArPaymentData::from(['paymentMethodId' => $method->id, 'amount' => $amount, 'idempotencyKey' => fake()->uuid()]))->payment;

        return app(AllocateArPayment::class)($setup['user'], $payment, [$invoice->id => $amount])->allocations[0];
    })->all();
}

test('a paid invoice still offers a credit note and lists the active payments that block it', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'amount' => '300.00']);
    [$first, $second] = payInvoiceInParts($setup, $invoice, ['100.00', '200.00']);
    app(ReverseAllocation::class)($setup['user'], $first, 'Wrong invoice');

    $this->actingAs($setup['user'])->get(invoiceUrl($setup, "/{$invoice->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('invoice.row.balance', '100.00')
        ->where('invoice.canCreditNote', true)
        ->has('invoice.allocations', 2)
        ->has('invoice.creditBlockedBy', 1)
        ->where('invoice.creditBlockedBy.0.id', $second->id)
        ->where('invoice.creditBlockedBy.0.canReverse', true));

    app(ReverseAllocation::class)($setup['user'], $second, 'Wrong invoice');
    payInvoiceInParts($setup, $invoice, ['300.00']);

    $this->get(invoiceUrl($setup, "/{$invoice->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('invoice.row.balance', '0.00')
        ->where('invoice.canCreditNote', true)
        ->has('invoice.creditBlockedBy', 1));
});

test('credit note is hidden on a void invoice and without the permission', function () {
    $setup = invoicePagesSetup();
    $void = ArInvoice::factory()->void()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])->get(invoiceUrl($setup, "/{$void->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('invoice.canCreditNote', false));

    $frontDesk = invoicePagesSetup(DefaultRoleEnum::FrontDesk);
    $paid = ArInvoice::factory()->create(['property_id' => $frontDesk['property']->id, 'amount' => '100.00']);

    $this->actingAs($frontDesk['user'])->get(invoiceUrl($frontDesk, "/{$paid->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('invoice.canCreditNote', false));
});

test('a credit above the balance names the balance and the payments to reverse', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'amount' => '300.00']);
    $url = invoiceUrl($setup, "/{$invoice->id}/credit-notes");
    $currency = $setup['property']->settings->currency->value;

    $this->actingAs($setup['user'])->post($url, ['amount' => '400.00', 'reason' => 'Too much'])
        ->assertSessionHasErrors(['amount' => "The credit cannot be more than the invoice balance ({$currency} 300.00)."]);

    [$first, $second] = payInvoiceInParts($setup, $invoice, ['100.00', '100.00']);
    $first->load('payment');
    $second->load('payment');

    $this->post($url, ['amount' => '250.00', 'reason' => 'Too much'])
        ->assertSessionHasErrors(['amount' => "The credit cannot be more than the invoice balance ({$currency} 100.00). Reverse payments {$first->payment->receipt_number} and {$second->payment->receipt_number} first."]);

    app(ReverseAllocation::class)($setup['user'], $second, 'Wrong invoice');

    $this->post($url, ['amount' => '250.00', 'reason' => 'Too much'])
        ->assertSessionHasErrors(['amount' => "The credit cannot be more than the invoice balance ({$currency} 200.00). Reverse payment {$first->payment->receipt_number} first."]);
});

test('after reversing a payment a credit note at the new balance succeeds', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'amount' => '300.00']);
    [$allocation] = payInvoiceInParts($setup, $invoice, ['300.00']);
    $url = invoiceUrl($setup, "/{$invoice->id}/credit-notes");

    $this->actingAs($setup['user'])->post($url, ['amount' => '300.00', 'reason' => 'Wrong rate'])->assertSessionHasErrors('amount');

    app(ReverseAllocation::class)($setup['user'], $allocation, 'Wrong invoice');

    $this->post($url, ['amount' => '300.00', 'reason' => 'Wrong rate'])->assertSessionHasNoErrors();

    expect(ArInvoice::query()->where('type', ArInvoiceType::CreditNote)->sole()->amount)->toBe('-300.00');
});

test('credit=1 opens the credit note Dialog only when the user may issue one', function () {
    $setup = invoicePagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id]);
    $void = ArInvoice::factory()->void()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user']);
    $this->get(invoiceUrl($setup, "/{$invoice->id}?credit=1"))->assertInertia(fn (Assert $page) => $page->where('openCreditNote', true));
    $this->get(invoiceUrl($setup, "/{$invoice->id}"))->assertInertia(fn (Assert $page) => $page->where('openCreditNote', false));
    $this->get(invoiceUrl($setup, "/{$void->id}?credit=1"))->assertInertia(fn (Assert $page) => $page->where('openCreditNote', false));

    $frontDesk = invoicePagesSetup(DefaultRoleEnum::FrontDesk);
    $other = ArInvoice::factory()->create(['property_id' => $frontDesk['property']->id]);

    $this->actingAs($frontDesk['user'])->get(invoiceUrl($frontDesk, "/{$other->id}?credit=1"))->assertInertia(fn (Assert $page) => $page->where('openCreditNote', false));
});
