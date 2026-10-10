# Booking summary

## Purpose

A printable proforma of one reservation: who pays, the stay, every charge (posted and still to come), the payments and what is still due. Guests, travel agents and companies ask for it before arrival and during the stay.

It is not a tax invoice. The tax invoice of a stay is the [folio invoice](../13-folio/06-folio-invoice.md), and a company gets an [AR invoice](../15-city-ledger/02-ar-invoices.md).

## Decisions

| Decision    | Rule                                                                                                                                                                                                                                                                                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Number      | None. The reservation `reference` identifies it, with "As at {date}, {time}" in the property time zone. Each print shows the current state, so a number of its own would either change on every print or stay the same while the content changes. A binding quote is a later feature. |
| Stored      | Nothing. No table, no snapshot, no activity log. The route is a read-only GET, like the [voucher](../13-folio/05-folio-page.md#print-voucher).                                                                                                                                        |
| Title       | "Booking summary", with "Not a tax invoice" under it.                                                                                                                                                                                                                                 |
| Permission  | `reservations.view`. It shows nothing the reservation page does not. No new permission.                                                                                                                                                                                               |
| Scope       | The whole reservation: every unit, every folio that is not Voided. A group prints as one document.                                                                                                                                                                                    |
| Server-made | `BookingSummary` (service, `app/Services/Reservations/`) returns one readonly `BookingSummaryDocument` with every figure. The view only prints. See [How the rows are built](#how-the-rows-are-built).                                                                                |

## Content

```
┌──────────────────────────────────────────────────────────────────────┐
│ [LOGO] Sandbay Resort                           Booking summary      │
│ Lot 12, Jalan Pantai, Kota Kinabalu             WA1-0142             │
│ +60 88 123 456 · stay@sandbay.my                As at 10 Oct 2026,   │
│                                                 14:32                │
│                                                 Not a tax invoice    │
├──────────────────────────────────────────────────────────────────────┤
│ BILL TO          STAY                    BOOKED ON     YOUR REF      │
│ Acme Sdn Bhd     12–15 Oct 2026 · 3 nts  04 Oct 2026   PO-7781       │
├──────────────────────────────────────────────────────────────────────┤
│ Rooms                                                                │
│ Room 103 · Deluxe King · Best flexible · Tan Wei Ming, 2 adults      │
│ Room 104 · Deluxe Twin · Best flexible · Lee Mei Ling, 2 adults      │
├──────────────────────────────────────────────────────────────────────┤
│ Charges                                                              │
│ Room 103 · Tan Wei Ming                                              │
│   12–15 Oct  Room, 3 nights × 250.00                       750.00    │
│   13 Oct     Island hopping trip                           360.00    │
│ Room 104 · Lee Mei Ling                                              │
│   12–15 Oct  Room, 3 nights × 230.00                       690.00    │
│                                          Service tax 8%    144.00    │
│                                          Tourism tax        60.00    │
│                                          Total MYR       2,004.00    │
├──────────────────────────────────────────────────────────────────────┤
│ Payments                                                             │
│   04 Oct  R-1002  Bank transfer                           (500.00)   │
│                                          Balance due MYR 1,504.00    │
├──────────────────────────────────────────────────────────────────────┤
│ Cancellation policy                                                  │
│ Free cancellation until 2 days before arrival. After that, one       │
│ night is charged.                                                    │
│ Payment details                                                      │
│ Maybank 5141 2233 4455 · Sandbay Resort Sdn Bhd                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Header

The [letterhead](../02-property/05-printing-settings.md#letterhead-and-footer). Its right slot holds "Booking summary", the reservation `reference`, "As at {date}, {time}" (now, in the property time zone) and "Not a tax invoice".

### Info strip

One row of cells. A cell with no value is left out, and the others share the width, so the strip has 2 to 4 cells. There is no column setting.

| Cell      | Value                                                                                       |
| --------- | ------------------------------------------------------------------------------------------- |
| Bill to   | The company (`company_id`), else the booker                                                 |
| Stay      | Earliest arrival to latest departure of the live units, and the nights between them         |
| Booked on | `created_at`, as a date in the property time zone                                           |
| Your ref  | `external_reference` (the OTA booking number or the company reference), only when it is set |

### Rooms

The posted rows come from the shared `FolioDocumentRows` (see [Line grouper](../02-property/05-printing-settings.md#line-grouper)); `BookingSummary` adds the Scheduled rows.

One line per unit, every status, so the document always says what was booked. A Cancelled or NoShow unit adds its status at the end ("· No-show"). Each line: tonight's room or bed (the first night's before arrival, left out when none is assigned), the room type, the rate plan, the primary guest and the guest count. A unit with different dates from the strip adds its own dates.

### Charges

The rows below go through the shared [line grouper](../02-property/05-printing-settings.md#line-grouper) (`DocumentLines`), which the folio invoice and the AR invoice also use.

- **Rows.** Every charge line of every folio that is not Voided, plus the Scheduled rows. This is the same set the [folio tab](../13-folio/05-folio-page.md) shows, so Total equals the sum of the folios' Projected totals minus their payments.
- **Left out.** Payment lines, and a line together with its reversal (a pair nets to 0). A transfer is a reversal plus a copy, so a transferred charge shows once.
- **Grouped by unit**, through the line source (a child takes its parent's), with the room and the primary guest as the group heading. Lines with no unit come last under "Other charges". The [document switch](../02-property/05-printing-settings.md#documents-tab) **Room and guest on lines** turns the grouping off: then the rows are one list by date with a [Room column](../02-property/05-printing-settings.md#room-column) when the reservation has more than one unit.
- **Room nights.** With **Nightly breakdown** off (default), the room lines of one unit with the same description and amount collapse into one row: "Room, 3 nights × 250.00" with the date range. Inclusions and discounts collapse the same way. With it on, one row per night.
- **Taxes.** Tax lines are summed per tax under the rows ("Service tax 8%", "Tourism tax"). With **Tax breakdown** off, one "Tax" total.
- **A Cancelled or NoShow unit** shows only when it has posted lines (its fee), with its status after the room name.
- **Total** is the sum of all charge, discount and tax rows.
- **No charge rows** (for example every unit a no-show under a Free policy): the section prints "No charges" and no Total line.

### How the rows are built

`BookingSummary` builds the grouper's `DocumentRow`s itself. It does not reuse `FolioStatement`: that service serves the folio tab (one selected folio, actions, permissions) and its rows carry no quantity, reversal link or nightly flag.

| Source         | How                                                                                                                                      | Nightly                                                                      |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Posted lines   | Every line of the folios that are not Voided, in one query. The unit comes from `LineSourceUnits`; a child line takes its parent's unit. | Unit night source: yes. Add-on: by its `ChargingUnit`. Reservation unit: no. |
| Scheduled rows | `ScheduledFolioRows::forReservation()`, the rows the folio tab shows. Each block carries its unit id.                                    | Key `night:`: yes. Key `addon:`: by the add-on's `ChargingUnit`.             |

- A child line (discount, tax) takes its parent's nightly flag.
- **Room label** (`DocumentRow::$room`): tonight's room number, else the first night's before arrival, else the room type name (room types have no code). The group heading is "Room 103 · Tan Wei Ming".
- A posted night and Scheduled nights with the same description and price merge into one row, for example one posted and two to come print "Room, 3 nights × 250.00".

### Payments

- Every payment line of the folios that are not Voided, net of refunds (a refund is its own row): date, receipt number, method and amount in brackets.
- A city ledger line (`DIRECT_BILL`) prints as "Billed to {company}, invoice {number}" and counts against the balance. The invoice is the AR invoice with that `folio_id` (credit notes left out).
- The receipt number and the method come from the line's `payments` row.
- No payments: the section is left out. It never prints "Total payments 0.00".
- No charge rows and no payments: Balance due is left out too. With payments but no charges (a deposit held on a free no-show), Balance due prints as a Credit, so the refund owed shows.
- **Balance due** = Total minus payments minus amounts billed to the company. It can be below 0 ("Credit").
- Paid and billed to company match what `ReservationDirectory` computes for the reservations list, and so does the balance once nothing is Scheduled (the list balance counts posted lines only; Balance due also counts the Scheduled rows). The tests compare them, so the list, this document and the summary card cannot drift apart.

### Footer blocks

- **Cancellation policy**: the `CANCEL` and `NO_SHOW` [room revenue policy](../06-catalogues/06-room-revenue.md#policies) texts in `property_settings.default_locale` (`text_ms` for Malay, else `text_en`; an empty Malay text falls back to English). Left out when the switch is off or both texts are empty.
- **Payment details**: the `bank_details` text from the Documents tab, left out when empty.
- **Amount in words** under Balance due, when the switch is on.
- The property `footer_text` on every page, as on every PDF.

## Rules

- **Empty values never print.** No "-", no "0.00" payment line, no country-only address, no empty cell.
- **Prices for the guest.** A reservation with a `company_id` offers a second print, **without prices**: the same document with the Charges, Payments, Balance due, amount in words and Payment details left out. The header, the info strip, Rooms and the cancellation policy stay. Rooms leaves out the rate plan, because its name can name a negotiated rate ("Room Only –20% (Acme only)"). A negotiated company rate is never handed to the guest by mistake. This follows the [registration card](../16-registration/01-card-fields.md#booking-summary) rule.
- **Outcome stamp** under "Not a tax invoice": CANCELLED when the reservation is Cancelled, NO-SHOW when every unit is NoShow. A mixed reservation gets no stamp; each unit shows its own status in Rooms. Either way it prints the fees and payments it holds.
- **Totals differ from the money strip.** The [money strip](06-reservation-page.md#money-strip) shows **Room total** (room nights only, without tax and add-ons, labelled so). The booking summary Total includes everything that will be charged, and the reservation page [summary card](06-reservation-page.md#summary-card) shows the same Total from `BookingSummary`. This is the wastays lesson: a summary that missed extra charges showed a false credit.
- **Currency** is the property currency, printed once in the Total and Balance due labels.

## Route and entry

| Item  | Value                                                                                                                                                                                                                                                     |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Route | `GET /p/{code}/reservations/{reservation}/booking-summary` (`reservations.booking-summary`, `BookingSummaryController`), `?prices=0` for the print without prices (only for a reservation with a company; otherwise ignored)                              |
| PDF   | Streams inline as `booking-summary-{reference}.pdf` through `PrintedPdf`, view `resources/views/pdf/booking-summary.blade.php`                                                                                                                            |
| Menu  | **⋯ More** on the reservation page: **Print booking summary**, plus **Print without prices** when the reservation has a company. Both open the PDF in a new tab. Shown on closed reservations too, and to a user who can view but not manage reservations |

## Tests

- Total equals the sum of the Projected totals of the folios that are not Voided plus their payments, and Balance due equals Total minus payments minus billed to company, for a single room, a MasterPays group, a group with a third-party folio and a reservation with a transferred charge.
- Paid and billed to company match `ReservationDirectory`, and the balance does once nothing is Scheduled.
- A posted night and Scheduled nights at the same price merge. The Room column shows for two units and hides for one.
- `?prices=0` is ignored without a company. Your ref is left out when empty. The Malay policy text falls back to English.
- A reversed line and its reversal are both left out. A Cancelled unit with a fee shows the fee.
- Every unit a no-show with no fee: NO-SHOW stamp, both units in Rooms with "No-show", "No charges", no Total and no Balance due. The same with a payment held: Balance due as a Credit.
- Without prices prints no amounts.
- A reservation of another property is a 404. A user without `reservations.view` is a 403.

## Later

- **Binding quote**: a quote number and a stored snapshot, only when a hotel needs to prove what was offered.
- **Agent payment schedule** (wastays prints one): estatell has no payment schedule yet.
