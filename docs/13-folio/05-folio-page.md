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

## Open

- The page layout, the tab on the [reservation page](../12-reservations/06-reservation-page.md) and the routes are not designed yet.
