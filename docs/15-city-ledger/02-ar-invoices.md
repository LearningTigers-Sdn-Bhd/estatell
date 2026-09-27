# AR invoices

## Purpose

Record what a company owes after direct-bill checkout.

## Data model

```
ar_invoices    id, property_id, company_id, folio_id (nullable), type (ArInvoiceType),
               number, issued_on, due_on, amount (signed), status (ArInvoiceStatus), timestamps
```

## Enums

- `ArInvoiceType`: Invoice, CreditNote.
- `ArInvoiceStatus`: Open, Settled, Void.

## Flow

1. The master folio is settled with the `DirectBill` payment method, labelled **"City ledger"** in the UI ("Settle to City ledger").
2. `RecordPayment` checks `direct_bill_enabled` and `CreditExposure`.
3. It posts the negative folio line, so the folio closes.
4. `CreateArInvoiceFromFolio` creates the invoice. `due_on` = `issued_on` + payment terms.

## Rules

- An AR invoice reuses the folio's invoice number. Credit notes get their own sequence.
- Issued invoices are never edited. Corrections are credit notes (negative amount) allocated against the invoice.
- Balance and overdue status are calculated, not stored.

## Actions

`CreateArInvoiceFromFolio`, `IssueCreditNote`, `VoidArInvoice`.

## Permissions

`city-ledger.view`, `city-ledger.invoice`, `city-ledger.credit-note`.

## Lessons from wastays

`ar_invoices` stored `paid_amount` and `outstanding_amount`, and a job refreshed overdue statuses.
