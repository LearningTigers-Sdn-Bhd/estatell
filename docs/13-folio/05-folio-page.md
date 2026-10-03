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

Row and folio actions call the existing Actions. A refusal code becomes an error toast and writes nothing.

| Action          | Permission                              | Shown on                                                            |
| --------------- | --------------------------------------- | ------------------------------------------------------------------- |
| Post charge     | `folio.post-charge`                     | An Open unit, master or third-party folio that can receive postings |
| Reverse         | `folio.reverse`                         | A Posted charge (not a child)                                       |
| Transfer        | `folio.transfer`                        | A Posted charge on an Open folio                                    |
| Apply discount  | `folio.discount`, `folio.discount-open` | An Open folio with eligible charges                                 |
| Remove discount | `folio.discount`                        | A discount child row                                                |
| Close, Reopen   | `folio.close`, `folio.reopen`           | The folio header                                                    |

A Scheduled row has no actions.

## Build order

Three commits, in order.

1. **Read-only tab:** `FolioStatement` (service, read only), `FolioData` and `FolioRowData`, the route, the rail, the rows, Balance and Projected total, and the real Balance in the summary card. Tests: row rules, the Scheduled-to-Posted key match, routing of Scheduled rows, query count with lazy loading prevented, permissions and 404 across properties.
2. **Row actions:** post charge, reverse, transfer, apply and remove discount. Controllers, routes, dialogs and toasts.
3. **Close and reopen buttons.**

## Open

- Check the query count of the routing step on a 10-unit group before choosing between in-memory routing and a batch `FolioRouter` method.
- The Cards and List money strip waits for a later item.
