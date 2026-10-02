# Add-ons

## Purpose

Extras sold with a booking that are not part of the rate: airport transfer, birthday cake, extra bed, breakfast.

## Data model

```
unit_add_ons    id, reservation_unit_id, charge_item_id, quantity, unit_amount,
                service_date (nullable), timestamps
```

- Every add-on belongs to one unit. A group extra, such as one transfer for the whole group, goes on one unit.
- No `property_id`: the table is scoped through the unit, like `unit_nights`.
- `quantity` is at least 1 and `unit_amount` is 0 or more (database CHECKs).
- The total is `quantity × unit_amount`. It is calculated, never stored.

## Rows by charging unit

The charge item's `ChargingUnit` decides how many rows are written and their quantity:

| Charging unit | Rows written  | `service_date`                    | `quantity`        |
| ------------- | ------------- | --------------------------------- | ----------------- |
| PerNight      | One per night | Each night of the stay            | 1                 |
| PerGuestNight | One per night | Each night of the stay            | Adults + children |
| PerItem       | One           | Optional (null posts at check-in) | Entered by staff  |
| PerStay       | One           | Optional                          | 1                 |
| PerGuest      | One           | Optional                          | Adults + children |

`ChargeQuantityResolver` (`app/Services/Catalogues/`) turns a charging unit and the unit's stay into these rows. Nightly rows let each night post under its own `addon:{id}` key, and let an early departure drop the later nights.

## Price

- `unit_amount` is copied from the charge item's `default_amount` when the add-on is added. A later catalogue price change does not touch it.
- A different amount is allowed only when the item has `allow_override`, or has no default amount (then the amount is required).
- The amount is before tax. Tax comes from the [folio](../13-folio/01-folios-and-lines.md) when the add-on posts.

## Actions

No UI in this item. The Add-ons tab on the [reservation page](06-reservation-page.md) and Extras on the [booking form](05-booking-form.md) come with those items. Their routes check `reservations.manage`; the Actions do not check permissions.

### `AddUnitAddOn`

`(User $actor, ReservationUnit $unit, NewAddOnData $data): AddOnResult`. `NewAddOnData`: `charge_item_id`, `quantity` (PerItem only), `unit_amount` (optional), `service_date` (optional, not for nightly units).

| Check, in order                                                                        | Refusal                       |
| -------------------------------------------------------------------------------------- | ----------------------------- |
| The charge item is active and belongs to the unit's property                           | `charge_item_not_found`       |
| The unit is Confirmed or CheckedIn                                                     | `unit_not_open`               |
| `service_date`, when given, is from arrival to departure, both included                | `service_date_outside_stay`   |
| `service_date`, when given, is not before the business date                            | `service_date_in_past`        |
| Quantity is at least 1                                                                 | `quantity_invalid`            |
| An amount different from `default_amount` needs `allow_override`; no default needs one | `amount_override_not_allowed` |

Nightly rows are written only for nights on or after the business date. It writes the rows and one activity log entry, in one transaction. A refusal writes and logs nothing. `AddOnResult` holds the created add-ons or the refusal code.

### `RemoveUnitAddOn`

Deletes one add-on row and logs it. Nothing posts before module 13, so there is nothing to reverse. [Folios and lines](../13-folio/01-folios-and-lines.md) adds `folio_line_id` to `unit_add_ons` and refuses removing a posted add-on.

### Booking with add-ons

`NewUnitData` gains `add_ons` (a list of `NewAddOnData`). `AddUnit` calls `AddUnitAddOn` for each one inside its transaction, so a booking stays all or nothing. An add-on refusal refuses the whole booking: `ReservationResult` passes the add-on code through and names the line. A bed line gives every bed the same add-ons.

## Rules

- Add-ons with a `service_date` post in the night audit for that date (`PostScheduledAddOns`, `addon:{id}` idempotency key).
- Add-ons without a date post at check-in (`CheckInUnit`).
- Add-ons on a Cancelled or NoShow unit stay as history and never post.
- Add-ons are not split out of the rate. Package inclusions are (see [Inclusions](../09-packages/01-inclusions.md)).
- Stay changes in [module 18](../18-front-desk/01-check-in-and-check-out.md) re-run the quantities of PerGuest and PerGuestNight add-ons and drop nightly rows outside the new dates.

## Lessons from wastays

wastays posted extras as forecast folio rows, one per stay date for nightly charging units, priced from `hotel_extra_charges` with an amount override flag. estatell keeps the per-night rows and the override flag, but stores add-ons on the unit, not as forecast folio rows.
