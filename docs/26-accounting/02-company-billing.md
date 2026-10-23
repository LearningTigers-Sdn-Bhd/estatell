# Company billing

## Purpose

Keep each company's balance in the accounting software: send the AR invoice when a folio is billed to a company, its credit notes, and the company's payments, so the accountant sees who owes what without retyping.

## Why it needs the clearing account

The [daily journal](01-daily-journal.md) already debits the city ledger amount to the account `DIRECT_BILL` is mapped to. A company invoice must not add revenue again, so it posts to that same account instead:

| Entry                          | Debit                         | Credit                   |
| ------------------------------ | ----------------------------- | ------------------------ |
| Daily journal (RM 660 billed)  | City ledger clearing 660      | Revenue and SST          |
| Invoice to Acme (this feature) | Accounts receivable, Acme 660 | City ledger clearing 660 |
| **Result**                     | Clearing account is 0         | Revenue is counted once  |

- The invoice has one line, no product and **no tax**. Revenue and tax are already in the journal.
- The accountant creates a "City ledger clearing" account, and the hotel maps `DIRECT_BILL` to it on the Accounts tab. No new setting is needed.
- Company billing needs the daily journal switched on. Without it the invoice would credit the clearing account with nothing to cancel.

## Scope

| Sent                                                       | Not sent                                                              |
| ---------------------------------------------------------- | --------------------------------------------------------------------- |
| Each company as a customer, once                           | Invoices, notes and payments brought from the old system (`migrated`) |
| AR invoices (settle to city ledger)                        | Documents issued before the start date                                |
| Credit notes, applied to their invoice                     | A payment with no receipt number                                      |
| AR payments, matched to the invoices they are allocated to | Money that is not allocated yet (see below)                           |
| Voids and changes to all of the above                      | Folio payments (the daily journal carries those)                      |

## Switching it on

- A switch on the Connection tab, **Also send company invoices and payments**. It needs **Push closed days automatically** on.
- The first time it is switched on, `company_billing_since` is set to the current business date. Only documents issued on or after that date are sent, so nothing old is backfilled. Switching it off and on again keeps the date, so nothing is missed.
- Earlier city ledger debits are in the clearing account with no invoice against them. That is the accountant's to clear once.

## Data model

```
accounting_connections   push_company_billing (bool), company_billing_since (date, nullable)

accounting_customers     id, property_id, company_id, provider, external_id
                         unique (company_id, provider)

accounting_documents     id, property_id, ar_invoice_id (nullable), ar_payment_id (nullable), provider,
                         kind (AccountingDocumentKind), status (AccountingDocumentStatus), external_id,
                         external_type, payload_hash, error, attempts, pushed_at, voided_at
                         unique (ar_invoice_id, provider), unique (ar_payment_id, provider)
                         check: exactly one of ar_invoice_id and ar_payment_id is set
```

- A credit note is an `ar_invoices` row, so its document has `ar_invoice_id` and kind CreditNote.
- `payload_hash` is the hash of what was sent. A receipt whose allocations changed has another hash, and is replaced.
- `external_type` is the provider's own document type. QNE needs the invoice's type to match a receipt to it.

## Enums

- `AccountingDocumentKind`: Invoice, CreditNote, Receipt.
- `AccountingDocumentStatus`: Pending, Pushed (shown as Sent), Failed, Voided.

## How a sync works

`ReconcileCompanyBilling` (Action) works from the records, never from events, so it is safe to run again: a second run sends nothing. It runs in this order:

| Step | Sends                                                                                                                           |
| ---- | ------------------------------------------------------------------------------------------------------------------------------- |
| 1    | Each AR invoice not sent yet. A voided invoice that was sent is voided there. A voided one that was never sent leaves no record |
| 2    | Each credit note whose invoice was sent, applied to that invoice. A voided one is voided there                                  |
| 3    | Each AR payment as one receipt for the sum of its active allocations to sent invoices, banked to the payment method's account   |

- **Customer:** created the first time a company needs one, with the code `EST-{company id}`. If the accountant already has the company, they can merge or relink it in the accounting software.
- **Receipt amount:** only the allocated part is sent. Money that is not allocated yet is not in the accounting software until staff allocate it, so no provider has to handle an unapplied payment.
- **Allocations change:** a reversed or added allocation, or a voided payment, changes the receipt. The old receipt is voided and a new one is sent, or none when nothing is left.
- **Bank account:** the account mapped to the payment method's transaction code (`CASH`, `BANK_TRANSFER`, and so on) on the Accounts tab. The same mapping is the debit account in the daily journal.
- **Failure:** an expected failure (no account for `DIRECT_BILL`, no bank account, a refusal by the provider) is saved on the document as Failed with the message, and the others go on. The next sync tries it again.

### Triggers

- `QueueCompanyBillingSync` is called by the city ledger Actions after they change an invoice, a credit note or a payment: `CreateArInvoiceFromFolio`, `IssueCreditNote`, `VoidArInvoice`, `RecordArPayment`, `AllocateArPayment`, `ReverseAllocation` and `VoidArPayment`. `AutoAllocateArPayment` goes through `AllocateArPayment`.
- It queues `ReconcileCompanyBillingJob` after the surrounding transaction commits, only for a property with company billing on. The job is unique per property while it waits, and a change during a run queues another. A busy property is retried.
- **Sync now** on the History tab runs the same Action at once and says how many documents were sent and how many failed.

## What is sent

| Document    | QNE                                                                                    | Bukku                                                                                  |
| ----------- | -------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Customer    | `Customers/New`, then `Customers/Create`                                               | `POST contacts`, type customer                                                         |
| Invoice     | `SalesInvoices/Create`, one line with an account id and the amount, approved           | `POST sales/invoices`, one line with `account_id` (no product), credit terms, ready    |
| Credit note | `ARCNs/Create` with a knock-off against the invoice                                    | `POST sales/credit_notes` with `link_items` to the invoice                             |
| Receipt     | `ARReceipts/Create`: `multiPayments` for the bank account, `knockoff` for each invoice | `POST sales/payments`: `deposit_items` for the bank account, `link_items` for invoices |
| Void        | `Void` on invoices, `ARCNs` and `ARReceipts`, with the id, a date and a short reason   | `PATCH` with status void and a reason                                                  |

- Our invoice number goes in the reference (`referenceNo` for QNE, `number2` for Bukku), not in the provider's own number, because a custom number needs a plan option at Bukku.
- Documents are approved or ready, not draft, because the provider can only match a receipt or credit note to a final invoice. The daily journal stays a draft.

To verify against a real QNE and a Bukku staging account: the `docType` QNE returns for invoices, credit notes and receipts, that a receipt's `knockoff` accepts our `docType` and `docId`, that Bukku accepts a line with an account and no product or tax, and the response keys that hold the new id.

## Routes and permission

`settings.manage` behind the feature gate. History tab: `settings.accounting.history` shows both lists. `settings.accounting.billing.sync` runs a sync.

## Tests

`tests/Feature/Accounting/CompanyBillingTest.php` runs the flow against both providers: the customer once, the invoice to the clearing account without tax, no repeat on a second run, a void, a credit note applied to its invoice, a receipt for the allocated amount, a replaced and a voided receipt, a missing account that fails and then sends, a refusal that does not stop the others, the start date, migrated documents, the switches, the queue and the job. Shared setup lives in `tests/Feature/Support/CompanyBillingSetup.php`.
