# Folio page

## Purpose

Show a folio as staff read it: what has posted, and what will post.

## Rows

The server builds one list. The browser only draws it.

| State     | Source                                                   | In the balance |
| --------- | -------------------------------------------------------- | -------------- |
| Posted    | `folio_lines`                                            | Yes            |
| Scheduled | `unit_nights` and `unit_add_ons` with no posted line yet | No             |

- There is no forecast table. A night is Scheduled until its `night:{unit_night_id}` line exists. A nightly add-on is Scheduled until its row has a `folio_line_id`.
- A Confirmed day use unit shows its block as a Scheduled DAY_USE row with key `dayuse:{unit_id}` and the description "Day use · 10:00–14:00", with the plan's inclusions split from the price and the DAY_USE taxes (`DayUseChargeQuote`). It stays Scheduled until a line with that key exists (check in posts it, see [Day use](../12-reservations/10-day-use.md#charging)).
- When the night audit posts a night, the same key matches, so the row moves from Scheduled to Posted. It cannot show twice.
- Scheduled rows are muted and carry the Scheduled marker (see [Row markers](#row-markers)). Tax on them is calculated live with `TaxCalculator`. Nothing is stored.
- With `MasterPays`, the unit folio shows no Scheduled room rows. The master folio shows them (needs [Routing](03-routing.md)).

## Layout

The folio panel has four rows: the title (folio name and status, with the folio number under it ("Folio BSAA-26300001", and "· Invoice …" once the folio has an invoice number), and Reopen or Close folio on the right), the figures, the action row, then the rows table. The action row holds one **More actions** dropdown on the left (an ellipsis icon and a chevron), with labelled groups split by a separator: **Charges** (Move charges), **Routing** (Routing rules, Third-party folio) and **Manual** (Manage manually…, for `folio.manual` on an Open folio; see [Manual Folio Editor](08-manual-folio.md#entry)). A group the user cannot use is hidden with its separator, and the dropdown is hidden when both are. The folio's own actions sit on the right and the folio's own actions on the right (Apply discount, Record payment, Post charge). **Settle to City ledger** (outline, after Record payment) shows on an Open folio paid by a company with a balance above 0, to users with `city-ledger.invoice`; it asks for a confirmation that names the company and the amount, then closes the folio and issues the AR invoice ([AR invoices](../15-city-ledger/02-ar-invoices.md)). The whole panel scrolls. A reservation with no folio shows the More actions dropdown with only its Routing group above the empty state.

An icon-only button with a tooltip ("Hide summary" or "Show summary") sits after Close folio. It hides the reservation summary card on the right, and the folio panel takes its width, below `lg` too. The choice is remembered in the browser and applies on the Folio and Guests tabs only: the other tabs always show the summary, so it can never be lost. One state serves both tabs (`useSummaryHidden`, key `reservations.summary-hidden`; the old `reservations.folio-summary-hidden` is read once as a fallback; see [Guests tab](../12-reservations/06-reservation-page.md#guests-tab)). The page holds the state (`useSummaryHidden`) and passes `hideAside` to `Workspace`.

## Collapsing Scheduled rows

An **OLD SYSTEM · folio {legacy number}** section sits above POSTED when the folio has migrated lines. Its header is a button like SCHEDULED, shows the section total and starts **collapsed** ([Past and migrated stays](../12-reservations/12-past-and-migrated-stays.md#on-the-folio-tab)).

The Date column shows each line's `service_date`. A line whose `service_date` is before its `posting_date` adds a muted line under the date: "posted {posting date}". The voucher does the same. The invoice groups lines into date ranges, so it prints the service date only.

The SCHEDULED header is a button with a chevron and `aria-expanded`. It hides or shows all Scheduled rows. It starts open. While collapsed it shows "N rows hidden". The state is local to the table and is not remembered. The POSTED header is plain text. Balance and Projected total do not change.

## Row markers

A row can carry markers. The server sends them as `tags`, a list of `FolioRowTag` cases with their label and tone (`BadgeData`). The browser draws each one as an icon with the label as its tooltip and accessible name. It never writes a label itself.

| Marker     | Icon         | On                                                                           | Row treatment                                                                                                       |
| ---------- | ------------ | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Scheduled  | `Clock`      | Top-level Scheduled rows only                                                | Muted text                                                                                                          |
| Overridden | `PencilLine` | A Scheduled night with an override                                           | Warning tint                                                                                                        |
| Reversed   | `Ban`        | A reversed line and its reversed children                                    | Warning tint, muted text, whole row struck through                                                                  |
| Reversal   | `Undo2`      | A reversal line and its children                                             | Warning tint, muted text                                                                                            |
| Refund     | `RotateCcw`  | A refund payment row                                                         | Info tint                                                                                                           |
| Manual     | `PencilLine` | A line with a `manual_batch_id`, shown only with `folio.view-manual-entries` | None. Its popover shows the user, the time and the reason ([Manual Folio Editor](08-manual-folio.md#manual-marker)) |

- A row takes a light background tint from the tone of its first marker that has one (Overridden, Reversed and Reversal: warning, Refund: info), so a reversed line and its reversal read as a pair. Neutral markers leave the row plain. The icon and tooltip still carry the meaning, so color is never alone.
- Scheduled discount and tax rows show no marker: the indent, the muted text and the section header already say it.

## Figures

- **Balance**: posted lines only (`FolioBalance`, `SUM(folio_lines.amount)`). Charges and taxes are positive, discounts and payments negative, and a refund is positive. A reversal cancels its original. A positive Balance is what the payer still owes, and a negative one is a deposit or an overpayment.
- **Projected total**: Balance plus the sum of the Scheduled rows (with their tax and discounts). It is the folio total if nothing changes and nothing else is paid. It is not an amount due.

Decision: **Balance never includes Scheduled rows.** A Scheduled row is a forecast and not a ledger line. It can still change (price override, stay change, cancellation) or drop before it posts. Counting it would show the guest owing nights that may change, and would stop a folio from closing at 0.00 until the nights post. Projected total is the combined view. The same Balance feeds the summary card (all folios that are not Voided) and the reservations list. A "not started" entry has no Balance and shows its Projected total.

## Discounts on a Scheduled night

A Scheduled night shows its discount as a child row, in the same order as a posted night: room, discount, tax.

| Line                 | Amount |
| -------------------- | ------ |
| Room · BAR Room Only | 280.00 |
| ↳ Staff rate (10%)   | −28.00 |
| ↳ Service Tax 8%     | +20.16 |
| Night total          | 272.16 |

- Tax is on the discounted amount.
- `ScheduledFolioRows` reads each night from `NightChargeQuote`, the same service `PostRoomCharges` posts from (see [Posting charges](02-posting-charges.md#room-charge-pipeline-per-unit-night)).
- A Scheduled night can carry the rate plan stay discount (`unit_nights.discount_amount`) and the booking-time discount (`reservation_units.discount_id`).
- A folio discount (`ApplyDiscount`) never targets a Scheduled night. Eligible lines are posted lines only. A Scheduled row has no discount action.

## Overridden nights

`unit_nights.amount` is already net of the stay discount: `amount = COALESCE(override_amount, base + extra + supplement − discount_amount)` (database CHECK). A Scheduled night is drawn from its parts, so the stay discount is a child row.

- A night with an `override_amount` has no breakdown, because the override replaces the whole sum. It shows as one row with the Overridden marker, with no discount child.
- The booking-time discount (`reservation_units.discount_id`) is not part of `amount`. It follows the child row rule above.

## Page and route

The folio is the fifth tab of the [reservation page](../12-reservations/06-reservation-page.md), after Units, Guests and Add-ons and before Activity.

| URL                                              | Name                 | Page                          |
| ------------------------------------------------ | -------------------- | ----------------------------- |
| `GET /p/{code}/reservations/{reservation}/folio` | `reservations.folio` | `pages/reservations/show.tsx` |

- It follows `renderTab()`: only this tab's data is read and sent. `?folio=` picks the folio and survives a refresh.
- **Read permission: `reservations.view`.** There is no `folio.view`. Owner, Manager, Front desk and Finance hold it. Each action needs its own permission (see Actions below).
- The summary card's Balance becomes real: the sum of the balances of the reservation's folios that are not Voided.
- The Cards and List money strip shows Total, Paid and Balance (see [Money strip](../12-reservations/06-reservation-page.md#money-strip)).

## Folio rail

A left rail lists the folios of the reservation, the same way the Units tab lists units. The rail shows only for 2 or more entries. On a phone it becomes a select above the content.

```
┌────────────────────┐ ┌──────────────────────────────────┐
│ FOLIOS             │ │ Master · Acme Sdn Bhd  F-0031    │
│ ▌Master · Acme     │ │ ● Open                           │
│  ● Open   272.16   │ │ [Balance] [Projected] [Lines]    │
│  Unit 101 · Tan    │ │ [+ Post charge] [% Discount] ... │
│  ● Open   12.00    │ │ rows (Posted, then Scheduled)    │
│  Unit 103          │ └──────────────────────────────────┘
│  not started       │
└────────────────────┘
```

| Rail item | Shows                                                                                   |
| --------- | --------------------------------------------------------------------------------------- |
| Name      | Master with its payer, Unit with its room and guest, Third party with its payer         |
| Status    | `StatusBadge` of `FolioStatus`                                                          |
| Balance   | Posted balance of that folio, in the danger tone when above 0                           |
| Order     | Master, then Unit folios in unit order, then Third party folios, then not-started units |

- **Not started:** a unit with no folio gets a read-only entry labelled "Unit X · not started". It shows the unit's Scheduled rows and no Balance. Its only action is Record payment, which creates the unit's folio on the first payment ([Payments and refunds](../14-payments/01-payments-and-refunds.md)). When Room charges route to the Master folio, the entry has no valid purpose before arrival, so it shows no Record payment: staff take the advance on the Master folio ([Purposes by stage](../14-payments/01-payments-and-refunds.md#purposes-by-stage)). Nothing else creates a folio to show it. A folio is created on the first posting (see [Posting charges](02-posting-charges.md)).
- The default folio is the Master when it exists, otherwise the first Unit folio.
- The rail takes about 200px, so the Room column is hidden while the rail names the unit.
- A Closed folio is read only: no post, discount, move or reverse actions, and its menus are hidden. It shows the invoice number and a notice above the rows that says what to do, with **Reopen folio** when the user may reopen it (see [When to reopen](01-folios-and-lines.md#close-and-reopen)):

```
┌──────────────────────────────────────────────────────────┐
│ ℹ Closed at check-out today, 11:42.                      │
│   To add a charge, refund or fix a line, reopen it.      │
│                                        [ Reopen folio ]  │
└──────────────────────────────────────────────────────────┘
```

- `canReopen` follows `FolioReopenRule`: `folio.reopen`, or `front-desk.check-out` for a Unit folio closed during the current business date. The `reservations.folios.reopen` route checks the same rule instead of `can:folio.reopen` alone.

## Which folio shows a Scheduled row

`FolioRouter::targetFor()` already writes nothing, and it returns null for a unit with no folio. The page uses it for every Scheduled row, with the room revenue code for a night and the charge item's code for an add-on.

- A row whose target is a folio shows on that folio. With `MasterPays`, the unit folio shows no Scheduled room rows and the master folio shows them.
- A row with a null target shows on the unit's "not started" entry.
- The page loads the reservation's routing rules once and routes in memory. If the query-count test needs it, add a batch method to `FolioRouter` and keep `targetFor()` as it is.

## Actions

Row and folio actions call the existing Actions. A refusal writes and logs nothing. A form (post charge, apply discount) shows the refusal in its Dialog, on the matching field or as one message for the form, like the add-on dialog. An AlertDialog (reverse, remove discount) has no field, so it shows an error toast.

| Action              | Permission                                                                               | Shown on                                                                                                                                                                                           |
| ------------------- | ---------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Post charge         | `folio.post-charge`                                                                      | An Open folio with at least one unit that can receive a posting                                                                                                                                    |
| Reverse             | `folio.reverse`                                                                          | A Posted charge (not a child, a reversal or an already reversed one)                                                                                                                               |
| Correct price…      | `reservations.change-price`                                                              | A posted room line of a night, on an Open folio. Opens [Change price](../12-reservations/09-change-night-price.md) for that night                                                                  |
| Move…, Move charges | `folio.transfer`                                                                         | **Move…** on a Posted charge on an Open folio, **Move charges** in the folio header's More actions dropdown, when another Open folio exists. Both open the [Move charges](07-move-charges.md) page |
| Apply discount      | `folio.discount`, `folio.discount-open`                                                  | An Open folio with eligible charges                                                                                                                                                                |
| Remove discount     | `folio.discount`                                                                         | A discount child row that is not reversed                                                                                                                                                          |
| Close, Reopen       | `folio.close`, `folio.reopen` (or same-day for a Unit folio with `front-desk.check-out`) | The folio header                                                                                                                                                                                   |
| Record payment      | `payment.record`                                                                         | The folio header of an Open folio, and a "not started" entry, when the folio has at least one purpose                                                                                              |
| Refund              | `payment.refund`                                                                         | A Completed payment row with an amount left to refund                                                                                                                                              |
| Print voucher       | `folio.print-voucher`                                                                    | The Print dropdown in the folio header, and the row menu of a printable posted line                                                                                                                |
| Third-party         | `folio.transfer`                                                                         | The action row, for the reservation                                                                                                                                                                |
| Routing rules       | `folio.transfer`                                                                         | The action row, for the reservation                                                                                                                                                                |

A Scheduled row has no actions except Print voucher. A "not started" entry has only Record payment and Print voucher.

### Routes

One `ReservationFolioController` with named methods. The routes sit in the reservation group with `scopeBindings()`, so a folio or line of another reservation or property returns 404 (`Reservation` gets a `folios()` relation for it). A Form Request checks the shape only.

| URL                                                          | Name                                    | Action           | Permission            |
| ------------------------------------------------------------ | --------------------------------------- | ---------------- | --------------------- |
| `POST …/{reservation}/folios/{folio}/charges`                | `reservations.folios.charges.store`     | `PostCharge`     | `folio.post-charge`   |
| `POST …/folios/{folio}/lines/{line}/reversal`                | `reservations.folios.lines.reverse`     | `ReverseLine`    | `folio.reverse`       |
| `GET …/folios/{folio}/voucher?lines[]=…&scheduled[]=…` (PDF) | `reservations.folios.voucher`           | `FolioVoucher`   | `folio.print-voucher` |
| `GET …/units/{unit}/voucher?scheduled[]=…` (PDF)             | `reservations.units.voucher`            | `FolioVoucher`   | `folio.print-voucher` |
| `POST …/folios/{folio}/discounts/quote` (JSON)               | `reservations.folios.discounts.quote`   | `DiscountQuote`  | `folio.discount`      |
| `POST …/folios/{folio}/discounts`                            | `reservations.folios.discounts.store`   | `ApplyDiscount`  | `folio.discount`      |
| `DELETE …/folios/{folio}/lines/{line}/discount`              | `reservations.folios.discounts.destroy` | `RemoveDiscount` | `folio.discount`      |

Record payment and Refund have their own `ReservationPaymentController` and routes, listed in [Payments and refunds](../14-payments/01-payments-and-refunds.md#routes).

- **Refusal messages:** `FolioRefusals` maps each `PostingResult` refusal code (and the two tax refusals) to a message and a field, the way `ReservationRefusals` does. A form gets `errors()`, and an AlertDialog gets `message()` as a toast (`Inertia::flash('toast', ...)`).
- **Server-driven buttons:** the server tells each row which actions it allows, from the user's permissions, the folio status and the row type. The browser never works it out. `FolioRowData` gets the line id and an `actions` list. `FolioData` gets `canPostCharge`, the units that can receive a posting, and `canMoveCharges` (an Open folio with another Open folio, for a user with `folio.transfer`).
- **Option lists:** charge items and active discounts are optional props, read when a dialog opens, like the add-on dialog. `chargeCodes` is read only by the Routing rules sheet.

### Post charge

- The dialog picks a charge item, then quantity, amount (when the item allows one) and an optional description. There is no "Other amount" and no code picker: staff never choose a transaction code. A one-off charge uses the closest item and its own description, for example General fee, "Broken glass". A repeatable charge belongs in the [charge item catalogue](../06-catalogues/02-charge-items.md).
- A description replaces the item name on the line. The placeholder shows the item name.
- **Folio** select first: the reservation's Open folios (Master, Third-party and Unit), labelled with type and payer ("Master · Borneo Ventures Sdn Bhd", "Unit 207 · Vincent Majalap"). It defaults to the folio the Dialog was opened from. Closed folios are not listed. A unit that can take a posting but has no folio yet is listed last as "Unit 102 · new folio"; choosing it posts to that unit's own folio and creates the folio (`folio_id` is sent empty with the unit). A third-party folio of a guest who is on exactly one postable unit starts with that unit in the Room select (`PostFolioOptionData.defaultUnitId`); staff can change it. The charge lands on the chosen folio; routing does not apply to Post charge.
- **Room** select: units that are CheckedIn or CheckedOut, so the line keeps its room for grouping and the documents. A Unit folio fixes the room to its own and disables the select. A Master or Third-party folio lets staff pick; with one room it is preselected and still shown.
- No warning when a routing rule would have chosen another folio: the staff member picked the folio.
- The server sends the folio options (`FolioData.postFolios` or the page's folio list) and the rooms; the success toast names the folio that received the charge.
- The form sends `manual:{uuid}` as the idempotency key, so a double click posts once.

### Reverse, remove discount

An AlertDialog names the target. No reason field: none of the Actions takes one.

### Move

Moving charges has its own full page: [Move charges](07-move-charges.md). **Move…** in a row menu opens it with that line ticked and another Open folio as the target (see [Move charges](07-move-charges.md#shape)); **Move charges** in the folio header opens it with this folio as the target. It replaced the single-row Transfer Dialog and its `reservations.folios.transfers.store` route (removed). Both entry points show when the row or the folio has `canMoveCharges` / the `move` action. **Move…** links to `reservations.folio.move?line={id}` and the page picks the starting target; **Move charges** links with `?to={folio}`.

### Apply discount

Two steps, as the quote requires. The dialog picks a discount (and an amount when the discount is Open, or Fixed with `allow_override`), asks the quote route, shows the eligible charges and the amount, then applies with the quote's `fingerprint`. A changed ledger refuses with `quote_changed`, and the dialog asks for a new quote.

**Open discount permission.** `ApplyDiscount` does not check permissions. An Open discount, or any amount that staff enter or change, needs `folio.discount-open`. A route middleware cannot tell, so the Form Request of the store and quote routes checks it. This is the one exception to "route middleware only"; the Percent and unchanged Fixed paths need only `folio.discount`.

## Print voucher

A voucher is a printed slip of any posted lines and Scheduled rows, for example a minibar slip the guest signs, or the tourism tax lines printed before the night audit posts them. It is one generic document: there is no tourism tax version, so staff tick the lines they want. It writes nothing and has no number. The folio number, the reservation reference and the posting dates identify it.

```
┌─ Print voucher ───────────────────────────────────────────────── ✕ ┐
│ Quick select  (Charge) (Adjustment) (Tax)  [Clear]                 │
│ ☑ Date         Description                          Amount         │
│ ☑ 04 Oct 2026  Island hopping trip               MYR 360.00        │
│ ☑              ↳ Tourism tax                      MYR 30.00        │
│ ☐ 04 Oct 2026  Laundry                            MYR 10.00        │
│ 2 lines selected                         Total MYR 390.00          │
│                                   [Cancel]   [🖨 Print voucher]    │
└────────────────────────────────────────────────────────────────────┘
```

- **Entry points:** **Print voucher** in the **Print** dropdown of the folio header opens the Dialog (the dropdown also holds Print invoice, see [Folio invoice](06-folio-invoice.md)). **Print voucher** in the row menu of a posted line opens it with that line and its children already ticked.
- **Which rows are listed:** posted lines that are not a payment (a payment has its receipt), not a reversal and not reversed, then the Scheduled rows of the open entry under a "Scheduled, not posted yet" heading. A tax or discount row can be ticked on its own, because a voucher only reads. The server sends `FolioRowData.printable` (true for a user who holds `folio.print-voucher`) and `kind` (a Scheduled row's kind comes from its key: `:tax:` is Tax, `:discount` and `:stay-discount` are Adjustment, anything else is Charge).
- **Scheduled rows on the PDF:** they print like any other row, with no "Scheduled" heading, note or subtotal, because a hotel hands the voucher over as it is. Only the Dialog marks them. Posted rows come first in ledger order, then Scheduled rows by date, and the Total is one sum.
- **Indent:** a discount or tax row is indented only when its parent charge is on the voucher too. Printed alone, it stands at the top with its date.
- **A unit with no folio yet:** its "not started" entry can print a voucher of its Scheduled rows, so a hotel can hand over a tourism tax voucher before anything posts. It uses `GET …/units/{unit}/voucher?scheduled[]=…` (`reservations.units.voucher`, same permission). The PDF has no folio number and uses the reservation reference. A row that is not that unit's "not started" row is a 404, and `lines[]` is a 422.
- **Quick select:** one button per `TransactionKind` except Payment (`voucher.kinds`). It ticks every listed line of that kind, and a second click unticks them.
- **Limit:** up to `FolioVoucher::MAX_LINES` (100) rows, posted and Scheduled together. The Dialog disables Print above it and the request refuses it.
- **Closed folio:** printing is still allowed, like the receipt.
- **Route:** `GET …/folios/{folio}/voucher?lines[]=…&scheduled[]=…` (posted line ids and Scheduled row keys) streams the PDF inline as `voucher-{folio number}.pdf` in a new tab. The Dialog stays open, so the selection can change and print again. A line or Scheduled row that is not on the folio is a 404. A payment, a reversal or a reversed line is a 422 (the Dialog never offers one). An empty or oversized list is a validation error.
- **Document:** `FolioVoucher` (service, read only) builds a `FolioVoucherDocument`. The letterhead and footer come from [Printing settings](../02-property/05-printing-settings.md) (`PrintProfile`), shared with the receipt. The view is `resources/views/pdf/voucher.blade.php` (A4, table layout, dompdf): the property, "Voucher", the folio number and date, a guest block ("Billed to" with the company when a company pays the folio, then name, nationality, reservation, stay), the chosen rows with children indented under their parent, the signed total, and signature lines. It prints two copies, "Guest copy" and "Hotel copy". The guest is the folio payer, or the booker.
- **Permission:** `folio.print-voucher` for Owner, Manager, Front desk and Finance. It also covers the [folio invoice](06-folio-invoice.md), so its label is "Print folio vouchers and invoices".

## Routing rules and third-party folios

Two reservation-level actions sit in the **Routing** group of the **More actions** dropdown in the action row, before the folio's own actions. The rules and decisions are in [Routing](03-routing.md#staff-screens).

```
┌────────────────────┬──────────────────────────────────────────────────┐
│ FOLIOS             │ Third party · Blue Reef Dive  ●Open              │
│  Master · Acme     │ Invoice F-0034                                   │
│  Unit 101 · Tan    │ [Balance] [Projected total] [Posted lines]       │
│ ▌Third party ·     │ [⋯ More actions ▾]               [Record …]      │
│   Blue Reef Dive   │ ...                                              │
└────────────────────┴──────────────────────────────────────────────────┘
```

**Third-party folio** opens a Dialog with the payer: Guest or Company (choice cards; Company shows only with `company.view`), then `GuestPicker` or `CompanySelect` (Active companies, the existing optional `companies` prop). It sends `payer_guest_id` or `payer_company_id`, never both. A Suspended or foreign company is a validation error on `payer_company_id`: "This company is not available. Pick an active company." On success the tab opens the new folio (`?folio=`) and a toast names the payer. **Settle to City ledger** works on a third-party folio with a company payer, also while units are in-house.

**Routing rules** opens a Sheet:

```
┌ Routing rules ──────────────────────────────────────────┐
│ Unit       Charges            Goes to            Source │
│ All units  Room               Master · Acme  Billing    │  read only
│ All units  DIVE Diving trip   Third party ·  Manual [🗑]│
│                               Blue Reef Dive            │
│ Unit 101   Food and beverage  Unit 102 · Lee Manual [🗑]│
│                                                         │
│ Add rule                                                │
│ Unit     [All units                 ▾]                  │
│ Charges  (•) Category [Food and beverage ▾]             │
│          ( ) Charge code [DIVE Diving trip ▾]           │
│          ( ) All charges                                │
│ Goes to  [Third party · Blue Reef Dive ▾]  Open folios  │
│ ⓘ Only later postings follow a new rule. Posted lines   │
│   stay; use Move charges.                               │
│                                          [Add rule]     │
└─────────────────────────────────────────────────────────┘
```

- The rules and the form options (units, categories, charge codes, Open folios) are one optional prop, read when the Sheet opens, like `chargeItems`.
- Remove asks in an AlertDialog ("Remove routing rule?"). A refusal shows as an error toast. A refusal in the add form shows on its field.

| URL                                           | Name                                    | Action                       | Permission       |
| --------------------------------------------- | --------------------------------------- | ---------------------------- | ---------------- |
| `POST …/{reservation}/folios/third-party`     | `reservations.folios.third-party.store` | `CreateFolio::thirdParty`    | `folio.transfer` |
| `POST …/{reservation}/routing-rules`          | `reservations.routing-rules.store`      | `CreateRoutingRule` (Manual) | `folio.transfer` |
| `DELETE …/{reservation}/routing-rules/{rule}` | `reservations.routing-rules.destroy`    | `RemoveRoutingRule`          | `folio.transfer` |

## Build order

Four commits, in order.

1. **Read-only tab (built):** `FolioStatement` (service, read only), `FolioData` and `FolioRowData`, the route, the rail, the rows, Balance and Projected total, and the real Balance in the summary card. Tests: row rules, the Scheduled-to-Posted key match, routing of Scheduled rows, query count with lazy loading prevented, permissions and 404 across properties.
2. **Post, reverse and transfer:** the controller, routes, Form Requests, refusal messages, row `actions` and the three dialogs.
3. **Discounts:** the quote route, the apply dialog with its two steps, remove discount, and the discount-open check.
4. **Close and reopen buttons.**

## Build status

Built (routing rules and third-party folios): `RoutingRuleList` (service, two queries), `RoutingRulesData` and `RoutingRuleRowData`, the `canManageRouting` prop and the `routingRules` optional prop on the folio tab (null without `folio.transfer`), `ThirdPartyFolioDialog`, `RoutingRulesSheet`, `RemoveRoutingRuleDialog`, and `FolioStatement::optionLabel()` for the folio names in the selects.

Decisions made at build time (routing rules and third-party folios):

- The two actions sit in the Routing group of the More actions dropdown (action row, below the figures) and also show when the reservation has no folio yet, so a third-party folio can be made before any charge.
- The Routing rules button shows no count: the rules are an optional prop, and a count on every page load is not worth a query.
- The Sheet reads `routingRules` again each time it opens and after each add or remove, so it never shows a folio or rule that changed in the meantime. Units come from the page's `units` prop, and charge codes from the `chargeCodes` optional prop.
- Rules are listed in the order `FolioRouter` tries them: all units first, then by unit, and a charge code before a category before all charges.
- The remove AlertDialog opens over the Sheet, as the discard-changes dialog does over Attach guest.
- `GuestPicker` moved to the `components/pms/` root, where the component folders doc already lists it, because a third module uses it now.

Built (close and reopen): the routes `reservations.folios.close` and `reservations.folios.reopen` (`can:folio.close`, `can:folio.reopen`), `ReservationFolioController::close` and `reopen`, the `FolioStatusResult` refusal messages in `FolioRefusals`, `FolioData.canClose` and `canReopen`, `CloseFolioDialog` and a Reopen button in the folio header.

Decisions made at build time (close and reopen):

- `canClose` is true for an Open folio and `canReopen` for a Closed one, when the user holds the permission. The Close button shows whatever the balance is, and the Action refuses a balance other than 0 with an error toast ("Only a folio with a balance of 0.00 can be closed. Settle it or move the charges first."). The Dialog says a balance of 0 is needed.
- Close asks for a confirmation, because it issues the invoice number and stops postings. Reopen has no confirmation: it only opens the folio again, and the invoice number stays.
- A closed folio shows its invoice number in the header. Its rows show no actions, and there is no Post charge or Apply discount button.
- The in-house check for a Master folio is still not built (see [Folios and lines](01-folios-and-lines.md#close-and-reopen)).

Built (discounts): the routes `reservations.folios.discounts.quote` (JSON), `reservations.folios.discounts.store` and `reservations.folios.discounts.destroy` (each with `can:folio.discount`), `QuoteFolioDiscountRequest` and `StoreFolioDiscountRequest`, `FolioDiscountOptionData`, `FolioDiscountQuoteResponseData`, the optional prop `folioDiscounts`, `FolioData.canApplyDiscount`, the `remove_discount` row action, and the apply and remove dialogs.

Decisions made at build time (discounts):

- The quote route returns the quote and an `error` message. A quote with no eligible charge or a refused amount still answers 200 with its message, so the Dialog can show it. A discount of another property, or an inactive one, is a 404. No open business date is a 422.
- The Form Request of the quote and store routes checks `folio.discount-open`: an Open discount always needs it, a Fixed discount needs it only when the amount differs from its value, and a Percent discount never does. Without it the route answers 403.
- The page sends the discount list as `folioDiscounts`. A user without `folio.discount-open` gets no Open discount, and `canEnterAmount` is false for them, so the Dialog never shows an amount field they cannot use. `canEnterAmount` is true for an Open discount, or a Fixed one with `allow_override`, when the user holds `folio.discount-open`.
- `canApplyDiscount` is true when the folio is Open, the user holds `folio.discount`, and a live charge with no live discount line exists. The quote still decides eligibility by scope, so a discount can answer "nothing eligible".
- The Remove discount action shows on a discount line (an Adjustment child of a charge) that is not reversed, on an Open folio, for a user with `folio.discount`.
- The Dialog has two steps. The first picks the discount (and the amount) and asks for the quote. The second shows the eligible charges with each share and the total, and applies with the quote's `fingerprint`. A refusal shows in the Dialog, including `quote_changed`, and Back asks for a new quote.

Built (post, reverse and transfer): `ReservationFolioController` with `storeCharge`, `reverse` and `transfer`, the routes `reservations.folios.charges.store`, `reservations.folios.lines.reverse` and `reservations.folios.transfers.store` (each with its own `can:`), `StoreFolioChargeRequest`, `StoreFolioTransferRequest`, `FolioRefusals`, `Reservation::folios()`, the `chargeCodes` optional prop, row `lineId` and `actions`, `canPostCharge`, `postUnits` and `transferTargets`, and the three dialogs. The transfer parts were replaced by the [Move charges](07-move-charges.md) page (`transfer`, `StoreFolioTransferRequest`, the route, the Dialog and `transferTargets` are gone; `canMoveCharges` and the row action `move` took their place). Nothing in the folio tab plan is left.

Decisions made at build time (actions):

- A row offers actions only when it is a Posted charge with no parent, no reversal and no marker, on an Open folio. Reverse needs `folio.reverse`. Move needs `folio.transfer` and at least one other Open folio. The list is empty otherwise.
- `postUnits` holds the CheckedIn and CheckedOut units (only the folio's own unit on a Unit folio). `canPostCharge` is true when the folio is Open, the user has `folio.post-charge` and that list is not empty. `canMoveCharges` is true only for a user with `folio.transfer`.
- The post charge Dialog posts by charge item, with an optional description. An item with a fixed price shows the price and sends it. (An earlier version also had "Other amount" with a charge code; it was removed because staff should not pick codes.)
- The success toast of a charge names the folio that received it. The Move charges toast names the target folio.
- A line, folio or target outside the reservation is a 404. `MoveCharges` takes the lines the Move charges page names.

Built (read-only tab): `reservations.folio` on `reservations.view`, `FolioStatement` and `ScheduledFolioRows` (services, read only), `FolioRouter::resolverFor()`, `FolioData`, `FolioRailItemData`, `FolioRowData`, the rail, the rows table, and the real Balance in the summary card (`ReservationData.balance`, null until the reservation has a folio). Not built at that step: every action (see Actions) and the Cards and List money strip, both built later.

Decisions made at build time:

- `FolioRouter::resolverFor(Reservation)` reads the rules and the unit folios once and returns a closure with the same choice as `targetFor()`. The page runs the same number of queries for 2 and for 8 units (tested).
- The rail lists folios that are not Voided. A "not started" entry exists only for a unit that has Scheduled rows with no target folio, so a unit whose rows route to the master gets no empty entry.
- Taxes of the property are read once with the ids of their codes. A Scheduled row uses the booker's nationality, as `PostCharge` does. A refused tax (for example a missing nationality) shows no tax rows.
- A Scheduled night shows the room at `base + extra + supplement`, then the stay discount (`discount_amount`), then the booking-time discount, then tax on what is left. A Percent booking-time discount takes its share of the night's net amount (after the stay discount). A Fixed one is taken once, from the first night of the unit, and never more than that night. An Open one shows nothing. The night audit must post the same amounts.
- A night with an `override_amount` is one row with the Overridden marker, with no discount rows and the tax on the override.
- An add-on with no `service_date` shows on the unit's arrival date.
- Posted rows follow the ledger: each charge or payment, then its children. A line with a reversal gets the Reversed marker, and a reversal line gets the Reversal marker. A posted row names its unit through the source of its line, or of its parent line for a tax or discount child (see [Posting charges](02-posting-charges.md#line-sources)). `FolioStatement` reads the units of all lines in one lookup for each source type (unit night, add-on, reservation unit), so the query count does not grow with the units. The Unit column shows on every folio type, for posted and Scheduled rows. A payment row has no unit.
- The Master is named "Master" and a third-party folio "Third party". The payer shows under the name: the guest, or the company. `FolioStatement` (rail and `optionLabel`), `RoutingRuleList` and `AddOnBilling` load `payerCompany` with `payerGuest`.
