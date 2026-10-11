# Day use

## Purpose

Sell a room for a number of hours on one day, for example 10:00 to 14:00 for MYR 95, and have it back for the overnight guest that evening. Transit hotels, airport hotels and resorts sell it to travellers between flights or to a wedding party getting ready.

Day use is sold at the front desk only. It is never on the public site, the company portal or a channel.

## Decisions

| Decision    | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Set up      | A rate plan with the pricing basis **Day use** (see [Rate plans](../08-rates/01-rate-plans.md#day-use-plans)). Having an active Day use plan turns day use on in the booking form.                                                                                                                                                                                                                                                      |
| Price       | One price per number of hours, per room type and date, in `rate_hour_prices` (see [Daily rates](../08-rates/02-daily-rates-and-restrictions.md#day-use-prices)).                                                                                                                                                                                                                                                                        |
| Hours       | Whole hours from the plan's minimum to its maximum. Half hours are out.                                                                                                                                                                                                                                                                                                                                                                 |
| Room types  | Room types sold per room. Dorm beds are out.                                                                                                                                                                                                                                                                                                                                                                                            |
| Adults      | No price by adults. A Day use price is for the room.                                                                                                                                                                                                                                                                                                                                                                                    |
| Night model | Unchanged. A day use unit has **no `unit_nights`**, so it never takes night inventory and never touches the one-room-per-night rule.                                                                                                                                                                                                                                                                                                    |
| Tourism tax | Not charged by default: the day use code has its own taxes, seeded without the Tourism tax (see [Taxes](#taxes)). Customs agrees: day use is not subject to tourism tax unless the guest is charged the per-night room rate ([General Guide on Tourism Tax](https://www.myttx.customs.gov.my/wp-content/uploads/2022/05/APPENDIX-A-General-Guide.pdf), paragraph 13 and Example 12). Staff can add it on the Day use tab for that case. |
| Extending   | Changing the hours after booking is a later item. The hour prices already support it.                                                                                                                                                                                                                                                                                                                                                   |

## Data model

```
reservation_units   + stay_type (StayType, default Overnight)
                    CHECK Overnight: departure_date > arrival_date
                    CHECK DayUse:    departure_date = arrival_date
unit_day_uses       id, reservation_unit_id (unique FK), date, room_id (nullable FK),
                    starts_at (time), ends_at (time), hours, amount, released_at (nullable),
                    timestamps
                    CHECK ends_at > starts_at, hours from 1 to 24,
                          hours = (ends_at - starts_at) in whole hours
                    EXCLUDE USING gist (room_id WITH =, tsrange(date + starts_at, date + ends_at) WITH &&)
                          WHERE room_id IS NOT NULL AND released_at IS NULL
```

- `StayType`: Overnight, DayUse, with `label()`. A DayUse unit's length label is "Day use · 4h", never "0 nights", everywhere a stay length shows (reservation list and card, unit panel, reports, `StayTotal`).
- `unit_day_uses` holds the room, the hours and the price of the block. It is the day use counterpart of `unit_nights`. `amount` is the quoted price; `override_amount` is not offered for day use at first.
- `released_at` is set when the unit is Cancelled or NoShow and cleared on reinstate, so the exclusion constraint only covers live blocks. The constraint needs the `btree_gist` Postgres extension. The migration enables it with `Schema::ensureExtensionExists('btree_gist')`. It ships with standard PostgreSQL and is a trusted extension, so a user with CREATE on the database can install it.
- `arrival_time` and `departure_time` on the unit equal `starts_at` and `ends_at`, so the arrivals, departures and late check-out code read one place.

## Booking form

```
Stay type   (Overnight) (•Day use)
Date        [ 12 Oct 2026 ]   Start [ 10:00 ]
Room type   [ Deluxe King ▾ ]  Plan [ Day use ▾ ]
Hours       [ 4 hours · MYR 95.00 ▾ ]          Ends 14:00
Room        [ 101 ▾ ]   (rooms free from 10:00 to 14:00)
```

- The **Stay type** switch shows only when the property has an active Day use plan. It applies to every room on the form: a reservation is all overnight or all day use at first.
- **Date**: the business date or later. **Start**: on the hour or half hour, inside the plan's **Sold between** window.
- **Hours** lists every hour count the plan sells with that date's price, and leaves out those that would end after the window. A missing hour price is not listed.
- **Room** is required for day use: the room is the inventory. It lists the rooms of the type that are free for those hours (see [Availability](#availability)).
- The rest of the form (booker, guests, company, add-ons, notes) is the same as an overnight booking. `CreateReservation` and `AddUnit` take `stay_type`, `start_time`, `hours` and `room_id` for a day use line.

- The block ends on the same date. A walk-in day use takes the business date and starts at the next half hour.
- **Hours** come from `reservations.day-use-offers` (`DayUseOffers`), which lists the priced hours inside the window with their end times.
- No override total and no discount on a day use line.
- Add-ons: one-off items use the date as the service date. Nightly items are refused (`nightly_item_for_day_use`). A plan's inclusions are allocated as for one night.
- **Assign room** is refused on a day use unit: the room is chosen at booking. Until check in and out are built, a day use unit has no actions in the unit menu.

## Availability

`DayUseAvailability` (service, `app/Services/Availability/`) lists the rooms of a room type that are free on a date between two times. A room is free when it is active, not in an open room block on that date, and none of these overlaps the hours:

| Busy because                                            | Busy from – until                                                 |
| ------------------------------------------------------- | ----------------------------------------------------------------- |
| An overnight unit has the room on the night before      | Until the unit's `departure_time`, or the property check-out time |
| An overnight unit has the room on the night of the date | From the unit's `arrival_time`, or the property check-in time     |
| Another live day use has the room                       | Its `starts_at` to `ends_at`                                      |

- Overnight availability (`AvailabilityService`) does not change: a day use has no nights.
- An overnight unit with no room yet does not hold a specific room. `AutoAssignRooms`, `MoveUnit`, manual `AssignRoom` and the overnight room picks at booking skip a room with a live day use that overlaps the unit's arrival or departure time on that date.
- `AddUnit` locks the room row and checks again in the transaction. The exclusion constraint is the last guard.

## Pricing

`DayUsePricing` (`app/Services/Rates/Pricing/`) prices one block: the `rate_hour_prices` row for the room type, the plan, the date and the hours. No row refuses with `no_hour_price`. Stop sell on the `rates` row refuses with `restricted` (the front desk override applies as for nights). Min stay, max stay and closed to departure do not apply.

It does not implement `StayPricing`, because that interface prices nights. `RateQuoteService::quoteDayUse(roomType, plan, date, hours)` calls it directly. `PricingBasis::DayUse->strategy()` throws a `LogicException`, so the nightly path never prices a Day use plan.

## Charging

- The block posts **at check-in**, because the guest leaves before the night audit. `CheckInUnit` posts it after the undated add-ons, with `PostFolioCharge`, under the key `dayuse:{unit_id}` and the description "Day use · 10:00–14:00".
- The code is the system transaction code **DAY_USE** ("Day use", category Room), seeded by `SeedTransactionCodes` and back-filled. It is not one of the five room revenue codes that follow the Taxes tab: it keeps its own taxes (see [Taxes](#taxes)).
- Inclusions of the plan are split from the price with `InclusionAllocator`, as for a night, under `dayuse:{unit_id}:incl:{inclusion_id}`.
- The source of the line is the reservation unit. `FolioRouter` routes it like a Room charge.
- A Confirmed day use shows its block as a Scheduled row on the folio tab, key `dayuse:{unit_id}`, until it posts.

## Taxes

No new tax column. A tax applies to the codes in its charge list (the `tax_transaction_code` pivot), so day use gets its own list:

- The [Room revenue](../06-catalogues/06-room-revenue.md#day-use-tab) page gains a **Day use** tab with a taxes multiselect for DAY_USE, shown when the property has a Day use plan (active or archived).
- DAY_USE never follows the Taxes tab, because a per-night tax such as the Tourism tax does not fit an hourly stay.
- When the first Day use plan is created, DAY_USE gets the Taxes tab's taxes **except** flat taxes (a fixed amount per room-night, such as the Tourism tax preset). Staff can change the choice on the tab.

## Front desk

| Action                                                | Day use rule                                                                                                                                                 |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Check in                                              | On the business date that equals the date. `stay_ended` and `arrival_not_reached` read the date for day use, because arrival and departure are the same day. |
| Check out                                             | On or after the date, as today. Marks the room Dirty, which matters for the evening arrival.                                                                 |
| Cancel, no-show                                       | The policy counts the block as one night priced at the block (first night, whole stay and one charge all give the block price). `released_at` is set.        |
| Reinstate                                             | Re-checks the room for those hours, clears `released_at`, or refuses `not_available`.                                                                        |
| Change dates, extend, early check-out, late check-out | Not offered for day use at first. Cancel and book again. Changing hours is a later item.                                                                     |
| Move room                                             | `MoveUnit` moves the block to another free room for the same hours.                                                                                          |

## Night audit

- The room charge step skips day use: there are no nights.
- A day use still CheckedIn on the business date shows in the existing **Not checked out** warning (`DepartureNotCheckedOut`: departure on or before D), with "Day use · ended 14:00" in the detail.
- A Confirmed day use not checked in shows in **Not checked in** and follows the missed arrivals step like any unit.

## Reports

- **Occupancy, ADR and RevPAR stay overnight only.** Day use has no nights, so the existing figures are unchanged.
- `daily_statistics` gains `day_use_sold` and `day_use_revenue`, snapshotted by `SnapshotStatistics`. The Occupancy report gains **Day use sold** and **Day use revenue** columns, and its totals sum them.
- The Revenue report shows DAY_USE as its own code with no change.
- Arrivals, Departures and In-house list day use units with "Day use · 10:00–14:00" in the stay column.
- The police and non-national guests reports include day use guests.

## Channels

A Day use plan is never mapped. The mapping capability refuses it with "Day use plans are sold at the front desk only." (see [Mappings and capability](../21-channels/02-mappings-and-capability.md)).

## Build order

| #   | Commit                                                | Contains                                                                                                                                                                                                                                                                             |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `feat(rates): add day use rate plans`                 | `PricingBasis::DayUse`, the plan columns and CHECKs, `rate_hour_prices`, the Details fields, the Pricing tab hour rows and Auto, the calendar cell, DAY_USE seeded and back-filled with the Room revenue Day use tab and `UpdateDayUseTaxes`, the channel refusal                    |
| 2a  | `feat(reservations): book day use rooms and prices`   | Backend: `StayType`, `unit_day_uses` with the exclusion constraint, `DayUsePricing` and `quoteDayUse`, `DayUseOffers`, `DayUseAvailability`, `AddDayUseUnit` and the day use path in `CreateReservation` and `AddUnit`, totals and the "Day use · 4h" label, the Scheduled folio row |
| 2b  | `feat(reservations): add day use to the booking form` | The Stay type switch, Hours select, Room picker, Add unit Sheet and the labels in the UI                                                                                                                                                                                             |
| 3   | `feat(front-desk): check day use in and out`          | Check-in posting the block, check-out, cancel and no-show with the policy rule, reinstate, `MoveUnit`, `AutoAssignRooms`, `AssignRoom` and overnight picks skipping overlapping rooms, the audit detail                                                                              |
| 4   | `feat(reports): show day use apart`                   | `day_use_sold` and `day_use_revenue` in the snapshot, the Occupancy columns, the stay column in Arrivals, Departures and In-house                                                                                                                                                    |

**Built (commit 2a, backend).** `StayType`, `unit_day_uses` with the exclusion constraint, `DayUsePricing` and `RateQuoteService::quoteDayUse()` (new refusals `plan_not_day_use`, `no_hour_price`, `hours_not_sold`), `DayUseOffers`, `DayUseAvailability`, `AddDayUseUnit` (called by `AddUnit`) and the day use path in `CreateReservation`. New refusals: `stay_type_mismatch`, `stay_types_mixed`, `day_use_not_for_channel`, `outside_sold_between`, `start_time_invalid`, `room_required`, `override_not_allowed`, `nightly_item_for_day_use` (add-ons) and `day_use_unit` (assign room or bed). Request keys: `stay_type`, `start_time`, `hours` and `room_id` (the date is `arrival_date`; a day use needs no `departure_date`). On the booking form the start time is shared by every line and each line has its own `hours` and `room_id`. `reservations.day-use-offers`, `reservations.quote` and `reservations.free-units` take the day use keys, and the form props `hasDayUse` and `addUnitOptions.ratePlans[].isDayUse`, `dayUseMinHours`, `dayUseMaxHours`, `dayUseFrom` and `dayUseUntil`. `StayLabel` gives the "Day use · 4h" label (`stayType` and `stayLabel` on the reservation list row, the reservation page and the unit panel; null for overnight). `StayTotal`, the list and money strip totals and the room box include the block. `DayUseChargeQuote` works out the lines of the block (the price less the plan's inclusions, taxed with the DAY_USE taxes) for the Scheduled folio row `dayuse:{unit_id}` and for check in to post. A day use unit has no unit menu actions yet. A guest who stays through the date (a night before and the night of the date in the same room) blocks the room all day, which the table above leaves implicit.

**Built (commit 2b, form).** The booking form shows a **Stay type** switch (Overnight, Day use) when `hasDayUse`. Changing it starts the lines again. Day use replaces Arrival, Departure and nights with **Date and start** (a walk-in starts at the next half hour, a booking at 10:00) and shows day use lines: room type, plan (day use plans only, picked for you when the room type has one), **Hours** from `reservations.day-use-offers` ("4 hours · MYR 95.00", with "Ends 14:00"), **Room** from `reservations.free-units` for those hours, adults and children. A day use line has no price override, no beds and no Discounts tab, and the Add-ons tab leaves out nightly items. An overnight booking lists only the other plans. The Add unit Sheet of a day use reservation adds day use units with the same fields. The reservation list, card, unit panel, check-in dialog and summary show `stayLabel` ("Day use · 4h") and the unit panel shows the block price instead of a night table. Helpers with tests: `day-use.ts` (`nextHalfHour`, `offerLabel`, `dayUseQuotePayload` and more). `SandbaySeeder` also books one Confirmed day use (Garden Room, 10:00 for 4 hours, 3 days from now).

**Sample data.** `SandbaySeeder` adds a "Day use" plan: 2 to 6 hours, sold between 08:00 and 18:00, on every room type. The first block costs 30% of the room's nightly price and each extra hour adds 10% (Garden Room: 2h 60.00, +20.00 an hour). It goes through `CreateRatePlan`, so DAY_USE gets the Taxes tab taxes except the Tourism tax.

## Later

- **Change the hours or the start** of a booked day use, re-priced from the hour prices (a guest staying 4h who wants 5h pays the 5-hour price minus the 4-hour price).
- Day use mixed with overnight rooms in one reservation.
- Prices by adults on a Day use plan.
- The [Stay view](../18-front-desk/03-stay-view.md) draws day use as a short bar with a clock and the hours.
