<?php

namespace App\Services\Accounting;

use App\Enums\AccountingDocumentKind;

/**
 * The calls estatell makes to an accounting software. One class per provider, chosen by `AccountingProvider::gateway()`.
 * Every method throws `AccountingException` when the provider refuses or cannot be reached.
 */
interface AccountingGateway
{
    /**
     * Sign in with the saved key and make one read call, so a wrong key or a wrong company fails here.
     */
    public function testConnection(): void;

    /**
     * The provider's account ids for account codes. A code the provider does not have is left out.
     *
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    public function findAccountIds(array $codes): array;

    /**
     * Create the journal and return the provider's id for it.
     */
    public function createJournal(AccountingJournal $journal): string;

    /**
     * Create a company as a customer and return the provider's id for it.
     */
    public function createCustomer(CustomerDetails $customer): string;

    public function createInvoice(InvoiceDetails $invoice): DocumentRef;

    public function createCreditNote(CreditNoteDetails $note): DocumentRef;

    public function createReceipt(ReceiptDetails $receipt): DocumentRef;

    /**
     * Void a document the provider holds. A voided document stays on record there.
     */
    public function voidDocument(AccountingDocumentKind $kind, DocumentRef $document, string $reason): void;
}
