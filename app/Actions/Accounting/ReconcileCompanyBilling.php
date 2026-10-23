<?php

namespace App\Actions\Accounting;

use App\Enums\AccountingDocumentKind;
use App\Enums\AccountingDocumentStatus;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\SystemTransactionCode;
use App\Features\AccountingIntegration;
use App\Models\AccountingConnection;
use App\Models\AccountingCustomer;
use App\Models\AccountingDocument;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingGateway;
use App\Services\Accounting\CompanyBillingMapper;
use App\Services\Accounting\DocumentRef;
use App\Services\Accounting\ReceiptLink;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * Bring the accounting software in line with the property's company billing: send each new AR invoice, credit note and
 * AR payment, and void or replace what changed. It works from the records, not from events, so running it twice sends nothing
 * twice and a failed document is simply tried again. Only documents issued on or after `company_billing_since` are sent.
 *
 * - An invoice is sent once. Voiding it in estatell voids it there.
 * - A credit note is sent after its invoice, and applied to it.
 * - A receipt is the AR payment's active allocations to invoices that were sent. When the allocations change, the old
 *   receipt is voided and a new one is sent. Money that is not allocated yet is not sent.
 *
 * Returns how many documents were sent or changed and how many failed. See docs/26-accounting/02-company-billing.md.
 */
class ReconcileCompanyBilling
{
    private int $changed = 0;

    private int $failed = 0;

    public function __construct(private readonly CompanyBillingMapper $mapper) {}

    /**
     * @return array{changed: int, failed: int}|null Null when company billing is not switched on for the property.
     *
     * @throws LockTimeoutException When another run holds the property for more than 30 seconds, so the job tries again.
     */
    public function __invoke(Property $property): ?array
    {
        $connection = $property->accountingConnection;

        if ($connection === null
            || ! $connection->enabled
            || ! $connection->push_company_billing
            || $connection->company_billing_since === null
            || blank($connection->api_key)
            || ! Feature::for($property)->active(AccountingIntegration::class)) {
            return null;
        }

        $lock = Cache::lock("accounting-billing:{$property->id}", 300);
        $lock->block(30);

        $this->changed = 0;
        $this->failed = 0;

        try {
            $gateway = $connection->provider->gateway($connection);

            $this->invoices($property, $connection, $gateway);
            $this->creditNotes($property, $connection, $gateway);
            $this->receipts($property, $connection, $gateway);
        } finally {
            $lock->release();
        }

        return ['changed' => $this->changed, 'failed' => $this->failed];
    }

    private function invoices(Property $property, AccountingConnection $connection, AccountingGateway $gateway): void
    {
        foreach ($this->arInvoices($property, $connection, ArInvoiceType::Invoice) as $invoice) {
            $document = $this->document($property, $connection, AccountingDocumentKind::Invoice, invoice: $invoice);
            $wanted = $invoice->status !== ArInvoiceStatus::Void;

            $this->reconcile($document, $gateway, $wanted ? $this->hash([$invoice->number, $invoice->amount, $invoice->issued_on->toDateString(), $invoice->due_on?->toDateString(), $invoice->company_id]) : null, function () use ($property, $connection, $gateway, $invoice): DocumentRef {
                $accountCode = $this->clearingAccount($property);
                $customerId = $this->customerId($property, $connection, $gateway, $invoice->company);

                return $gateway->createInvoice($this->mapper->invoice($property, $invoice, $customerId, $accountCode));
            });
        }
    }

    private function creditNotes(Property $property, AccountingConnection $connection, AccountingGateway $gateway): void
    {
        foreach ($this->arInvoices($property, $connection, ArInvoiceType::CreditNote) as $note) {
            $parent = $this->existingDocument($property, $connection, invoiceId: (int) $note->credit_for_invoice_id);
            $parentSent = $parent?->status === AccountingDocumentStatus::Pushed && $parent->external_id !== null;
            $document = $this->document($property, $connection, AccountingDocumentKind::CreditNote, invoice: $note);

            if (! $parentSent && $document->external_id === null) {
                continue;
            }

            $wanted = $note->status !== ArInvoiceStatus::Void && $parentSent;

            $this->reconcile($document, $gateway, $wanted ? $this->hash([$note->number, $note->amount, $note->issued_on->toDateString(), $parent->external_id]) : null, function () use ($property, $connection, $gateway, $note, $parent): DocumentRef {
                $accountCode = $this->clearingAccount($property);
                $customerId = $this->customerId($property, $connection, $gateway, $note->company);

                return $gateway->createCreditNote($this->mapper->creditNote($property, $note, $customerId, $accountCode, new DocumentRef((string) $parent->external_id, $parent->external_type)));
            });
        }
    }

