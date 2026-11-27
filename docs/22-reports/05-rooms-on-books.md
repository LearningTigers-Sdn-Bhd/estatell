# Rooms on books

## Purpose

Tanini's eZee "Rooms On Books" sheet (requirements 7.7 and 7.8): what is on the books for the coming days, the pace of the next 30 days, and the bookings made, cancelled, moved to other dates or missed on a date, with the room nights and revenue each one added or took away.

**Status: built** (2026-11-22).

## Page

One page at `GET /p/{code}/reports/rooms-on-books`, Reports › Revenue, after Daily report. `TabbedReport::RoomsOnBooks`, with three tabs (`?tab=`; a bad or missing value opens Overview):

| Tab          | Report key                           | Dates                                                      |
| ------------ | ------------------------------------ | ---------------------------------------------------------- |
| Overview     | `BookingChanges` (for its dates)     | The changes made in the range. Default: the business date. |
| On the books | `OnTheBooks` (`on-the-books`)        | Default: the business date and the 14 days after it.       |
| Changes      | `BookingChanges` (`booking-changes`) | Default: the business date. Any range up to 366 days.      |

A tab link keeps the dates only when it reads the same report as the open tab, so Overview and Changes share their dates and On the books opens on its own. On the books opens forward with `ReportKey::opensForwardDays()` (15). Both tables have the standard layout, PDF and spreadsheet exports, Landscape. Neither is in the sidebar by itself.

Counted units: overnight rooms and beds, one each. Day use is left out, as it holds no night.

## Overview

Four tables, no export.

| Table                     | What it shows                                                                                                                                                                      |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Impact of changes         | For New bookings, Cancellations, Amendments, No-shows and Net: rooms, room nights and revenue added (negative when taken away), and ARR (revenue ÷ room nights). The Changes rows. |
| Pace                      | Next 10 days, 11–20 days, 21–30 days and Next 30 days from the open business date: room nights of the units that hold inventory, RPD (nights ÷ days) and occupancy.                |
| Last 10 days              | The 10 closed dates before the business date, from `daily_statistics`: rooms and beds sold and occupancy. A date with no snapshot is left out.                                     |
| Pick-up by length of stay | The new bookings by nights: 1–3, 4–7, 8–10, 11+. Rooms, room nights, average rate and revenue.                                                                                     |

Pace and Last 10 days always count from the business date: the books cannot show what was on them on an earlier day.

## On the books

One row for each date. A unit counts when it is booked: Confirmed, CheckedIn or CheckedOut. So a past date shows what stayed and a future date what is on the books now.

| Column          | Key              | Meaning                                                                       |
| --------------- | ---------------- | ----------------------------------------------------------------------------- |
| Date            | `date`           | The date                                                                      |
| In-house        | `in_house`       | Units booked for the night before: the rooms full that morning. No total.     |
| Arrivals        | `arrivals`       | Units arriving (eZee "Confirmed bookings")                                    |
| Departures      | `departures`     | Units departing                                                               |
| Group arrivals  | `group_arrivals` | Arrivals on a reservation with a group name                                   |
| Rooms on books  | `rooms_on_books` | Units booked for the night                                                    |
| Complimentary   | `complimentary`  | Of those, on a complimentary rate plan (hidden by default)                    |
| Available rooms | `available`      | Rooms and beds on sale (`RoomSupply`, as on History & forecast; hidden)       |
| Occupancy %     | `occupancy`      | Rooms on books ÷ available                                                    |
| ARR             | `arr`            | Booked price of the paid nights ÷ their count (complimentary nights out)      |
| Room revenue    | `room_revenue`   | Booked price of the paid nights (`unit_nights.amount`, before discounts, tax) |

Totals sum the movements and room nights; occupancy and ARR are worked out again from the sums.

## Changes

One row for each change to an overnight unit, grouped in sections in the order New bookings, Cancellations, Amendments, No-shows (`BookingChangeKind`), each section with its rooms, room nights and revenue change. Unpaged, so every section is whole.

| Kind         | Source                                                                                                                              | Room nights and revenue change        |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| New booking  | The units created in the dates. A kiosk hold released unpaid is left out.                                                           | + the stay's nights and booked price  |
| Cancellation | Activity log `reservation.unit_cancelled` (every cancel path logs it per unit: front desk, portal, booking engine), with its reason | − the stay's nights and booked price  |
| Amendment    | Activity log `reservation.unit_dates_changed`, with the original and new dates and the totals before and after                      | The difference in nights and in total |
| No-show      | Activity log `reservation.unit_no_show`, with its reason                                                                            | − the stay's nights and booked price  |

- **Dates.** A change is in the dates by its time in the property time zone, as Booking performance reads the booking date. Activity is matched on `properties.property_id`.
- **Columns.** Change, Time, Reservation, Guest, Room, Original check-in and check-out (amendments only), Check-in, Check-out, Nights, Rate, Revenue, Room nights change, Revenue change, Rate plan (marked Complimentary; hidden), Group (hidden), Source, Booked by, Reason.
- **Totals.** The net room nights change and revenue change.
- **Index.** `activity_log (event, created_at)` keeps the read fast as the log grows.

Not built from the eZee sheet: **Tentative** reservations (estatell has no unconfirmed status), and a separate **Complimentary reservations** list (a new booking on a complimentary plan shows its plan in the Rate plan column).

## Code

| Part                                               | Role                                                                   |
| -------------------------------------------------- | ---------------------------------------------------------------------- |
| `RoomsOnBooksReport`                               | The day grid and its totals.                                           |
| `BookingChangesReport`                             | The change rows, sections and totals.                                  |
| `RoomsOnBooksOverview`                             | The four Overview tables.                                              |
| `BookedNightCounts`                                | Sold, complimentary, pax and paid amount of each date in one query.    |
| `RoomSupply`                                       | Rooms and beds on sale on each date (shared with History & forecast).  |
| `BookingChangeKind`                                | New booking, Cancellation, Amendment, No-show: label, tone, log event. |
| `RoomsOnBooksController`, `reports/rooms-on-books` | The page.                                                              |

Every read is a fixed number of queries, whatever the bookings (tested with lazy loading prevented).

## Open

- A segment breakdown is kept in view until Tanini has segment data (see [History & forecast](04-history-and-forecast.md#open)).
