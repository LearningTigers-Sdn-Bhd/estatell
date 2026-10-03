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
- When the night audit posts a night, the same key matches, so the row moves from Scheduled to Posted. It cannot show twice.
- Scheduled rows are muted and tagged "Scheduled". Tax on them is calculated live with `TaxCalculator`. Nothing is stored.
- With `MasterPays`, the unit folio shows no Scheduled room rows. The master folio shows them (needs [Routing](03-routing.md)).

## Figures

- **Balance**: posted lines only (`FolioBalance`).
- **Projected total**: posted lines plus Scheduled rows.

## Discounts on a Scheduled night

A Scheduled night shows its discount as a child row, in the same order as a posted night: room, discount, tax.

| Line                 | Amount |
| -------------------- | ------ |
| Room · BAR Room Only | 280.00 |
| ↳ Staff rate (10%)   | −28.00 |
| ↳ Service Tax 8%     | +20.16 |
| Night total          | 272.16 |

- Tax is on the discounted amount.
- A Scheduled night can carry the rate plan stay discount (`unit_nights.discount_amount`) and the booking-time discount (`reservation_units.discount_id`).
- A folio discount (`ApplyDiscount`) never targets a Scheduled night. Eligible lines are posted lines only. A Scheduled row has no discount action.

## Overridden nights

`unit_nights.amount` is already net of the stay discount: `amount = COALESCE(override_amount, base + extra + supplement − discount_amount)` (database CHECK). A Scheduled night is drawn from its parts, so the stay discount is a child row.

- A night with an `override_amount` has no breakdown, because the override replaces the whole sum. It shows as one row tagged "Overridden", with no discount child.
- The booking-time discount (`reservation_units.discount_id`) is not part of `amount`. It follows the child row rule above.

## Page and route

The folio is the fifth tab of the [reservation page](../12-reservations/06-reservation-page.md), after Units, Guests and Add-ons and before Activity.

| URL                                              | Name                 | Page                          |
| ------------------------------------------------ | -------------------- | ----------------------------- |
| `GET /p/{code}/reservations/{reservation}/folio` | `reservations.folio` | `pages/reservations/show.tsx` |

- It follows `renderTab()`: only this tab's data is read and sent. `?folio=` picks the folio and survives a refresh.
- **Read permission: `reservations.view`.** There is no `folio.view`. Owner, Manager, Front desk and Finance hold it. Each action needs its own permission (see Actions below).
- The summary card's Balance becomes real: the sum of the balances of the reservation's folios that are not Voided.
- The Cards and List money strip (Charges, Paid, Balance) is a later small item.

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

- **Not started:** a unit with no folio gets a read-only entry labelled "Unit X · not started". It shows the unit's Scheduled rows, no Balance and no actions. Nothing creates a folio to show it. A folio is created on the first posting (see [Posting charges](02-posting-charges.md)).
- The default folio is the Master when it exists, otherwise the first Unit folio.
- The rail takes about 200px, so the Room column is hidden while the rail names the unit.
- A Closed folio is read only: no post, discount, transfer or reverse actions, and its menus are hidden. It shows the invoice number and a Reopen action.

## Which folio shows a Scheduled row

`FolioRouter::targetFor()` already writes nothing, and it returns null for a unit with no folio. The page uses it for every Scheduled row, with the room revenue code for a night and the charge item's code for an add-on.

- A row whose target is a folio shows on that folio. With `MasterPays`, the unit folio shows no Scheduled room rows and the master folio shows them.
- A row with a null target shows on the unit's "not started" entry.
- The page loads the reservation's routing rules once and routes in memory. If the query-count test needs it, add a batch method to `FolioRouter` and keep `targetFor()` as it is.

## Actions

Row and folio actions call the existing Actions. A refusal writes and logs nothing. A form (post charge, transfer, apply discount) shows the refusal in its Dialog, on the matching field or as one message for the form, like the add-on dialog. An AlertDialog (reverse, remove discount) has no field, so it shows an error toast.

| Action          | Permission                              | Shown on                                                             |
| --------------- | --------------------------------------- | -------------------------------------------------------------------- |
| Post charge     | `folio.post-charge`                     | An Open folio with at least one unit that can receive a posting      |
| Reverse         | `folio.reverse`                         | A Posted charge (not a child, a reversal or an already reversed one) |
| Transfer        | `folio.transfer`                        | A Posted charge on an Open folio, when another Open folio exists     |
| Apply discount  | `folio.discount`, `folio.discount-open` | An Open folio with eligible charges                                  |
| Remove discount | `folio.discount`                        | A discount child row that is not reversed                            |
| Close, Reopen   | `folio.close`, `folio.reopen`           | The folio header (the third item)                                    |

