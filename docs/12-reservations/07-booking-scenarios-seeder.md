# Booking scenarios seeder

## Purpose

Seed one property with every single, group and dorm bed case, including the edge cases, so staff and agents can try each flow in the UI. `SandbaySeeder` stays the polished demo. This seeder is the test bench.

## Shape

| Item        | Value                                                                                          |
| ----------- | ---------------------------------------------------------------------------------------------- |
| Class       | `Database\Seeders\BookingScenariosSeeder`                                                      |
| Run         | `php artisan db:seed --class=BookingScenariosSeeder`. Not called by `DatabaseSeeder`.          |
| Property    | Kinabalu Hostel & Suites, Kota Kinabalu, Sabah. A new property, never Sandbay.                 |
| Repeat      | Skips with a warning when a property with that name exists, like `SandbaySeeder`.              |
| Transaction | Everything in one transaction. A refused step throws and leaves nothing behind.                |
| Writes      | Production Actions only. No `forceFill` on statuses. No factories for domain rows.             |
| Staff       | Owner, Manager and Front desk, with the Sandbay staff password. Printed in a table at the end. |
| Output      | A table of every scenario: code, reservation reference, expected status, actual status.        |

Every reservation's `notes` starts with its scenario code, for example `[G4] Partly cancelled group.`, so staff can find it with the reservation search.

## Back-dated history

A booking cannot arrive before the business date (`arrival_in_past`). To get real stays, posted room charges and closed dates, the seeder starts in the past and rolls forward with the night audit.

1. Open the first business date at **D0 = today − 7** with `OpenBusinessDate`.
2. On D0, create every booking in the tables below. All arrivals are on or after D0.
3. For each day from D0 to D6: run that day's front desk events (the **Events** column), then `RunNightAudit` (Manual trigger, the owner as actor, no force). A refused or Failed audit throws.
4. The business date ends at **D7 = today**. Day 7 events run, and no audit runs on D7.

Rules this depends on:

- `missed_arrival_policy` is set to **Mark no-show** with `UpdatePropertySettings`, so an arrival that is not checked in by the audit becomes NoShow. `late_arrival_expected` units are skipped (S14).
- `overdue_departure_policy` stays **Block**. Every unit that departs on a day is checked out before that day's audit.
- `auto_assign_rooms` is on, so check-in runs `AutoAssignRooms` and no in-house unit is without a room at audit time (the readiness blocker). No booking picks a room. After `ExtendStay` (S9), the added nights get the unit's room with `MoveUnit`.
- A unit is checked out only after its folio balance is 0 (`balance_not_settled`), so the seeder records a Settlement payment first.

## Setup

| Item       | Value                                                                                                                                                                                                                                                       |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Room types | Standard Double: 10 rooms (101–110). Deluxe Twin: 8 rooms (201–208). Family Suite: 2 rooms (301–302). 6-Bed Dorm: 1 room (D1), six beds from `GenerateBeds`, `SellUnit::Bed`.                                                                               |
| Rate plans | Room Only and Bed & Breakfast (the room types, B&B with a breakfast inclusion), Dorm Bed (`PricingBasis::PerBed`, the dorm only: a per-room plan cannot sell a Bed room type), Corporate (10% below Room Only, Standard and Deluxe, linked to the company). |
| Rates      | D0 to D+60 for every plan. Standard and Room Only on D15: `min_stay` 3 (for S5).                                                                                                                                                                            |
| Age groups | The property defaults (Infant 0–3, Child 4–11). Bed & Breakfast prices children with its extra child amount, so age 17 is priced as an adult.                                                                                                               |
| Company    | Borneo Ventures Sdn Bhd, Active, linked to Corporate with `SyncCompanyRatePlans`.                                                                                                                                                                           |
| Guests     | 64, with Malaysian and foreign names and a nationality. Every unit gets its own guest, so check-in never stops on `nationality_required`.                                                                                                                   |
| Taxes      | SST 8% and Tourism tax, as in Sandbay.                                                                                                                                                                                                                      |

## Single bookings

