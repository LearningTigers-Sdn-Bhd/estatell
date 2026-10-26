<?php

use App\Actions\Accounting\QueueCompanyBillingSync;
use App\Actions\Accounting\ReconcileCompanyBilling;
use App\Actions\CityLedger\VoidArPayment;
use App\Enums\AccountingDocumentKind;
use App\Enums\AccountingDocumentStatus;
use App\Enums\AccountingProvider;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\SystemTransactionCode;
use App\Jobs\ReconcileCompanyBillingJob;
use App\Models\AccountingCustomer;
use App\Models\AccountingDocument;
use App\Models\ArAllocation;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/CompanyBillingSetup.php';

/**
 * Where each provider receives each kind of call.
 *
 * @return array{customer: string, invoice: string, note: string, receipt: string}
 */
function billingPaths(AccountingProvider $provider): array
{
    return $provider === AccountingProvider::Qne
        ? ['customer' => 'api/Customers/Create', 'invoice' => 'api/Invoices/Create', 'note' => 'api/ARCNs/Create', 'receipt' => 'api/ARReceipts/Create']
        : ['customer' => '/contacts', 'invoice' => 'sales/invoices', 'note' => 'sales/credit_notes', 'receipt' => 'sales/payments'];
}

/** The void calls the provider received. */
function voidCalls(AccountingProvider $provider): int
{
    return count($provider === AccountingProvider::Qne ? billingCalls('/Void') : billingCalls('sales/', 'PATCH'));
}

function reconcile(Property $property): ?array
{
    return app(ReconcileCompanyBilling::class)($property->refresh());
}

dataset('providers', [
    'QNE' => AccountingProvider::Qne,
    'Bukku' => AccountingProvider::Bukku,
]);

it('sends the company once as a customer and the invoice to the clearing account without tax', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company] = billingSetup($provider);
    $invoice = billingInvoice($property, $company);
    $paths = billingPaths($provider);

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 0])
        ->and(count(billingCalls($paths['customer'])))->toBe(1)
        ->and(count(billingCalls($paths['invoice'])))->toBe(1);

    $document = AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole();
    expect($document->status)->toBe(AccountingDocumentStatus::Pushed)
        ->and($document->kind)->toBe(AccountingDocumentKind::Invoice)
        ->and($document->external_id)->not->toBeEmpty()
        ->and(AccountingCustomer::query()->where('company_id', $company->id)->count())->toBe(1);

    $call = billingCalls($paths['invoice'])[0];

    if ($provider === AccountingProvider::Qne) {
        expect($call['details'])->toHaveCount(1)
            ->and($call['details'][0]['amount'])->toBe(500.0)
            ->and($call['details'][0])->not->toHaveKey('taxCodeId')
            ->and($call['referenceNo'])->toBe($invoice->number)
            ->and($call['dueDate'])->toBe('2026-11-03T00:00:00');
    } else {
        expect($call['form_items'])->toHaveCount(1)
            ->and($call['form_items'][0]['account_id'])->toBe(31)
            ->and($call['form_items'][0]['unit_price'])->toBe(500.0)
            ->and($call['form_items'][0])->not->toHaveKey('tax_code_id')
            ->and($call['number2'])->toBe($invoice->number)
            ->and($call['term_items'][0]['date'])->toBe('2026-11-03')
            ->and($call['currency_code'])->toBe('MYR');
    }

    $before = Http::recorded()->count();
    expect(reconcile($property))->toBe(['changed' => 0, 'failed' => 0])
        ->and(Http::recorded()->count())->toBe($before);
})->with('providers');

it('reuses the customer for the same company\'s next invoice', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company] = billingSetup($provider);
    billingInvoice($property, $company);
    reconcile($property);
    billingInvoice($property, $company);

    reconcile($property);

    expect(count(billingCalls(billingPaths($provider)['customer'])))->toBe(1)
        ->and(count(billingCalls(billingPaths($provider)['invoice'])))->toBe(2);
})->with('providers');

it('voids the invoice at the provider when it is voided in estatell', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company] = billingSetup($provider);
    $invoice = billingInvoice($property, $company);
    reconcile($property);

    $invoice->forceFill(['status' => ArInvoiceStatus::Void])->save();

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 0])
        ->and(voidCalls($provider))->toBe(1);

    $document = AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole();
    expect($document->status)->toBe(AccountingDocumentStatus::Voided)
        ->and($document->external_id)->toBeNull()
        ->and(reconcile($property))->toBe(['changed' => 0, 'failed' => 0])
        ->and(voidCalls($provider))->toBe(1);
})->with('providers');