A Scheduled row has no actions. A "not started" entry has none either.

### Routes

One `ReservationFolioController` with named methods. The routes sit in the reservation group with `scopeBindings()`, so a folio or line of another reservation or property returns 404 (`Reservation` gets a `folios()` relation for it). A Form Request checks the shape only.

| URL                                             | Name                                    | Action           | Permission          |
| ----------------------------------------------- | --------------------------------------- | ---------------- | ------------------- |
| `POST …/{reservation}/folios/{folio}/charges`   | `reservations.folios.charges.store`     | `PostCharge`     | `folio.post-charge` |
| `POST …/folios/{folio}/lines/{line}/reversal`   | `reservations.folios.lines.reverse`     | `ReverseLine`    | `folio.reverse`     |
| `POST …/folios/{folio}/transfers`               | `reservations.folios.transfers.store`   | `TransferLines`  | `folio.transfer`    |
| `POST …/folios/{folio}/discounts/quote` (JSON)  | `reservations.folios.discounts.quote`   | `DiscountQuote`  | `folio.discount`    |
| `POST …/folios/{folio}/discounts`               | `reservations.folios.discounts.store`   | `ApplyDiscount`  | `folio.discount`    |
| `DELETE …/folios/{folio}/lines/{line}/discount` | `reservations.folios.discounts.destroy` | `RemoveDiscount` | `folio.discount`    |

- **Refusal messages:** `FolioRefusals` maps each `PostingResult` refusal code (and the two tax refusals) to a message and a field, the way `ReservationRefusals` does. A form gets `errors()`, and an AlertDialog gets `message()` as a toast (`Inertia::flash('toast', ...)`).
- **Server-driven buttons:** the server tells each row which actions it allows, from the user's permissions, the folio status and the row type. The browser never works it out. `FolioRowData` gets the line id and an `actions` list. `FolioData` gets `canPostCharge`, the units that can receive a posting, and the other Open folios as transfer targets.
- **Option lists:** charge items and active discounts are optional props, read when a dialog opens, like the add-on dialog.

### Post charge

- On a Unit folio the unit is fixed.
- On a Master or Third-party folio the dialog has a Unit select (units that are CheckedIn or CheckedOut). `PostCharge` takes a unit and `FolioRouter` picks the folio, so the dialog says that routing decides the folio, and the success toast names the folio that received the charge.
- The form sends `manual:{uuid}` as the idempotency key, so a double click posts once.

### Reverse, remove discount

An AlertDialog names the target. No reason field: none of the Actions takes one.

### Transfer

A single-row "Transfer…" in the row menu with a Select of the other Open folios of the reservation. The children travel with their charge. Selecting several lines with checkboxes can follow when front desk needs it.

### Apply discount

Two steps, as the quote requires. The dialog picks a discount (and an amount when the discount is Open, or Fixed with `allow_override`), asks the quote route, shows the eligible charges and the amount, then applies with the quote's `fingerprint`. A changed ledger refuses with `quote_changed`, and the dialog asks for a new quote.

**Open discount permission.** `ApplyDiscount` does not check permissions. An Open discount, or any amount that staff enter or change, needs `folio.discount-open`. A route middleware cannot tell, so the Form Request of the store and quote routes checks it. This is the one exception to "route middleware only"; the Percent and unchanged Fixed paths need only `folio.discount`.

## Build order

Four commits, in order.

1. **Read-only tab (built):** `FolioStatement` (service, read only), `FolioData` and `FolioRowData`, the route, the rail, the rows, Balance and Projected total, and the real Balance in the summary card. Tests: row rules, the Scheduled-to-Posted key match, routing of Scheduled rows, query count with lazy loading prevented, permissions and 404 across properties.
2. **Post, reverse and transfer:** the controller, routes, Form Requests, refusal messages, row `actions` and the three dialogs.
3. **Discounts:** the quote route, the apply dialog with its two steps, remove discount, and the discount-open check.
4. **Close and reopen buttons.**

## Build status

Built (discounts): the routes `reservations.folios.discounts.quote` (JSON), `reservations.folios.discounts.store` and `reservations.folios.discounts.destroy` (each with `can:folio.discount`), `QuoteFolioDiscountRequest` and `StoreFolioDiscountRequest`, `FolioDiscountOptionData`, `FolioDiscountQuoteResponseData`, the optional prop `folioDiscounts`, `FolioData.canApplyDiscount`, the `remove_discount` row action, and the apply and remove dialogs.

Decisions made at build time (discounts):

