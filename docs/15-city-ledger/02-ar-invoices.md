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
    - it is a Master folio and a unit is still CheckedIn (`units_in_house`). The message names the rooms: "Room 103 is still in-house. Check out all units first." This runs before any write, so no DIRECT_BILL line is posted. `CloseFolio` checks the same as a backstop (see [Folios and lines](../13-folio/01-folios-and-lines.md#close-and-reopen)). Without it, the next night could not post and the folio could not reopen;
    - its payer is not a company of the property (`no_company_payer`);
    - the company is Suspended or has `direct_bill_enabled` off (`direct_bill_not_allowed`);
    - the balance is not above 0 (`nothing_to_settle`);
    - there is no business date (`no_business_date`).
- Settle is **never refused by the credit limit**: it adds no debt, it moves the folio balance into AR. Over the limit, the confirmation shows a warning ("Acme Sdn Bhd is over its credit limit by MYR 100.00. The invoice is still issued.") and no override reason is asked. See [Credit control](04-credit-control-and-reports.md#credit-check).
- `ReopenFolio` refuses a folio that has an AR invoice that is not Void (`folio_has_ar_invoice`): fix it with a credit note.
- The reservation list shows a settled folio's DIRECT_BILL amount as "billed to company" under Paid, not as Paid ([Money strip](../12-reservations/06-reservation-page.md#money-strip)).
- A ThirdParty folio with a company payer settles the same way, also while units are in-house. It makes its own invoice. It is refused only while a live unit still routes Scheduled charges after the business date to it (`folio_receives_scheduled_charges`, from `CloseFolio`, checked before any write): those nights would fail every audit with `folio_settled_to_city_ledger`. Staff change the rule with [Move charges](../13-folio/07-move-charges.md#scheduled-charges) first, or settle after the last night posts.
- **A late charge after Settle** (for example a minibar found the next morning) cannot post to the settled folio (`folio_settled_to_city_ledger`). Staff open a third-party folio for the company, add a routing rule for that unit to it, post the charge, then settle that folio: a second invoice. See [Routing](../13-folio/03-routing.md#rules).
- An AR invoice reuses the folio's invoice number. Credit notes take their own sequence (`DocumentType::CreditNote`).
- Issued invoices are never edited. A correction is a credit note: a negative row with `credit_for_invoice_id`, issued with a reason, for at most the invoice's balance (`credit_exceeds_balance`). It is Settled at once, because it is applied to its invoice as it is issued.
- **Crediting a paid invoice.** Payments reduce the balance, so a paid invoice cannot take a credit note until a payment is freed. Staff reverse an allocation ([AR payments](03-ar-payments.md)), issue the credit note, then allocate the payment again; what is left stays as credit on the company. The page explains this instead of hiding the action (see [Pages](#pages)).
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
- **Issue credit note shows on every invoice that is not Void**, also at balance 0 (`ArInvoiceData.canCreditNote` needs the permission and a non-Void invoice only). `ArInvoiceData.creditBlockedBy` lists the invoice's active allocations (receipt number, date, amount), read with the Allocations section, so no extra query.

| Case                        | Dialog                                                                                                                                                                                                                                                                                                                                    |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Balance above 0             | Amount and reason, as above. Under the amount: "At most MYR 200.00." With allocations it adds "To credit more, reverse a payment first: RC-0045 (MYR 2,200.00)."                                                                                                                                                                          |
| Balance 0, with allocations | No amount field. "This invoice is paid. A credit note can only reduce what is still owed (MYR 0.00). To credit it, reverse a payment first:" then one row per allocation with **Reverse**, which opens the Reverse allocation Dialog. "The money stays with {company} as credit. Allocate it again after the credit note." Only **Close** |
| Amount over the balance     | `credit_exceeds_balance` names the balance and the payments: "The credit cannot be more than the invoice balance (MYR 200.00). Reverse payment RC-0045 first." Without allocations it ends after the balance.                                                                                                                             |

- **Reverse** closes the credit note Dialog and opens the existing Reverse allocation Dialog. After a reversal the page reloads, the balance goes up and the credit note Dialog opens again by itself, now with the amount field. Cancelling the reversal also returns to the credit note Dialog. Dialogs are never stacked.
- `?credit=1` on the invoice page opens the credit note Dialog on load when `canCreditNote`; otherwise it is ignored. It stays as a deep link. The settled folio's closed notice opens the same Dialog on the folio page instead ([Folios and lines](../13-folio/01-folios-and-lines.md#close-and-reopen)).
- Balance 0 and no active allocations: "This invoice has no balance left, so there is nothing to credit." with only **Close**.
- A user who may issue credit notes but not reverse payments sees the payments without **Reverse** (each allocation's `canReverse`), and "Ask a user who can reverse payments."
- `credit_exceeds_balance` gets the balance and the receipt numbers from the controller, which reads them after the refusal (like the reservation group refusals). `IssueCreditNote` and its rule do not change.
- A one-step "credit a paid invoice" (reverse, credit and re-allocate in one transaction) is not built. It is decided with e-invoicing corrections (see the [checklist](../checklist.md#e-invoicing)).
- **Settle to City ledger** is a button in the folio header, after Record payment, on an Open folio with a company payer and a balance above 0. It asks for a confirmation that names the company and the amount.
- The sidebar shows **City ledger › Invoices** after Companies, for users with `company.view`.

## PDF

Rendered on demand with dompdf, not stored, with the shared letterhead from [Printing settings](../02-property/05-printing-settings.md). It shows the invoice or credit note number, dates, the company's name, tax numbers and billing address, the folio lines (an invoice) or the reason and the invoice it corrects (a credit note), and the total. A Void document is stamped VOID.

The folio lines follow the [Documents tab](../02-property/05-printing-settings.md#documents-tab) switches, with the same line rules as the [folio invoice](../13-folio/06-folio-invoice.md#content): grouped under the room and the staying guest ("Room 103 · Tan Wei Ming", from the line source), collapsed room nights, the tax summary per tax, and the amount in words. A line and its reversal are both left out. With **Room and guest on lines** off, the [Rooms block](../02-property/05-printing-settings.md#rooms-block) names the staying guests: an AR invoice always names them. The **Payment details** text prints under the total. The info strip leaves out empty cells (2 to 4 cells: Bill to, Issued, Due, Reservation, the reservation `reference`). A credit note does not read the switches and keeps its layout.

- **Rows**: the folio's posted lines through the shared `FolioDocumentRows` and the [line grouper](../02-property/05-printing-settings.md#line-grouper), with the tax registration numbers. The city ledger line itself is left out. `ArInvoiceDetail::lines()` keeps serving the read-only invoice page.
- **Paid at the hotel**: the invoice amount is the folio balance sent to the company, so a guest payment before settlement must show. After the charges Total, the folio's payments print under "Paid at the hotel" (date, receipt number, method, in brackets), through the shared `FolioPaymentRows`. Then **Amount due** = `ar_invoices.amount`. With no payments the section is left out, and Amount due still prints under Total with the same figure, so it is always the last line.
- **Amount in words** spells the Amount due, the number the company pays.
- **No folio** (`folio_id` null): no lines section, only the info strip and Amount due.

```
Room 103 · Tan Wei Ming
  12–15 Oct  Room, 3 nights × 250.00                       750.00
                          Service tax 8% · SST W10-…        60.00
                                         Total MYR         810.00
Paid at the hotel
  12 Oct  R-1004  Card                                   (100.00)
                                    Amount due MYR         710.00
Ringgit Malaysia seven hundred and ten only
Payment details: Maybank 5141 2233 4455 · Sandbay Resort Sdn Bhd
```

- **Bill to** comes from `CompanyBillTo`. It prints the country only when a street, postcode or city is set, so a company with only a country prints no address line. The folio invoice uses the same class.

## Build order

1. Tables, enums, models, `SettleToCityLedger`, `CreateArInvoiceFromFolio` and the reopen guard.
2. `IssueCreditNote`, `VoidArInvoice` and the two permissions.
3. The invoices pages, the PDF, the folio button and the sidebar item.

## Lessons from wastays

`ar_invoices` stored `paid_amount` and `outstanding_amount`, and a job refreshed overdue statuses.
