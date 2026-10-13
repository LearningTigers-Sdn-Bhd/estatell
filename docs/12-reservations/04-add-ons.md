# Add-ons

## Purpose

Extras sold with a booking that are not part of the rate: airport transfer, birthday cake, extra bed, breakfast.

## Data model

```
unit_add_ons    id, reservation_unit_id, charge_item_id, quantity, unit_amount,
                service_date (nullable), property_age_group_id (nullable), timestamps
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

- **Split by price.** A PerGuest or PerGuestNight item with [child prices](../06-catalogues/02-charge-items.md#child-prices) writes one row per price instead of one row for everyone: adults (`property_age_group_id` null, the default amount) and one row per age group that has children and is not Free (its own amount). Example, Breakfast 30.00, Child 4–11 at 50%, Infant Free, for 2 adults, a child of 6 and a baby: adults 2 × 30.00 and Child 4–11 1 × 15.00, and no infant row. Nightly items do this for each night. A staff override of the amount applies to the adult row only.
- The folio line of a child row reads "Breakfast · Child 4–11" (`AddOnLabel`): the scheduled rows, the Add-ons tab and the posted line all use it.

`ChargeQuantityResolver` (`app/Services/Catalogues/`, one `match` returning row arrays) turns a charging unit and the unit's stay into these rows. For a per-guest item, `ChargeChildPrices::split()` maps the `reservation_unit_children` ages to age groups first (an age past the last group, or a child with no recorded age, pays the adult price) and the resolver writes the adult row and one row per billable group. Each row carries `age_group_id` and its `child_price`; `ChargeChildPrices::rowAmount()` prices it. `AddUnitAddOn` and `BookingQuote` both use them, so a quote matches what is written. A child row is `unit_add_ons.property_age_group_id`; it is set null if the age group is deleted. Nightly rows let each night post under its own `addon:{id}` key, and let an early departure drop the later nights.

## Price

- `unit_amount` is copied from the charge item's `default_amount` when the add-on is added. A later catalogue price change does not touch it.
- A different amount is allowed only when the item has `allow_override`, or has no default amount (then the amount is required).
- The amount is before tax. Tax comes from the [folio](../13-folio/01-folios-and-lines.md) when the add-on posts.

## Actions

No UI in this item. The Add-ons tab on the [reservation page](06-reservation-page.md) and Extras on the [booking form](05-booking-form.md) come with those items. Their routes check `reservations.manage`; the Actions do not check permissions.

### `AddUnitAddOn`

`(User $actor, ReservationUnit $unit, NewAddOnData $data): AddOnResult`. `NewAddOnData`: `charge_item_id`, `quantity` (PerItem only, default 1), `unit_amount` (optional), `service_date` (optional). It locks the unit row first, so a concurrent cancel cannot race.

| Check, in order                                                                                     | Refusal                       |
| --------------------------------------------------------------------------------------------------- | ----------------------------- |
| The charge item is active and belongs to the unit's property                                        | `charge_item_not_found`       |
| The unit is Confirmed or CheckedIn                                                                  | `unit_not_open`               |
| `service_date`, when given, is from arrival to departure, both included (non-nightly items)         | `service_date_outside_stay`   |
| `service_date`, when given, is not before the business date (non-nightly items)                     | `service_date_in_past`        |
| A nightly item has at least one night on or after the business date                                 | `service_date_in_past`        |
| Quantity is from 1 to 999                                                                           | `quantity_invalid`            |
| `unit_amount`, when given, is 0 or more with at most two decimals                                   | `amount_invalid`              |
| An amount different from `default_amount` needs `allow_override`; an item with no default needs one | `amount_override_not_allowed` |

- Nightly items (PerNight, PerGuestNight) ignore a given `service_date` and `quantity`: their rows always follow the stay nights, and only nights on or after the business date are written.
- An amount equal to the default (`50` and `50.00`) is not an override.
- An undated non-nightly add-on on a CheckedIn unit gets the business date as `service_date`, so it posts in that night's audit (check-in has already happened). On a CheckedIn **day use** unit the add-on posts at once through `PostCharge` (key `addon:{id}`, `folio_line_id` set), because the guest checks out before the audit and an unposted add-on would never post. A posting refusal removes the row and returns that code.
- The same item can be added twice to one unit. There is no unique index.
- It writes the rows and one activity entry (`reservation.add_on_added`, on the reservation), in one transaction. A refusal writes and logs nothing. `AddOnResult` holds the created add-ons or the refusal code.

### `RemoveUnitAddOn`

`(User $actor, UnitAddOn $addOn): bool`. Deletes one add-on row and logs `reservation.add_on_removed` on the reservation. Returns false, writing and logging nothing, when the row is already gone or the unit is not Confirmed or CheckedIn (add-ons of Cancelled, NoShow and CheckedOut units stay as history). A posted add-on (`unit_add_ons.folio_line_id` is set) is refused the same way (`add_on_posted`). The correction is `ReverseLine`.

### Booking with add-ons

`NewUnitData` gains `add_ons` (a list of `NewAddOnData`). `AddUnit` calls `AddUnitAddOn` for each one inside its transaction, so a booking stays all or nothing. An add-on refusal refuses the whole booking: `ReservationResult` passes the add-on code through and names the line. `AddUnit` rolls back its own unit too, so Add unit on the reservation page never leaves a unit behind. A bed line gives every bed the same add-ons.

## Added automatically

An item with **Add to every new booking** (`charge_items.auto_add`) is added to each new room as an ordinary add-on. `AutoAddOns` (service, `app/Services/Reservations/`) returns the active auto-add items as `NewAddOnData` for a unit.

| Where a room is added                | Auto-add                                                                                              |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Booking form and walk-in             | Prefilled in each room's Add-ons with an **Added automatically** tag and **×** to remove it           |
| **Add unit** on the reservation page | Added by the server (the Sheet has no add-ons); the toast names them: "Unit added with Cleaning fee." |
| Company portal booking               | Added by the server; the company user cannot remove them (comes with the portal item; not built yet)  |
| Channel booking                      | Not added. The OTA price and terms decide what the guest pays                                         |
| Reinstate                            | Not added again. The unit's old add-ons come back as they were                                        |
| Day use unit                         | PerStay, PerGuest and PerItem items only; nightly items have no nights                                |

- An auto-added add-on posts, prices and follows stay changes like any add-on.
- Removing one on the booking form is logged on the reservation: "Cleaning fee was not added (removed on the booking form)." The form sends the removed item ids as `declined_auto_add_ons`.
- The price follows the item's `allow_override` rule, like any add-on.
- On the booking form a switch to day use starts the lines again, so each line is seeded for the new stay type (nightly items drop out for day use). Picking another charge item on an automatic row counts as removing the automatic one.
- `LogDeclinedAutoAddOns` logs `reservation.auto_add_on_declined` for each removed item that is still an active auto-add item of the property. A stale or foreign id writes nothing.
- On Add unit, `AutoAddOns::items()` picks the items and the controller passes them as ordinary add-ons, so a refusal refuses the whole unit like any add-on.

## Rules

- Add-ons with a `service_date` post in the night audit for that date (`PostScheduledAddOns`, `addon:{id}` idempotency key).
- Add-ons without a date post at check-in (`CheckInUnit`). A CheckedIn unit never has undated add-ons (see above).
- Add-ons on a Cancelled or NoShow unit stay as history and never post.
- A [boat trip](../24-boats/02-boat-trips.md#fare) adds its fare as an undated add-on and links the rows in `boat_trip_add_ons`.
- Add-ons are not split out of the rate. Package inclusions are (see [Inclusions](../09-packages/01-inclusions.md)).
- Stay changes in [module 18](../18-front-desk/01-check-in-and-check-out.md) drop unposted dated rows outside the new dates and copy nightly rows (PerNight, PerGuestNight) for new nights (`AddNightlyAddOnRows`). Undated rows do not change.

## Build status

Built: `unit_add_ons` (with its CHECKs), `UnitAddOn`, `NewAddOnData`, `NewUnitData::$addOns`, `AddUnitAddOn`, `RemoveUnitAddOn`, `AddOnResult` and `ChargeQuantityResolver`. `AddUnit` calls `AddUnitAddOn` for each add-on of the line and rolls back its own unit on a refusal. The Add-ons tab, the booking form Extras and boat trips (`SyncBoatFare`) call them. Posting is built: `CheckInUnit` posts undated add-ons and `PostScheduledAddOns` posts dated ones in the night audit (module 13).

## Add-ons tab and the folio

The [Add-ons tab](06-reservation-page.md#add-ons-tab) reads the folio side without writing to it. `AddOnBilling` (`app/Services/Folio/`) returns, for each add-on, the folio that holds its line (posted) or the folio a routing rule will send it to (not posted, and only when it differs from the unit's own folio). `ReservationAddOnData` carries `posted`, `folioId`, `folioLabel`, `postedOn` and `routedTo`. A posted row hides Remove, since `RemoveUnitAddOn` refuses it (`add_on_posted`). Posting itself is still not built.

## Lessons from wastays

wastays posted extras as forecast folio rows, one per stay date for nightly charging units, priced from `hotel_extra_charges` with an amount override flag. estatell keeps the per-night rows and the override flag, but stores add-ons on the unit, not as forecast folio rows.