it('leaves no record for an invoice that was voided before it was ever sent', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company] = billingSetup();
    billingInvoice($property, $company, ['status' => ArInvoiceStatus::Void]);

    expect(reconcile($property))->toBe(['changed' => 0, 'failed' => 0])
        ->and(AccountingDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends a credit note after its invoice and applies it to that invoice', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company] = billingSetup($provider);
    $invoice = billingInvoice($property, $company);
    $note = billingInvoice($property, $company, ['type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $invoice->id, 'amount' => '-120.00', 'number' => 'CN-0001', 'reason' => 'Goodwill', 'due_on' => null]);
    $paths = billingPaths($provider);

    expect(reconcile($property))->toBe(['changed' => 2, 'failed' => 0]);

    $invoiceDocument = AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole();
    $call = billingCalls($paths['note'])[0];

    if ($provider === AccountingProvider::Qne) {
        expect($call['details'][0]['amount'])->toBe(120.0)
            ->and($call['knockoff'][0]['docId'])->toBe($invoiceDocument->external_id)
            ->and($call['knockoff'][0]['docType'])->toBe('INV')
            ->and($call['knockoff'][0]['paymentAmount'])->toBe(120.0);
    } else {
        expect($call['form_items'][0]['unit_price'])->toBe(120.0)
            ->and($call['link_items'][0]['target_transaction_id'])->toBe((int) $invoiceDocument->external_id)
            ->and($call['link_items'][0]['apply_amount'])->toBe(120.0);
    }

    expect(AccountingDocument::query()->where('ar_invoice_id', $note->id)->sole()->kind)->toBe(AccountingDocumentKind::CreditNote);
})->with('providers');

it('sends a receipt for the allocated amount, banked to the method account', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup($provider);
    $invoice = billingInvoice($property, $company);
    $payment = billingPayment($property, $company, $method, $invoice, '300.00', ['amount' => '500.00']);
    $paths = billingPaths($provider);

    reconcile($property);

    $invoiceDocument = AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole();
    $receipt = AccountingDocument::query()->where('ar_payment_id', $payment->id)->sole();
    $call = billingCalls($paths['receipt'])[0];

    expect($receipt->status)->toBe(AccountingDocumentStatus::Pushed)->and($receipt->kind)->toBe(AccountingDocumentKind::Receipt);

    if ($provider === AccountingProvider::Qne) {
        expect($call['multiPayments'][0]['amount'])->toBe(300.0)
            ->and($call['multiPayments'][0]['referenceNo'])->toBe($payment->receipt_number)
            ->and($call['knockoff'][0]['docId'])->toBe($invoiceDocument->external_id)
            ->and($call['knockoff'][0]['paymentAmount'])->toBe(300.0);
    } else {
        expect($call['amount'])->toBe(300.0)
            ->and($call['deposit_items'][0]['account_id'])->toBe(32)
            ->and($call['deposit_items'][0]['amount'])->toBe(300.0)
            ->and($call['link_items'][0]['target_transaction_id'])->toBe((int) $invoiceDocument->external_id)
            ->and($call['link_items'][0]['apply_amount'])->toBe(300.0)
            ->and($call['number2'])->toBe($payment->receipt_number);
    }

    $before = Http::recorded()->count();
    reconcile($property);
    expect(Http::recorded()->count())->toBe($before);
})->with('providers');

it('replaces the receipt when its allocations change and voids it when none are left', function (AccountingProvider $provider) {
    fakeBilling($provider);
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup($provider);
    $invoice = billingInvoice($property, $company);
    $payment = billingPayment($property, $company, $method, $invoice, '200.00');
    reconcile($property);
    $paths = billingPaths($provider);

    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '100.00']);

    expect(reconcile($property))->toBe(['changed' => 2, 'failed' => 0])
        ->and(voidCalls($provider))->toBe(1)
        ->and(count(billingCalls($paths['receipt'])))->toBe(2);

    $receipt = AccountingDocument::query()->where('ar_payment_id', $payment->id)->sole();
    expect($receipt->status)->toBe(AccountingDocumentStatus::Pushed);

    ArAllocation::query()->where('ar_payment_id', $payment->id)->update(['reversed_at' => now(), 'reversed_by' => User::factory()->create(['property_id' => $property->id])->id, 'reversal_reason' => 'Wrong invoice']);

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 0])
        ->and(voidCalls($provider))->toBe(2)
        ->and($receipt->refresh()->status)->toBe(AccountingDocumentStatus::Voided);
})->with('providers');

it('voids the receipt when the payment is voided', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup();
    $invoice = billingInvoice($property, $company);
    $payment = billingPayment($property, $company, $method, $invoice);
    reconcile($property);

    ArAllocation::query()->where('ar_payment_id', $payment->id)->update(['reversed_at' => now(), 'reversed_by' => User::factory()->create(['property_id' => $property->id])->id, 'reversal_reason' => 'x']);
    $payment->forceFill(['voided_at' => now(), 'voided_by' => $payment->received_by])->save();

    reconcile($property);

    expect(AccountingDocument::query()->where('ar_payment_id', $payment->id)->sole()->status)->toBe(AccountingDocumentStatus::Voided);
});