    private function receipts(Property $property, AccountingConnection $connection, AccountingGateway $gateway): void
    {
        $sent = $this->sentInvoiceDocuments($property, $connection);

        $payments = ArPayment::query()
            ->where('property_id', $property->id)
            ->whereNotNull('receipt_number')
            ->where(fn (Builder $query) => $query
                ->whereDate('received_on', '>=', $connection->company_billing_since)
                ->orWhereIn('id', AccountingDocument::query()->where('property_id', $property->id)->whereNotNull('ar_payment_id')->select('ar_payment_id')))
            ->with(['company', 'paymentMethod.transactionCode', 'allocations' => fn ($allocations) => $allocations->active()])
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            $document = $this->document($property, $connection, AccountingDocumentKind::Receipt, payment: $payment);

            /** @var list<ReceiptLink> $links */
            $links = [];

            foreach ($payment->allocations as $allocation) {
                /** @var ArAllocation $allocation */
                $invoiceDocument = $sent[$allocation->ar_invoice_id] ?? null;

                if ($invoiceDocument !== null) {
                    $links[] = new ReceiptLink(new DocumentRef((string) $invoiceDocument->external_id, $invoiceDocument->external_type), (string) $allocation->amount);
                }
            }

            $wanted = $payment->voided_at === null && $links !== [];
            $account = $payment->paymentMethod->transactionCode->gl_account_code;

            $hash = $wanted ? $this->hash([$payment->receipt_number, $payment->received_on->toDateString(), $account, array_map(fn (ReceiptLink $link): array => [$link->invoice->id, $link->amount], $links)]) : null;

            $this->reconcile($document, $gateway, $hash, function () use ($property, $connection, $gateway, $payment, $links, $account): DocumentRef {
                if (blank($account)) {
                    throw AccountingException::unmappedCodes([$payment->paymentMethod->transactionCode->code]);
                }

                $customerId = $this->customerId($property, $connection, $gateway, $payment->company);

                return $gateway->createReceipt($this->mapper->receipt($property, $payment, $customerId, (string) $account, $links));
            });
        }
    }

    /**
     * Send, replace or void one document so the provider matches the wanted state. `$hash` is null when nothing should exist
     * there. An expected failure is saved on the document and does not stop the others.
     *
     * @param  Closure(): DocumentRef  $create
     */
    private function reconcile(AccountingDocument $document, AccountingGateway $gateway, ?string $hash, Closure $create): void
    {
        try {
            if ($hash === null) {
                $this->void($document, $gateway);

                if ($document->status === AccountingDocumentStatus::Pending && $document->external_id === null) {
                    $document->delete();
                }

                return;
            }

            if ($document->status === AccountingDocumentStatus::Pushed && $document->payload_hash === $hash) {
                return;
            }

            $this->void($document, $gateway);

            $document->forceFill(['attempts' => $document->attempts + 1]);
            $ref = $create();

            $document->forceFill([
                'status' => AccountingDocumentStatus::Pushed,
                'external_id' => $ref->id,
                'external_type' => $ref->type,
                'payload_hash' => $hash,
                'error' => null,
                'pushed_at' => now(),
                'voided_at' => null,
            ])->save();
            $this->changed++;
        } catch (AccountingException $exception) {
            $document->forceFill(['status' => AccountingDocumentStatus::Failed, 'error' => $exception->getMessage()])->save();
            $this->failed++;
        }
    }

    /**
     * Void the provider's copy, if there is one that is not voided yet.
     */
    private function void(AccountingDocument $document, AccountingGateway $gateway): void
    {
        if ($document->external_id === null || $document->voided_at !== null) {
            return;
        }

        $gateway->voidDocument($document->kind, new DocumentRef($document->external_id, $document->external_type), __('Voided or changed in estatell'));

        $document->forceFill([
            'status' => AccountingDocumentStatus::Voided,
            'external_id' => null,
            'external_type' => null,
            'payload_hash' => null,
            'voided_at' => now(),
            'error' => null,
        ])->save();
        $this->changed++;
    }

    /**
     * @return Builder<ArInvoice>
     */
    private function arInvoiceQuery(Property $property, AccountingConnection $connection, ArInvoiceType $type): Builder
    {
        return ArInvoice::query()
            ->where('property_id', $property->id)
            ->where('type', $type->value)
            ->where('migrated', false)
            ->where(fn (Builder $query) => $query
                ->whereDate('issued_on', '>=', $connection->company_billing_since)
                ->orWhereIn('id', AccountingDocument::query()->where('property_id', $property->id)->whereNotNull('ar_invoice_id')->select('ar_invoice_id')));
    }

    /**
     * @return iterable<int, ArInvoice>
     */
    private function arInvoices(Property $property, AccountingConnection $connection, ArInvoiceType $type): iterable
    {
        return $this->arInvoiceQuery($property, $connection, $type)->with('company')->orderBy('id')->get();
    }

    private function document(Property $property, AccountingConnection $connection, AccountingDocumentKind $kind, ?ArInvoice $invoice = null, ?ArPayment $payment = null): AccountingDocument
    {
        return AccountingDocument::query()->firstOrCreate(
            ['provider' => $connection->provider, 'ar_invoice_id' => $invoice?->id, 'ar_payment_id' => $payment?->id],
            ['property_id' => $property->id, 'kind' => $kind, 'status' => AccountingDocumentStatus::Pending],
        );
    }

    private function existingDocument(Property $property, AccountingConnection $connection, int $invoiceId): ?AccountingDocument
    {
        return AccountingDocument::query()
            ->where('property_id', $property->id)
            ->where('provider', $connection->provider->value)
            ->where('ar_invoice_id', $invoiceId)
            ->first();
    }

    /**
     * The sent invoice documents of the property by AR invoice id.
     *
     * @return array<int, AccountingDocument>
     */
    private function sentInvoiceDocuments(Property $property, AccountingConnection $connection): array
    {
        return AccountingDocument::query()
            ->where('property_id', $property->id)
            ->where('provider', $connection->provider->value)
            ->where('kind', AccountingDocumentKind::Invoice->value)
            ->where('status', AccountingDocumentStatus::Pushed->value)
            ->whereNotNull('external_id')
            ->get()
            ->keyBy('ar_invoice_id')
            ->all();
    }

    private function clearingAccount(Property $property): string
    {
        $code = TransactionCode::query()
            ->where('property_id', $property->id)
            ->where('system_key', SystemTransactionCode::DirectBillPayment->value)
            ->first();

        if ($code === null || blank($code->gl_account_code)) {
            throw AccountingException::unmappedCodes([$code->code ?? SystemTransactionCode::DirectBillPayment->code()]);
        }

        return (string) $code->gl_account_code;
    }

    private function customerId(Property $property, AccountingConnection $connection, AccountingGateway $gateway, Company $company): string
    {
        $customer = AccountingCustomer::query()
            ->where('company_id', $company->id)
            ->where('provider', $connection->provider->value)
            ->first();

        if ($customer !== null) {
            return $customer->external_id;
        }

        $externalId = $gateway->createCustomer($this->mapper->customer($company));

        if ($externalId === '') {
            throw new AccountingException(__('The accounting software did not return an id for :name.', ['name' => $company->name]));
        }

        return AccountingCustomer::query()->create([
            'property_id' => $property->id,
            'company_id' => $company->id,
            'provider' => $connection->provider,
            'external_id' => $externalId,
        ])->external_id;
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function hash(array $parts): string
    {
        return hash('sha256', (string) json_encode($parts));
    }
}
