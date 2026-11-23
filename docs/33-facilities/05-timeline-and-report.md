# Timeline and report

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

Show facility bookings next to the rooms, on one screen, like Bookinglayer's reservation calendar. Give staff a daily list of who booked what, which is also the participant list for activities.

## Facilities section on the Timeline View

> **Built** (item 7). **+ Book facility** in the peek is item 7b.

The [Timeline View](../18-front-desk/03-stay-view.md#timeline-view-screen) gets a **Facilities** section after the room rows, above the sticky **Available** footer. There is no separate facilities board.

```
├────────────────┼─────────┼─────────┼─────────┼─────────┼─────────┼───────┤
│ Facilities ⌄   │         │         │         │         │         │       │
│ Riverside      │         │▒Sabah   │▐ Lim &  │         │         │       │
│ Hall           │         │▒Tourism │  Tan    │         │         │       │
│ BBQ pit        │  1/3    │  0/3    │ 3/3 Full│  0/3    │  0/3    │       │
│ Kayak          │  4/6    │  0/6    │ 6/6 Full│  2/6    │  0/6    │       │
│ Night Walk     │ 12/20   │  8/20   │20/20Full│  5/20   │  0/20   │       │
├────────────────┼─────────┼─────────┼─────────┼─────────┼─────────┼───────┤
│ Available      │  4/12   │  1/12   │  2/12   │  6/12   │  7/12   │       │
└────────────────┴─────────┴─────────┴─────────┴─────────┴─────────┴───────┘
  ▐ Confirmed   ▒ Tentative
```

| Row                                   | Cell                                                                                                                   |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Booked by Item with How many 1 (hall) | The booking's name: the event name, or the booker and reference ("Lee Wei · ES-2041"). "{n} booked" when more than one |
| Any other facility                    | The most Confirmed bookings take at one time / How many. "Full" in the warning tone when it reaches it                 |

- Day columns, as for rooms. Hours are in the peek, so the grid does not get wider.
- Only Confirmed bookings count towards the number, the same rule as the clash check. A day whose bookings are all Tentative is hatched and muted; a mixed day says "{n} tentative" in the cell title. Cancelled bookings do not show.
- Facility rows sort by `position`. The section shows the available facilities, and any other facility with a live booking in the window. The section header collapses like a room group, and shows the facility count when folded.
- The **Filters** sheet gets a **Show facilities** switch, on by default. It is remembered in the browser with the other Timeline choices (`showFacilities` in `estatell:stay-view:timeline`). The switch shows only when the feature is on.
- **Available** counts rooms only.
- The server sends `facilities` (`FacilityTimelineRowData`, null when the feature is off) and `facilityCells` (`FacilityDayCellData`, only for dates with a live booking; a date with none is 0 booked). Both come from `FacilityTimeline`, a Service in this module. The Timeline only draws them, so the stay view module does not read `facility_bookings`.
- Room View is not changed.

## Facility day peek

A click on a facility cell opens a Popover, like the stay peek.

```
┌──────────────────────────────────────────┐
│ BBQ pit · Fri 12 Dec                     │
│ 12:00–14:00  WWF lunch               ›   │
│              Event · 1 item              │
│ 19:00–22:00  Lee Wei · ES-2041       ›   │
│              Booking · 2 items           │
│ 3 of 3 items booked at the busiest time. │
└──────────────────────────────────────────┘
```

- Each line links to its event (`events.view`) or reservation's Facilities tab (`reservations.view`). Without the permission the line shows with no link.
- The peek loads that day's bookings from `GET front-desk/facilities/{facility}/days/{date}` (`front-desk.facilities.day`, `FacilityDayLineData`), like the stay peek loads its folio. The route needs `front-desk.view` and the `facilities` middleware. The cell data does not carry the lines.

### + Book facility (item 7b)

**+ Book facility** in the peek opens the [Book facility](04-facility-bookings.md#book-facility-sheet) Sheet with the facility and date filled in. From the Timeline, staff pick the owner first: **Event** (an existing event, or a new one) or **Reservation** (search by guest, reference or room).

## Facility bookings report

A new report under **Reports**, category Front desk. Columns follow the [column layouts](../22-reports/01-core-reports.md#column-layouts-per-property).

```
Facility bookings      Date [ 12 Dec 2026 ▾ ]   Facility [ Night Walk ▾ ]   [Export]
──────────────────────────────────────────────────────────────────────────────────
Time          Facility     Guest / event          Room / no.   Qty   Status
20:00–22:00   Night Walk   Tan Mei Ling           Room 12       2    Confirmed
20:00–22:00   Night Walk   Mr Ahmad Faizal        Room 07       3    Confirmed
20:00–22:00   Night Walk   Borneo Trails group    BKG-0441     15    Confirmed
                                                   Session      20 / 20
```

| Key        | Label         | Default visible |
| ---------- | ------------- | --------------- |
| `time`     | Time          | ✓               |
| `facility` | Facility      | ✓               |
| `owner`    | Guest / event | ✓               |
| `ref`      | Room / no.    | ✓               |
| `quantity` | Qty           | ✓               |
| `status`   | Status        | ✓               |
| `phone`    | Phone         |                 |
| `folio`    | Charge to     |                 |
| `amount`   | Amount        |                 |
| `notes`    | Notes         |                 |

- Filters: date range, facility (multi-select), status. Cancelled are left out unless picked.
- A total row per facility and session sums the quantity against capacity. A facility with no sessions totals per start and end time.
- `ReportKey::FacilityBookings`, feature-gated by `Facilities`.

## Report changes

- **Deposit liability:** counts payments on Open event folios whose event starts (`starts_on`) after the business date, as it does for reservations not yet arrived.
- **Payments, Folio ledger, Tax:** event folio lines show the event number where they show a reservation number today.