- The quote route returns the quote and an `error` message. A quote with no eligible charge or a refused amount still answers 200 with its message, so the Dialog can show it. A discount of another property, or an inactive one, is a 404. No open business date is a 422.
- The Form Request of the quote and store routes checks `folio.discount-open`: an Open discount always needs it, a Fixed discount needs it only when the amount differs from its value, and a Percent discount never does. Without it the route answers 403.
- The page sends the discount list as `folioDiscounts`. A user without `folio.discount-open` gets no Open discount, and `canEnterAmount` is false for them, so the Dialog never shows an amount field they cannot use. `canEnterAmount` is true for an Open discount, or a Fixed one with `allow_override`, when the user holds `folio.discount-open`.
- `canApplyDiscount` is true when the folio is Open, the user holds `folio.discount`, and a live charge with no live discount line exists. The quote still decides eligibility by scope, so a discount can answer "nothing eligible".
- The Remove discount action shows on a discount line (an Adjustment child of a charge) that is not reversed, on an Open folio, for a user with `folio.discount`.
- The Dialog has two steps. The first picks the discount (and the amount) and asks for the quote. The second shows the eligible charges with each share and the total, and applies with the quote's `fingerprint`. A refusal shows in the Dialog, including `quote_changed`, and Back asks for a new quote.

Built (post, reverse and transfer): `ReservationFolioController` with `storeCharge`, `reverse` and `transfer`, the routes `reservations.folios.charges.store`, `reservations.folios.lines.reverse` and `reservations.folios.transfers.store` (each with its own `can:`), `StoreFolioChargeRequest`, `StoreFolioTransferRequest`, `FolioRefusals`, `Reservation::folios()`, the `chargeCodes` optional prop, row `lineId` and `actions`, `canPostCharge`, `postUnits` and `transferTargets`, and the three dialogs. Not built: close and reopen.

Decisions made at build time (actions):

- A row offers actions only when it is a Posted charge with no parent, no reversal and no tag, on an Open folio. Reverse needs `folio.reverse`. Transfer needs `folio.transfer` and at least one other Open folio. The list is empty otherwise.
- `postUnits` holds the CheckedIn and CheckedOut units (only the folio's own unit on a Unit folio). `canPostCharge` is true when the folio is Open, the user has `folio.post-charge` and that list is not empty. `transferTargets` is sent only to a user with `folio.transfer`.
- The post charge Dialog posts by charge item or by "Other amount" with a charge code and a description (`chargeCodes` are the active Charge codes). A catalogue item with a fixed price shows the price and sends it.
- The success toast of a charge names the folio that received it. A transfer toast names the target folio.
- A line, folio or target outside the reservation is a 404. `TransferLines` takes the lines the form names (the dialog sends one).

Built (read-only tab): `reservations.folio` on `reservations.view`, `FolioStatement` and `ScheduledFolioRows` (services, read only), `FolioRouter::resolverFor()`, `FolioData`, `FolioRailItemData`, `FolioRowData`, the rail, the rows table, and the real Balance in the summary card (`ReservationData.balance`, null until the reservation has a folio). Not built: every action (see Actions), the Cards and List money strip.

Decisions made at build time:

- `FolioRouter::resolverFor(Reservation)` reads the rules and the unit folios once and returns a closure with the same choice as `targetFor()`. The page runs the same number of queries for 2 and for 8 units (tested).
- The rail lists folios that are not Voided. A "not started" entry exists only for a unit that has Scheduled rows with no target folio, so a unit whose rows route to the master gets no empty entry.
- Taxes of the property are read once with the ids of their codes. A Scheduled row uses the booker's nationality, as `PostCharge` does. A refused tax (for example a missing nationality) shows no tax rows.
- A Scheduled night shows the room at `base + extra + supplement`, then the stay discount (`discount_amount`), then the booking-time discount, then tax on what is left. A Percent booking-time discount takes its share of the night's net amount (after the stay discount). A Fixed one is taken once, from the first night of the unit, and never more than that night. An Open one shows nothing. The night audit must post the same amounts.
- A night with an `override_amount` is one row tagged "Overridden", with no discount rows and the tax on the override.
- An add-on with no `service_date` shows on the unit's arrival date.
- Posted rows follow the ledger: each charge or payment, then its children. A line with a reversal is tagged "Reversed", and a reversal line is tagged "Reversal". Posted lines carry no unit, so the Unit column shows only for Scheduled rows.
- The Master is named "Master" and a third-party folio "Third party". A guest payer shows under the name. A company payer shows no name until [Companies](../15-city-ledger/01-companies.md) exists.

## Open

- The Cards and List money strip waits for a later item.
- Posted rows cannot name their unit. Add a source to night and add-on lines (or the night audit's `source_type`) before the master folio needs a Unit column for posted rows.