| Code | Scenario                | Room type, plan         | Stay      | Events                                                                  | Status at D7 | Proves                                                                         |
| ---- | ----------------------- | ----------------------- | --------- | ----------------------------------------------------------------------- | ------------ | ------------------------------------------------------------------------------ |
| S1   | Plain stay, finished    | Standard, Room Only     | D0 → D2   | D0 check in. D2 settle in cash, check out (check-out closes the folio). | Completed    | History, two nights of room charges posted by the audit, a closed folio        |
| S2   | Family with children    | Family Suite, B&B       | D9 → D12  | None                                                                    | Confirmed    | Child ages 4, 9 and 17. 17 is priced as an adult when past the last age group  |
| S3   | Complimentary stay      | Standard, Room Only     | D8 → D9   | Booked with `overrideTotal` 0.00 and a reason                           | Confirmed    | A 0 override, the reason in the log                                            |
| S4   | Override with remainder | Standard, Room Only     | D9 → D12  | Booked with `overrideTotal` 100.00                                      | Confirmed    | 33.33, 33.33, 33.34 over three nights                                          |
| S5   | Restriction override    | Standard, Room Only     | D15 → D16 | Booked with a `restrictionOverrideReason`                               | Confirmed    | One night against `min_stay` 3, allowed by `rates.override-restrictions`       |
| S6   | OTA booking             | Family Suite, Room Only | D9 → D12  | `CreateBooking` with `fromChannel`, `source` Channel                    | Confirmed    | `channel_name`, `external_reference`. With S2, Family Suite is sold out D9–D11 |
| S7   | Company single          | Deluxe, Corporate       | D7 → D9   | None. Arrives today.                                                    | Confirmed    | `company_id`, a company-linked plan, routing for one unit                      |
| S8   | Room move mid-stay      | Standard, B&B           | D4 → D9   | D4 check in. D6 `MoveUnit` from night D6, with a reason.                | InHouse      | Nights D4–D5 in one room, D6 onward in another                                 |
| S9   | Extended stay           | Deluxe, Room Only       | D3 → D6   | D3 check in. D5 `ExtendStay` to D8.                                     | InHouse      | Kept prices for old nights, a new quote for added nights                       |
| S10  | Early departure         | Standard, Room Only     | D2 → D7   | D2 check in. D4 settle, `EarlyCheckOut`.                                | Completed    | `EarlyDeparturePolicy`, unused nights deleted                                  |
| S11  | Departs today, unpaid   | Standard, B&B           | D5 → D7   | D5 check in. No payment.                                                | InHouse      | Departures list, balance due, `balance_not_settled` can be tried               |
| S12  | Cancelled, reinstated   | Standard, Room Only     | D20 → D22 | D1 `CancelUnit`. D3 `ReinstateUnit` with a reason.                      | Confirmed    | The room released, then re-priced                                              |
| S13  | Unassigned arrival      | Standard, Room Only     | D7 → D9   | Booked with no pick. Arrives today.                                     | Confirmed    | No room yet, check-in auto-assigns                                             |
| S14  | Late arrival            | Standard, Room Only     | D6 → D8   | Booked with `lateArrivalExpected`. Not checked in.                      | Confirmed    | The D6 audit skips it. Arrival date in the past but still Confirmed            |

## Group bookings

| Code | Scenario              | Units                                          | Billing       | Events                                                          | Status at D7 | Proves                                                                        |
| ---- | --------------------- | ---------------------------------------------- | ------------- | --------------------------------------------------------------- | ------------ | ----------------------------------------------------------------------------- |
| G1   | Wedding, staggered    | 2 Standard D6 → D9, 3 Deluxe D8 → D10, B&B     | MasterPays    | D6 check in the 2 Standard units.                               | InHouse      | Mixed dates and types. InHouse while other units are still to arrive (rule 3) |
| G2   | Corporate team        | 4 Deluxe D5 → D9, Corporate, the company       | MasterPaysAll | D5 `CheckInAllUnits`. D6 post a Laundry bag charge on one unit. | InHouse      | The extra routed to the Master folio                                          |
| G3   | Friends trip          | 3 Standard D6 → D8, Room Only                  | EachPays      | D6 `CheckInAllUnits`.                                           | InHouse      | One folio per unit, no routing rules                                          |
| G4   | Partly cancelled      | 4 Standard D12 → D14, Room Only                | MasterPays    | D2 `CancelUnit` on one unit.                                    | Confirmed    | One cancelled unit leaves the others alone                                    |
| G5   | Fully cancelled       | 2 Standard D18 → D20, Room Only                | EachPays      | D1 `CancelUnit` on both.                                        | Cancelled    | Rule 1                                                                        |
| G6   | No live unit left     | 2 Standard D4 → D6, Room Only                  | EachPays      | D2 `CancelUnit` on one. The D4 audit marks the other NoShow.    | Completed    | Rule 2, and a no-show made by the audit                                       |
| G7   | One left, two to come | 1 Deluxe D1 → D3, 2 Deluxe D9 → D11, Room Only | MasterPays    | D1 check in the first unit. D3 check out.                       | Confirmed    | Rule 5. The Master folio holds room charges for a reservation not in house    |
| G8   | Billing mode locked   | 2 Deluxe D10 → D12, Room Only                  | MasterPays    | D1 Advance of 500.00 by bank transfer on the Master folio.      | Confirmed    | `master_has_payments` can be tried from Edit group                            |
| G9   | Unit added later      | 2 Standard D10 → D13, Room Only                | MasterPays    | D2 `AddUnit` adds a third Standard unit.                        | Confirmed    | `AddUnit` on an existing reservation, routing synced                          |
| G10  | No group name         | 2 Deluxe D14 → D16, B&B, `group_name` null     | EachPays      | None                                                            | Confirmed    | The UI shows the booker's name                                                |

