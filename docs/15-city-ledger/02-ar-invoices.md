# AR invoices

## Purpose

Record what a company owes after direct-bill checkout, and correct it with credit notes.

## Data model

```
ar_invoices    id, property_id, company_id, folio_id (nullable), credit_for_invoice_id (nullable),
               type (ArInvoiceType), number, issued_on, due_on (nullable), amount (signed),
               reason (nullable), status (ArInvoiceStatus), created_by, timestamps
               UNIQUE (property_id, type, number) WHERE status <> 'void'
               UNIQUE (folio_id) WHERE type = 'invoice' AND status <> 'void'
               CHECK invoice: amount > 0, credit_for_invoice_id IS NULL, due_on IS NOT NULL
               CHECK credit note: amount < 0, credit_for_invoice_id IS NOT NULL, folio_id IS NULL
```

## Enums

- `ArInvoiceType`: Invoice, CreditNote.
- `ArInvoiceStatus`: Open, Settled, Void.

## Flow

1. Staff press **Settle to City ledger** on an Open folio whose payer is a company (permission `city-ledger.invoice`).
2. `SettleToCityLedger` checks the folio and the company, then does three things in one transaction:
    - posts one negative `DIRECT_BILL` line for the whole balance on the business date, key `city-ledger:{folio_id}`;
    - closes the folio with `CloseFolio`, which issues the folio's `invoice_number` if it has none;
    - runs `CreateArInvoiceFromFolio`.
3. The invoice has the folio's `invoice_number`, `issued_on` = the business date and `due_on` = `issued_on` + the company's payment terms (`issued_on` when the terms are empty).

The settle action does not go through `RecordPayment`: a City ledger line has no `payments` row, so it takes no receipt number, cannot be refunded, and the payment methods list keeps `DirectBill` out of the Record payment Dialog. The seeded `DirectBill` payment method stays inactive and supplies only the transaction code.

## Rules

- Settle refuses (a result, not an error) when:
    - the folio is not Open (`folio_not_open`);
    - its payer is not a company of the property (`no_company_payer`);
    - the company is Suspended or has `direct_bill_enabled` off (`direct_bill_not_allowed`);
    - the balance is not above 0 (`nothing_to_settle`);
    - there is no business date (`no_business_date`).