it('fails the invoice with the code named when the clearing account is not mapped, then sends it once it is', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company] = billingSetup(mapped: false);
    $invoice = billingInvoice($property, $company);

    expect(reconcile($property))->toBe(['changed' => 0, 'failed' => 1]);

    $document = AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole();
    expect($document->status)->toBe(AccountingDocumentStatus::Failed)
        ->and($document->error)->toContain('DIRECT_BILL')
        ->and(billingCalls('api/Invoices/Create'))->toBe([]);

    mapCode($property, SystemTransactionCode::DirectBillPayment->value, '300-100');

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 0])
        ->and($document->refresh()->status)->toBe(AccountingDocumentStatus::Pushed)
        ->and($document->attempts)->toBe(2)
        ->and($document->error)->toBeNull();
});

it('fails the receipt when the payment method has no bank account', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup();
    $method->transactionCode->forceFill(['gl_account_code' => null])->save();
    $invoice = billingInvoice($property, $company);
    $payment = billingPayment($property, $company, $method, $invoice);

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 1])
        ->and(AccountingDocument::query()->where('ar_payment_id', $payment->id)->sole()->error)->toContain($method->transactionCode->code);
});

it('fails the invoice and keeps going when the provider refuses it', function () {
    Http::fake([
        '*/api/Auth/Connect*' => Http::response(['code' => '0000', 'data' => ['token' => 'jwt']]),
        '*/api/AccountCodes/Leaf/Query*' => Http::response(['code' => '0000', 'data' => ['count' => 1, 'value' => [['id' => 'a']]]]),
        '*/api/*/New*' => Http::response(['code' => '0000', 'data' => []]),
        '*/api/Customers/Create*' => Http::response(['code' => '0000', 'data' => ['id' => 701]]),
        '*/api/Invoices/Create*' => Http::response(['code' => '4000', 'message' => 'Period is closed']),
    ]);
    ['property' => $property, 'company' => $company] = billingSetup();
    $invoice = billingInvoice($property, $company);

    expect(reconcile($property))->toBe(['changed' => 0, 'failed' => 1])
        ->and(AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole()->error)->toContain('Period is closed');
});

it('sends only documents issued on or after the start date, and never migrated ones or payments without a receipt', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup();
    billingInvoice($property, $company, ['issued_on' => '2026-09-30']);
    billingInvoice($property, $company, ['migrated' => true]);
    $kept = billingInvoice($property, $company);
    $oldSystem = PaymentMethod::query()->where('property_id', $property->id)->where('type', 'old_system')->firstOrFail();
    billingPayment($property, $company, $oldSystem, $kept, '100.00', ['receipt_number' => null, 'reference' => 'OLD-1']);

    expect(reconcile($property))->toBe(['changed' => 1, 'failed' => 0])
        ->and(AccountingDocument::query()->count())->toBe(1);
});

it('does nothing when company billing, the connection or the feature is off', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'connection' => $connection, 'company' => $company] = billingSetup();
    billingInvoice($property, $company);

    $connection->forceFill(['push_company_billing' => false])->save();
    expect(reconcile($property))->toBeNull();

    $connection->forceFill(['push_company_billing' => true, 'enabled' => false])->save();
    expect(reconcile($property))->toBeNull();

    $connection->forceFill(['enabled' => true, 'company_billing_since' => null])->save();
    expect(reconcile($property))->toBeNull();

    Http::assertNothingSent();
});

it('queues a sync only for a property with company billing switched on', function () {
    Queue::fake();
    ['property' => $property, 'connection' => $connection] = billingSetup();

    app(QueueCompanyBillingSync::class)($property->id);
    Queue::assertPushed(ReconcileCompanyBillingJob::class, fn (ReconcileCompanyBillingJob $job): bool => $job->propertyId === $property->id);

    Queue::fake();
    $connection->forceFill(['push_company_billing' => false])->save();
    app(QueueCompanyBillingSync::class)($property->id);
    Queue::assertNothingPushed();
});

it('queues a sync from the city ledger actions', function () {
    Queue::fake();
    ['property' => $property, 'company' => $company, 'method' => $method] = billingSetup();
    $payment = billingPayment($property, $company, $method, billingInvoice($property, $company));
    ArAllocation::query()->where('ar_payment_id', $payment->id)->update(['reversed_at' => now(), 'reversed_by' => $payment->received_by, 'reversal_reason' => 'x']);

    app(VoidArPayment::class)(User::factory()->create(['property_id' => $property->id]), $payment);

    Queue::assertPushed(ReconcileCompanyBillingJob::class);
});

it('runs the queued job through the Action', function () {
    fakeBilling(AccountingProvider::Qne);
    ['property' => $property, 'company' => $company] = billingSetup();
    $invoice = billingInvoice($property, $company);

    (new ReconcileCompanyBillingJob($property->id))->handle(app(ReconcileCompanyBilling::class));

    expect(AccountingDocument::query()->where('ar_invoice_id', $invoice->id)->sole()->status)->toBe(AccountingDocumentStatus::Pushed);
});