## Dorm beds

| Code | Scenario         | Units                                                             | Events                                                                                        | Status at D7 | Proves                                                              |
| ---- | ---------------- | ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------- | ------------ | ------------------------------------------------------------------- |
| B1   | Solo backpacker  | 1 bed D5 → D9, Dorm Bed                                           | D5 check in.                                                                                  | InHouse      | `bed_id` assignment. Attaching a second guest gives `bed_unit_full` |
| B2   | Backpacker group | `beds: 4`, D3 → D9, Dorm Bed, EachPays                            | D3 check in 3 beds. The D3 audit marks the 4th NoShow. D5 settle one bed and `EarlyCheckOut`. | InHouse      | Each bed has its own status and folio                               |
| B3   | Rooms and beds   | 2 Standard on Room Only + 3 beds on Dorm Bed, D10 → D12, EachPays | None                                                                                          | Confirmed    | Rooms and beds in one reservation                                   |
| B4   | Full dorm        | `beds: 6`, D16 → D17, Dorm Bed, MasterPays                        | None                                                                                          | Confirmed    | Availability shows 0 beds on D16                                    |

## Refusals to try in the UI

The seeder does not create refusals. Refusals are covered by the feature tests. It prepares the state so staff can see each one.

| Refusal               | Where to try it                                                             |
| --------------------- | --------------------------------------------------------------------------- |
| `not_available`       | Book a Family Suite for D9–D11 (S2 and S6), or a dorm bed on D16 (B4)       |
| `reservation_closed`  | Add unit on G5                                                              |
| `bed_unit_full`       | Attach a guest on B1                                                        |
| `master_has_payments` | Change the billing mode on G8                                               |
| `balance_not_settled` | Check out S11                                                               |
| `restricted`          | Book Standard, Room Only, D15 for one night without a reason, as Front desk |

## Room counts per night

Counted with every unit the seeder books (G9's third unit too), before cancellations and no-shows release rooms. Every night stays within inventory.

| Night | Standard (10) | Deluxe (8)             | Family (2) | Dorm beds (6) |
| ----- | ------------- | ---------------------- | ---------- | ------------- |
| D6    | 9             | 5 after S9 is extended | 0          | 5             |
| D7    | 8             | 6                      | 0          | 5             |
| D8    | 4             | 8                      | 0          | 5             |
| D9    | 1             | 5                      | 2          | 0             |
| D10   | 6             | 4                      | 2          | 3             |
| D12   | 7             | 0                      | 0          | 0             |
| D16   | 0             | 0                      | 0          | 6             |

## Tests

`tests/Feature/Seeders/BookingScenariosSeederTest.php`:

- The seeder builds a live property: 21 rooms, 6 beds, 4 plans, 28 reservations, 60 units (G9 gains one on D2), 7 Closed dates and today Open.
- Each scenario code reaches the status in the tables above, with the unit statuses of G6 and B2.
- The audit posts room nights for S1 (two) and S9 (four, D3 to D6), and none for G6 and S14.
- Running it twice adds nothing.

## Build notes

- `RunNightAudit` runs inside the seeder's one transaction. The audit gate passes because D0 to D6 are before the system date.
- A unit is picked by its position in the booking. An Eloquent collection's `only()` filters by primary key, so the seeder filters by index.
- The Laundry bag item uses the code `LAUNDRY_BAG`, because `LAUNDRY` is a system transaction code.

## Build status

Built: `BookingScenariosSeeder` and its test. Run it with `php artisan db:seed --class=BookingScenariosSeeder`.
