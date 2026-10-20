# Old system balances

## Purpose

Bring company money from the old PMS into the city ledger on go-live: invoices a company still owes, and credit it paid in advance. Part of [Migration](../17-onboarding/07-migration.md).

Without them the first bank transfer after go-live has no invoice to match, the aging report is wrong, and the credit limit sees no debt.

## Entry

On the company page, the header **More actions** menu (icon and label) holds **Add from old system** (inside the [migration window](../17-onboarding/07-migration.md#migration-window), `folio.manual`) together with **Suspend** or **Reactivate** (`company.manage`). The menu shows when the user may do at least one of them.

```
┌─ Add from old system · Acme Sdn Bhd ────────────────────────────────┐
│  (•) Unpaid invoice    ( ) Credit on account                        │
│                                                                     │
│  Old invoice no. *  [e.g. INV-2026-0912 ]                           │
│  Issued on *        [12 Sep ▾]        Due on *   [12 Oct ▾]         │
│  Amount owed *      [ 4,850.00 ]   what is still unpaid             │
│  Reason *           [e.g. Migrated from old PMS                 ]   │
│                                              [Cancel]  [Save]       │
└─────────────────────────────────────────────────────────────────────┘
```

Credit on account shows **Old receipt no.**, **Received on** and **Amount** instead.

## Unpaid invoice

`AddOldSystemInvoice` (`app/Actions/CityLedger/`) creates an `ar_invoices` row:

| Column      | Value                                       |
| ----------- | ------------------------------------------- |
| `type`      | Invoice                                     |
| `folio_id`  | null                                        |
| `number`    | The old invoice number                      |
| `issued_on` | The old issue date, before the go-live date |
| `due_on`    | The old due date, on or after `issued_on`   |
| `amount`    | What is still owed, greater than 0          |
| `reason`    | The reason                                  |
| `migrated`  | true (new column, default false)            |

| Check                                                  | Refusal                   |
| ------------------------------------------------------ | ------------------------- |
| The window is open                                     | `migration_window_closed` |
| The company is the property's and Active               | `company_not_available`   |
| `issued_on` is before the go-live date                 | `issued_after_go_live`    |
| `due_on` is on or after `issued_on`                    | `due_before_issue`        |
| The amount is greater than 0 with at most two decimals | `amount_invalid`          |
| No other invoice that is not Void has the number       | `number_taken`            |

Logged on the company: `city_ledger.old_invoice_added`.

## Credit on account

`AddOldSystemCredit` creates an `ar_payments` row with the [Old system](../17-onboarding/07-migration.md#old-system-payment-method) method, `received_on` = the old date, `reference` = the old receipt number, and no receipt number. It stays unallocated, so it is credit on the company, as the [balance rules](03-ar-payments.md#rules) already say. It can be allocated to an invoice later, like any AR payment.

`ar_payments.receipt_number` becomes nullable. A CHECK cannot read the method type, so it is `receipt_number IS NOT NULL OR reference IS NOT NULL`: an AR payment with no receipt number must carry the old receipt number as its reference. Only `AddOldSystemCredit` writes a null receipt number. Screens and statements show it as "Old system · {reference}" (`ArPayment::displayNumber()`), and it has no receipt PDF (a 404, no Receipt link, also on the company portal). Logged: `city_ledger.old_credit_added`. A repeated form key returns the same payment.

## Edge cases

| Case                                             | Handling                                                                                                                                                                                           |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| An old invoice was partly paid                   | Enter only what is still owed. The original total goes in the reason.                                                                                                                              |
| The company pays an old invoice after go-live    | A normal AR payment with a real method, allocated to the migrated invoice. It counts as cash received.                                                                                             |
| The company disputes an old invoice              | A credit note against it, as for any invoice.                                                                                                                                                      |
| It was entered wrong                             | Void it (allowed while it has no active allocations). Credit on account is voided the same way.                                                                                                    |
| The old number matches an estatell number        | Refused with `number_taken`. estatell numbers carry the prefix, so a clash is unlikely.                                                                                                            |
| The company is over its credit limit on day one  | Migrated debt counts in exposure at once, so new direct-bill bookings follow the existing limit rules. The dialog warns before saving: "Acme Sdn Bhd will be over its credit limit by MYR 850.00." |
| An old invoice must be reprinted                 | Not possible: there is no folio. The statement and the invoice page show "Old system · {number}". The PDF is in the old PMS.                                                                       |
| E-invoicing                                      | A migrated invoice is never submitted.                                                                                                                                                             |
| The company is not in estatell yet               | Add it first, in the companies list or with the existing import.                                                                                                                                   |
| A booking advance recorded here and on the folio | Counted twice. The dialog hint says where each goes (see [Old system payment method](../17-onboarding/07-migration.md#old-system-payment-method)).                                                 |
| Many invoices                                    | One dialog per invoice. A CSV import is not planned.                                                                                                                                               |

## Pages

- The invoice page and the list show a **Migrated** badge on a migrated invoice, and no **Print** for it.
- Aging and the statement include migrated invoices by their real dates.

## Build order

| Item | Commit                                                           | Covers                                                                                                                                                                                        |
| ---- | ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | `feat(city-ledger): add unpaid invoices and credit from old PMS` | `ar_invoices.migrated`, the nullable receipt number for Old system, `AddOldSystemInvoice`, `AddOldSystemCredit`, the dialog, the badge, the credit limit warning. Needs: the migration window |
