# Sailings

## Purpose

A departure is the timetable: it runs every day at the same time. A **sailing** is what really runs on one date: one boat, its crew and its time. Sailings let a single date differ from the timetable without changing it:

- More than one boat on a departure (a busy 09:00 boat in).
- Another time for one date (today's 14:30 leaves at 15:00).
- A cancelled trip for one date.
- An extra trip that is not on the timetable.
- A **boat job** that carries no guests: diesel, linen, supplies, staff.

Staff change sailings on the [Boat Trips board](05-boats-board.md). Needs the `Boats` feature.

## Data model

```
boat_sailings       id, property_id, date, kind (SailingKind),
                    direction (BoatDirection) null,
                    boat_departure_id null → boat_departures (restrict),
                    leaves_at time null, arrives_at time null, pickup_point text null,
                    boat_id null → boats (restrict),
                    job_type (BoatJobType) null, note text null (≤ 500),
                    cancelled_at null, cancelled_by null → users (null on delete),
                    done_at null, done_by null → users (null on delete),
                    timestamps
                    INDEX (property_id, date)
                    CHECK (kind = 'guests') = (direction IS NOT NULL)
                    CHECK (kind = 'job') = (job_type IS NOT NULL)
                    CHECK kind = 'guests' OR boat_departure_id IS NULL
                    CHECK boat_departure_id IS NOT NULL OR leaves_at IS NOT NULL
                    CHECK arrives_at IS NULL OR leaves_at IS NULL OR arrives_at > leaves_at
                    CHECK done_at IS NULL OR kind = 'job'

boat_sailing_crew   id, boat_sailing_id → boat_sailings (cascade),
                    boat_crew_member_id → boat_crew_members (restrict),
                    in_charge bool (default false), timestamps
                    UNIQUE (boat_sailing_id, boat_crew_member_id)
                    UNIQUE (boat_sailing_id) WHERE in_charge
```

| Sailing    | `kind` | `boat_departure_id` | `direction` | Times                                   |
| ---------- | ------ | ------------------- | ----------- | --------------------------------------- |
| Departure  | guests | set                 | set         | Null = the timetable time, else its own |
| Extra trip | guests | null                | set         | Required                                |
| Boat job   | job    | null                | null        | `leaves_at` required, no `arrives_at`   |

- `date` and the times are in the property time zone. A sailing cannot cross midnight, as a departure.
- `pickup_point` is null on a departure sailing (it uses the departure's) and optional on an extra trip.
- Who is on a sailing: the trips whose `boat_sailing_id` points at it, and on the [main sailing](#main-sailing) also the trips of that departure and date with no sailing.

## Enums

| Enum          | Cases (label)                                                                               | Notes                        |
| ------------- | ------------------------------------------------------------------------------------------- | ---------------------------- |
| `SailingKind` | Guests ("Guest trip"), Job ("Boat job")                                                     |                              |
| `BoatJobType` | Diesel ("Diesel"), Linen ("Linen"), Supplies ("Supplies"), Staff ("Staff"), Other ("Other") | `needsNote()` true for Other |

A fixed list, not types each property defines. Other with a note covers the rest.

## Main sailing

- A date with **no sailing rows** for a departure runs as the timetable: one sailing with the default boat and crew. This sailing is not stored. `DateSailings` (`app/Services/Boats/`) builds it in memory, so a normal day needs no rows and no data entry.
- The first change on a date for a departure (boat, crew, time, a second boat, cancel) stores that sailing first, copying the departure's default boat and crew. This is the **main sailing**: the departure's sailing on that date with the lowest id.
- Trips of that departure and date with no `boat_sailing_id` are on the main sailing. A trip is on another sailing only when staff move it there ([Move](#move-guests)).
- A later change to the departure's default boat or crew changes dates with no rows only. Stored sailings keep what they copied.
- **Reset to timetable** on the board deletes a departure's stored sailings for the date and goes back to the timetable. It is refused while an added boat or an extra trip has guests (`sailing_has_guests`).

## Times

- A departure sailing with null times uses the timetable. A changed time is stored on the sailing and shows "(changed)".
- Each boat of a departure can have its own time (boat 2 leaves at 09:30).
- **Unit times follow the sailing.** When a sailing's property-side time changes (Boat in: `arrives_at`, Boat out: `leaves_at`), `SaveSailing` sets `arrival_time` or `departure_time` on the units of its trips, in the same transaction, like `UpdateBoatDeparture` does. Meals and the front desk lists follow.
- `UpdateBoatDeparture` moves upcoming unit times only for trips whose sailing has no time of its own.
- A guest already checked in keeps their arrival time, as on the timetable.

## Seats

| Sailing                       | Limit                                                   |
| ----------------------------- | ------------------------------------------------------- |
| Main sailing with no boat set | The departure's seats                                   |
| Any sailing with a boat       | The boat's seats, else the departure's seats, else none |
| Extra trip with no boat       | None                                                    |

- The board shows the count per sailing: "9 / 12 seats".
- A departure's limit on a date is the sum of its sailings' limits, or no limit when any sailing has none. The booking pickers use this ([Seats](02-boat-trips.md#seats)).
- Seats are never a refusal, as everywhere in Boats.

## Cancel

- **Cancel trip** cancels a departure on one date: all its sailings get `cancelled_at`. The board offers it only on the main sailing's menu, and says "(all boats)" when the departure has more boats that date. An added boat with no guests is removed with **Remove boat** instead. It is refused while any trip is on the departure on that date (`sailing_has_guests`, "Move the 6 guests first."). The board offers Move to for them.
- A cancelled departure leaves the booking pickers for that date, and `SetBoatTrip` refuses it (`departure_cancelled`).
- **Undo** clears `cancelled_at`. The board shows the cancelled departure muted, with Undo.
- An extra trip with no guests is deleted, not cancelled.
- Removing an added boat (not the main sailing) deletes its row. Refused while it has guests.

## Move guests

`MoveBoatTrips` moves trips to another sailing of the **same date and direction**:

- To another boat of the same departure: sets `boat_sailing_id`. The booked departure and the fare do not change.
- To an extra trip: sets `boat_sailing_id`. The booked departure and the fare stay. The unit time takes the extra trip's time.
- To another departure: calls `SetBoatTrip` with that departure, so the fare changes as for any departure change ([Fare](02-boat-trips.md#fare)).
- The unit time follows the target sailing. The tick clears, because the guest is now on another boat.
- It runs on many trips at once and is all or nothing. A refused unit names the room ("Double · 104: …").

## Jobs

A boat job is a sailing of kind Job: a boat and crew going out without guests.

- Fields: Job type, Leaves, Boat, Crew, Note. The note is required for Other ("Say what the job is.").
- **Done** is a tick on the board. It records who and when (`done_at`, `done_by`), like the trip tick.
- A job can be changed or deleted while it is not done. A done job can only be un-ticked first.
- A job is not a guest trip: it has no seats, no fare, no direction and no effect on unit times or meals.

## Actions

All in `app/Actions/Boats/`. Each locks the date's sailings of the departure (or the sailing), logs activity on the property and returns a result for an expected refusal.

| Action                | Does                                                                                                    | Logs                                              |
| --------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| `SaveSailing`         | Sets the boat, crew and time of a sailing. Stores the main sailing first when needed. Moves unit times. | `boat_sailing.saved`                              |
| `AddSailingBoat`      | Adds another boat to a departure on a date                                                              | `boat_sailing.boat_added`                         |
| `RemoveSailing`       | Deletes an added boat or an extra trip with no guests                                                   | `boat_sailing.removed`                            |
| `ResetDepartureDate`  | **Reset to timetable**: deletes the departure's stored sailings for the date                            | `boat_sailing.reset`                              |
| `CancelDepartureDate` | Cancels a departure on a date, or undoes it                                                             | `boat_sailing.cancelled`, `.restored`             |
| `AddExtraSailing`     | Adds an extra guest trip                                                                                | `boat_sailing.extra_added`                        |
| `SaveBoatJob`         | Adds or changes a boat job                                                                              | `boat_job.saved`                                  |
| `DeleteBoatJob`       | Deletes a job that is not done                                                                          | `boat_job.deleted`                                |
| `SetBoatJobDone`      | Ticks or un-ticks a job                                                                                 | `boat_job.done`, `.undone`                        |
| `MoveBoatTrips`       | Moves trips to another sailing ([Move](#move-guests))                                                   | `reservation.boat_trip_moved` on each reservation |
| `SyncBoatTripsToStay` | Clears `boat_sailing_id` and resets the time when a trip's date moves                                   | none (the stay change logs)                       |

| Refusal              | When                                                                                          |
| -------------------- | --------------------------------------------------------------------------------------------- |
| `sailing_has_guests` | Cancel, remove or Default while guests are on it                                              |
| `boat_unavailable`   | An unavailable boat or crew member that is not already on the sailing, or of another property |
| `in_charge_required` | Assistants without anyone in charge                                                           |
| `date_closed`        | A date before the business date. Past dates are read only.                                    |
| `job_done`           | Changing or deleting a done job                                                               |
| `note_required`      | A job of type Other with no note                                                              |

## Migration

One migration moves the old data, then drops what it replaces.

1. `boat_crew_members`: one row per distinct name in `boat_departure_runs.crew` and `boat_departures.default_crew`, split on "/" and "," and trimmed, ignoring case.
2. `boat_departure_crew`: from `default_crew`. The first name is in charge, the rest are assistants.
3. `boat_sailings`: one main sailing per `boat_departure_runs` row, with its boat. `boat_sailing_crew` from its crew text, as in step 2.
4. Drop `boat_departure_runs` and `boat_departures.default_crew`.

The down migration recreates both from the main sailings and joins crew names with " / ". Before the migration runs on production, check the real crew text, so the split rule fits it.

## Permissions

`reservations.view` sees sailings. `reservations.manage` changes them, as the trip tick does. No new permission.

## Out of scope

- Which guest of a room is on which boat. Trips are per unit; a guest who travels apart is a separate unit.
- Crew rosters, hours and pay.
- Fuel quantities and costs.
- Sailings for dates before the business date. They stay as they were.
