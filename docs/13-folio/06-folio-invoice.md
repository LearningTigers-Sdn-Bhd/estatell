# Folio invoice

## Purpose

The printed invoice of one closed folio. `CloseFolio` already gives the folio its `invoice_number` (see [Folios and lines](01-folios-and-lines.md#close-and-reopen)); this document prints it. A guest asks for it at check-out, and a company or agent that pays a folio gets the same document.

## Decisions

| Decision   | Rule                                                                                                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| When       | A **Closed** folio only, because only then does it have its number and a balance of 0. An Open folio has no invoice: print the [booking summary](../12-reservations/08-booking-summary.md). |
| Number     | The folio's `invoice_number`. Nothing new is numbered.                                                                                                                                      |
| Stored     | Nothing. It prints the folio's lines as they are now. A folio reopened and closed again prints its current lines under the same number. E-invoicing will need a snapshot at close (Later).  |
| Title      | "Invoice".                                                                                                                                                                                  |
| Permission | `folio.print-voucher`, the folio print permission. Its label becomes "Print folio vouchers and invoices". The key does not change, so no migration.                                         |
| Server     | `FolioInvoice` (service, `app/Services/Folio/`) builds a `FolioInvoiceDocument`. View `resources/views/pdf/folio-invoice.blade.php`.                                                        |

## Content

```
┌──────────────────────────────────────────────────────────────────────┐
│ [LOGO] Sandbay Resort                                   Invoice      │
│ Lot 12, Jalan Pantai, Kota Kinabalu                     F-0031       │
│ SST W10-1808-32000123                                   15 Oct 2026  │
├──────────────────────────────────────────────────────────────────────┤
│ BILL TO              STAY                       RESERVATION          │
│ Tan Wei Ming         12–15 Oct 2026 · 3 nts     WA1-0142             │
│ 12 Jalan Aman, KL                                                    │
├──────────────────────────────────────────────────────────────────────┤
│ Room 103 · Tan Wei Ming                                              │
│   12–15 Oct  Room, 3 nights × 250.00                       750.00    │
│   14 Oct     Laundry                                        10.00    │
│                                          Service tax 8%     60.80    │
│                                          Tourism tax        30.00    │
│                                          Total MYR         850.80    │
│   15 Oct  R-1007  Card                                    (850.80)   │
│                                          Balance MYR         0.00    │
└──────────────────────────────────────────────────────────────────────┘
```

- **Header slot**: "Invoice", the `invoice_number` and the close date (`closed_at` in the property time zone).
- **Info strip** (2 to 4 cells, empty ones left out): **Bill to**, **Stay**, **Reservation** (the `reference`), **Your ref** (`external_reference`).
- **Bill to**: the payer company (name, its tax numbers and billing address), else the payer guest (name and address), else the booker. The same rule as the voucher's "Billed to".
- **Lines**: the folio's charge, discount and tax lines, with the same rules as the [booking summary charges](../12-reservations/08-booking-summary.md#charges): a line and its reversal are both left out, grouping by unit, collapsed room nights and the tax summary follow the [document switches](../02-property/05-printing-settings.md#documents-tab). There are no Scheduled rows: the folio is closed.
- **Legal facts always print**, whatever the switches: the number, the date, Bill to, the tax amounts and the tax registration number next to the tax total (see [Legal fields](../02-property/05-printing-settings.md#legal-fields)), and the total.
- **Payments**: each payment and refund line (date, receipt number, method). A city ledger line prints as "Billed to {company}, invoice {AR number}". Then **Balance**, which is 0.00 on a closed folio.
- **Footer blocks**: amount in words and signature lines when their switches are on, then the property `footer_text`.

## Route and entry

| Item  | Value                                                                                                                                                                   |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Route | `GET /p/{code}/reservations/{reservation}/folios/{folio}/invoice` (`reservations.folios.invoice`). An Open or Voided folio is a 404. A folio of another reservation too |
| PDF   | Streams inline as `invoice-{invoice_number}.pdf` through `PrintedPdf`                                                                                                   |
| Entry | **Print invoice** in the folio header of a Closed folio, and in the closed notice. Shown to users with `folio.print-voucher`                                            |

## Tests

- The total equals the sum of the printed charge, discount and tax rows, and the balance is 0.00.
- A transferred-out charge (reversed on this folio) does not print. A transferred-in copy does.
- A settled folio prints its city ledger line with the AR invoice number.
- An Open folio is a 404. A user without `folio.print-voucher` is a 403.

## Later

- **E-invoicing (MyInvois)**: a snapshot of the lines and the buyer at close, so a reopened folio cannot change an issued invoice, and a credit note path for a folio invoice. Planned after this item, see the [checklist](../checklist.md#e-invoicing).
