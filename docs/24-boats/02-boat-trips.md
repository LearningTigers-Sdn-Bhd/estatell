# Boat trips

## Purpose

Record how each room's guests come in and go out: on a scheduled boat, on a charter, or on their own boat. The trip sets the room's expected time, which then drives meals, the front desk lists, housekeeping order, the boat fare and the [manifest](03-boat-manifest.md).

Needs the `Boats` feature (see [Boat settings](01-boat-settings.md#feature-flag)).

## Data model

```
unit_boat_trips    id, property_id, reservation_unit_id, direction (BoatDirection),
                   mode (BoatMode), boat_departure_id null → boat_departures (restrict),
                   note text null, timestamps
                   UNIQUE (reservation_unit_id, direction)
                   CHECK (mode = 'scheduled') = (boat_departure_id IS NOT NULL)

boat_trip_add_ons  id, unit_boat_trip_id → unit_boat_trips (cascade),
                   unit_add_on_id → unit_add_ons (cascade), timestamps
                   UNIQUE (unit_add_on_id)
```

- **One trip per unit per direction.** Trips are per unit, not per guest. A guest who travels apart from the rest of the room is booked as a separate unit.
- **No date column.** A Boat in trip is on the unit's arrival date, and a Boat out trip is on its departure date. Changing dates, extending a stay or an early check-out moves the trip with the unit.
- **No time column.** The time is the unit's `arrival_time` (In) or `departure_time` (Out) ([Expected times](../12-reservations/01-reservation-shape.md#expected-times)). One fact, one place.
- `boat_trip_add_ons` links a trip to the fare add-on rows it created. A fare with child prices writes several rows (adults, and one per charged age group), so it is a link table, not one column. Boats owns it, so `unit_add_ons` does not change.
- A unit with no trip row means "not set yet".

## Enums

| Enum       | Cases (label)                                                       | Notes                                      |
| ---------- | ------------------------------------------------------------------- | ------------------------------------------ |
| `BoatMode` | Scheduled ("Scheduled boat"), Charter ("Charter"), Own ("Own boat") | `needsDeparture()` true for Scheduled only |

## Setting a trip

`SetBoatTrip` (`app/Actions/Boats/`): `(User $actor, ReservationUnit $unit, BoatTripData $data): BoatTripResult`. `BoatTripData`: `direction`, `mode`, `boat_departure_id` (Scheduled), `time` (Charter and Own, `H:i`), `note` (optional). It takes a shared lock on the departure, then locks the unit row (the same order as `UpdateBoatDeparture`, so the two cannot deadlock), and does everything in one transaction:

1. Writes or replaces the trip row.
2. Sets the unit time:

| Mode         | Boat in sets `arrival_time` to | Boat out sets `departure_time` to |
| ------------ | ------------------------------ | --------------------------------- |
| Scheduled    | the departure's `arrives_at`   | the departure's `leaves_at`       |
| Charter, Own | the time staff typed           | the time staff typed              |

3. Syncs the fare (see [Fare](#fare)).
4. Logs `reservation.boat_trip_set` on the reservation: "Boat in: Scheduled boat 09:00 (Kuala Besut jetty)."

| Check, in order                                                                  | Refusal               |
| -------------------------------------------------------------------------------- | --------------------- |
| The `Boats` feature is on for the unit's property                                | `boats_off`           |
| Boat in: the unit is Confirmed. Boat out: the unit is Confirmed or CheckedIn     | `unit_not_open`       |
| Scheduled: the departure is available, of the property and of the same direction | `departure_not_found` |
| Charter and Own: a time is given                                                 | `time_required`       |

- Seats are **never** a refusal. A full departure is a warning in the form (see [Seats](#seats)).
- `ClearBoatTrip` removes the trip ("Not set"), with the same unit checks. It removes the unposted fare rows and leaves the unit time as it is. Logs `reservation.boat_trip_cleared`.

## Fare

When the trip is Scheduled and its departure has a fare, the trip adds the fare as an add-on through `AddUnitAddOn` and links the rows in `boat_trip_add_ons`.

- **One fare per trip.** The fare is one way, per guest: Boat in and Boat out each add their own. "Boat in by speedboat, own boat out" pays one fare.
- **Undated.** The fare add-on has no `service_date`, so it posts at check-in like other undated add-ons. A Boat out trip set after check-in gets the business date and posts in that night's audit (the existing [add-on rule](../12-reservations/04-add-ons.md#addunitaddon)). A fare dated on the departure day would post after the guest has left.
- **Child prices** apply: the rows split by age group as for any per-guest item.
- **Package skip.** No fare is added when the unit's rate plan has an inclusion for the same charge item (an island package that includes the boat). The check reads the plan through a Rates service, not the Rates models.
- **Changing or clearing a trip** first removes the trip's unposted fare rows with `RemoveUnitAddOn`, then adds the new fare if there is one. A posted fare row is kept. The Dialog then says: "The old fare is already posted. Reverse it on the folio if needed." The fix is `ReverseLine` on the folio. Nothing is reversed automatically.
- **Charter and Own** add nothing. Staff add a charter charge by hand, for example with an Enter when charging item.
- **Guest counts and stay changes** leave fare rows alone. Guest counts cannot change after booking, and stay changes leave undated rows as they are ([Add-ons rules](../12-reservations/04-add-ons.md#rules)), so fare rows keep their link. A future guest count change must link any new age-group fare row to its trip.
- Cancel, no-show and reinstate treat fare rows like any add-on: they stay as history and come back on reinstate.

## Seats

- A departure's seat count for a date is the adults and children of every Confirmed, CheckedIn and CheckedOut unit with a Scheduled trip on that departure and date. Every guest takes a seat, infants included.
- Pickers show "09:00 · 14 / 20 seats". Over the limit shows the count in the warning tone ("22 / 20") and the form says "This boat is over its 20 seats." Staff can still save.
- A departure with no seat limit shows no count in the picker.
- `BoatSeats` (`app/Services/Boats/`) counts for one date and one or more departures.

## Booking form

With the feature on, the booking form Stay row gets **Boat in** and **Boat out** after the times. Like the times, they apply to every line.

```
Arrival   [12 Oct 2026 | 14:00]   3 nights   Departure  [15 Oct 2026 | 12:00]
Boat in   [Scheduled · 09:00 Kuala Besut → 09:45 ▾]
Boat out  [Not set ▾]
```

- Each select lists **Not set**, the available departures of that direction with seats, **Charter** and **Own boat**.
- Picking a departure fills the matching time input with its property-side time and locks it. Picking Charter or Own unlocks the time and shows a **Note** input.
- Seats are counted for the form's arrival and departure dates. The form reads `GET /p/{code}/reservations/boat-seats?direction=in&date=2026-10-12` (`reservations.boat-seats`, `reservations.manage`, `boats`), which returns `{"seats": {"<departure id>": 14}}` from `BoatSeats::forDate`. The picker label shows the saved count. The over-seats warning also adds this booking's guests, in the browser only.
- A Scheduled trip sends no time: `SetBoatTrip` takes it from the departure. Charter and Own send the Stay row's arrival time (In) or departure time (Out).
- A refused trip refuses the whole booking (`AddUnit` throws `ReservationRefused`). The error shows on `boat_in` or `boat_out` on the Stay row, not on a line.
- **Day use has no boat trips.** A day use unit's times are its start and end, and a trip would overwrite them. The Boat row is hidden on day use, the request refuses `boat_in` and `boat_out` there, and Add unit copies no trips onto a day use reservation.
- The request sends `boat_in` and `boat_out` (each `BoatTripData` or null). `NewUnitData` gains `boat_trips`. `AddUnit` calls `SetBoatTrip` for each trip inside its transaction, so a booking stays all or nothing, as with add-ons.
- **Add unit** on the reservation page copies the first unit's trips, as it copies the times. A trip whose departure is now unavailable is skipped, and the toast says so ("Boat in 09:00 was skipped. It is no longer available."). Add unit is not refused. The copy is read by `CopyableBoatTrips` in the controller; `AddUnit` stays strict.
- Channel and company portal bookings arrive without trips. The [dashboard](#dashboard) lists them.

## Reservation page

The unit panel gets a **Boat** detail row under Stay, with the `ship` icon:

```
Boat   In   Scheduled · 09:00 Kuala Besut → 09:45            [Change]
       Out  Own boat · 11:30 · Guest's own speedboat           [Change]
```

- **Change** opens a Dialog (`FormOverlay`, `md`) for that direction: **Mode** (Not set, Scheduled boat, Charter, Own boat), then **Departure** (with seats) for Scheduled, or **Time** and **Note** for Charter and Own. The button is "Save boat in" or "Save boat out".
- **Change** shows only while `SetBoatTrip` would accept the unit (Boat in while Confirmed, Boat out while Confirmed or CheckedIn) and the user has `reservations.manage`. Otherwise the row is read only.
- **⋯ More › Set boats** on the reservation header opens one Dialog with Boat in and Boat out for **every open unit** (Confirmed and CheckedIn). Boat in is read only on a CheckedIn unit. It calls `SetBoatTrip` per unit and direction in one transaction. One refusal saves nothing and names the unit. Afterwards each unit can still differ.
- Trip changes show on the Activity tab with the `ship` icon.

## Meals

When the feature is on, kitchen counts use the unit times on the first and last day ([Meal entitlements](../07-meals/02-meal-entitlements.md#arrival-and-departure-times)):

| Day           | A meal counts when                               |
| ------------- | ------------------------------------------------ |
| Arrival day   | `arrival_time` is before the meal's `ends_at`    |
| Departure day | `departure_time` is after the meal's `starts_at` |
| Days between  | Always, as now                                   |

- This trims the inclusion dates. It never adds a meal the plan does not include.
- It changes the **kitchen counts** only (`MealCounts`). Folio postings and prices do not change.
- An island full-board plan sets Lunch and Dinner to start on the arrival day and lets the boat time trim them.
- Example: a boat in that arrives 12:45 counts lunch (ends 14:30). A boat out that leaves 12:00 does not count lunch (starts 12:00).

## Front desk

- **Arrivals and Departures** (reports and dashboard lists) show a **Boat** column: "09:00 boat", "Charter 10:30", "Own 11:30" or "—". It is hidden when the feature is off.
- Rows sort by expected time, so they come out in boat order.

### Dashboard

With the feature on, the [To do today](../18-front-desk/04-dashboard.md#to-do-today) list gets one more row type after Arrival:

| Row         | When                                                                                  | Button   |
| ----------- | ------------------------------------------------------------------------------------- | -------- |
| **No boat** | A Confirmed unit arriving on the business date or the day after, with no Boat in trip | **Open** |

One row per reservation ("2 rooms"). **Open** goes to the reservation page.

## Housekeeping

The [room status board](../19-housekeeping/01-room-status-board.md) sorts departures by `departure_time` and arrivals by `arrival_time`. This works for every property. With boats, it follows the boat order.

## Permissions

`reservations.manage` sets and clears trips. `reservations.view` sees them. No new permission.

## Lessons from wastays

wastays stored `boat_in_at`, `boat_out_at`, `boat_in_type` and `boat_out_type` on each `booking_guest`, copied again on `reservation_import_rows`, with times as strings in some places. The date lived inside the datetime, so a date change needed `Boats::ResolveTimes` to rebuild it. estatell stores one trip per unit and direction, keeps the time on the unit and takes the date from the stay.