- The credit limit is checked with `CreditCheck` ([Credit control](04-credit-control-and-reports.md#credit-check)): a company already over its limit is refused (`credit_limit_exceeded`) unless the user passes `company.credit-override` with a reason.
- `ReopenFolio` refuses a folio that has an AR invoice that is not Void (`folio_has_ar_invoice`): fix it with a credit note.
- An AR invoice reuses the folio's invoice number. Credit notes take their own sequence (`DocumentType::CreditNote`).
- Issued invoices are never edited. A correction is a credit note: a negative row with `credit_for_invoice_id`, issued with a reason, for at most the invoice's balance (`credit_exceeds_balance`). It is Settled at once, because it is applied to its invoice as it is issued.
- Invoice balance = amount + its non-void credit notes - active allocations ([AR payments](03-ar-payments.md)). The invoice becomes Settled when the balance reaches 0, and Open again when a credit note is voided or an allocation is reversed. The invoice page lists its allocations. Balance and overdue status are calculated, not stored.
- Void:
    - an invoice can be voided only while Open with no non-void credit notes and no active allocations (`invoice_not_voidable`);
    - a credit note can be voided, which puts its invoice back to its calculated status;
    - voiding does not touch the folio. Staff can reopen it (now allowed), reverse the City ledger line and settle again, which makes a new invoice with the same number.
- Refusal codes: `not_an_open_invoice`, `amount_invalid`, `reason_required`, `credit_exceeds_balance`, `no_business_date` (credit note); `invoice_not_voidable`, `already_void` (void). `ArInvoiceBalance` (`app/Services/CityLedger/`) calculates the balance.
- Every action logs on the company: `ar_invoice.created`, `ar_invoice.credit_note_issued`, `ar_invoice.voided`.

## Actions

| Action                     | Does                                                                                       |
| -------------------------- | ------------------------------------------------------------------------------------------ |
| `SettleToCityLedger`       | The three steps above. Returns a result with the invoice, or a refusal                     |
| `CreateArInvoiceFromFolio` | Makes the invoice from a Closed folio with a company payer. Called by `SettleToCityLedger` |
| `IssueCreditNote`          | `(User, ArInvoice, amount, reason)`. Takes a number from the `CreditNote` sequence         |
| `VoidArInvoice`            | Voids an invoice or a credit note                                                          |

## Permissions

| Permission                | Allows                                  | Default roles           |
| ------------------------- | --------------------------------------- | ----------------------- |
| `company.view`            | See the invoices list and invoice pages | as before               |
| `city-ledger.invoice`     | Settle to City ledger, void an invoice  | Owner, Manager, Finance |
| `city-ledger.credit-note` | Issue and void a credit note            | Owner, Manager, Finance |

## Pages

| Page         | Route                                                                                                      | Permission                                                                 |
| ------------ | ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| Invoices     | `GET /p/{code}/finance/city-ledger/invoices` (`finance.city-ledger.invoices.index`)                        | `company.view`                                                             |
| Invoice page | `GET .../invoices/{invoice}` (`.show`)                                                                     | `company.view`                                                             |
| Invoice PDF  | `GET .../invoices/{invoice}/pdf` (`.pdf`)                                                                  | `company.view`                                                             |
| Void         | `POST .../invoices/{invoice}/void` (AlertDialog)                                                           | `city-ledger.invoice` (invoice) or `city-ledger.credit-note` (credit note) |
| Credit note  | `POST .../invoices/{invoice}/credit-notes` (Dialog on the invoice page)                                    | `city-ledger.credit-note`                                                  |
| Settle       | `POST /p/{code}/reservations/{reservation}/folios/{folio}/city-ledger` (`reservations.folios.city-ledger`) | `city-ledger.invoice`                                                      |

```
Finance › City ledger › Invoices
 Number        Company        Type         Issued     Due        Amount     Balance   Status
 F-0034        Borneo Travel  Invoice      04 Oct     03 Nov     2,400.00   2,000.00  Open
 CN-0002       Borneo Travel  Credit note  05 Oct     –          -400.00    –         Settled
```

- The list shows every invoice and credit note of the property, newest first, with search by number or company and filters for type and status. A credit note shows no balance. An overdue invoice (Open, past `due_on` in the property's business date) shows a danger-tone due date.
- The invoice page shows the header (number, company link, folio link, issued and due dates, status), the figures (amount, credit notes, balance), the folio lines it was made from (read only), its credit notes, and the actions **Download PDF**, **Issue credit note** and **Void**.
- **Issue credit note** is a Dialog with the amount (default: the balance) and a required reason. Void asks for a confirmation.
- **Settle to City ledger** is a button in the folio header, after Record payment, on an Open folio with a company payer and a balance above 0. It asks for a confirmation that names the company and the amount.
- The sidebar shows **City ledger › Invoices** after Companies, for users with `company.view`.

## PDF

Rendered on demand with dompdf, not stored, with the shared letterhead from [Printing settings](../02-property/05-printing-settings.md). It shows the invoice or credit note number, dates, the company's name, tax numbers and billing address, the folio lines (an invoice) or the reason and the invoice it corrects (a credit note), and the total. A Void document is stamped VOID.

## Build order

1. Tables, enums, models, `SettleToCityLedger`, `CreateArInvoiceFromFolio` and the reopen guard.
2. `IssueCreditNote`, `VoidArInvoice` and the two permissions.
3. The invoices pages, the PDF, the folio button and the sidebar item.

## Lessons from wastays

`ar_invoices` stored `paid_amount` and `outstanding_amount`, and a job refreshed overdue statuses.
