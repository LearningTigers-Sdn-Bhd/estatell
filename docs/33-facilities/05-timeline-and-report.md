# Timeline and report

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

Show facility bookings next to the rooms, on one screen, like Bookinglayer's reservation calendar. Give staff a daily list of who booked what, which is also the participant list for activities.

## Facilities section on the Timeline View

The [Timeline View](../18-front-desk/03-stay-view.md#timeline-view-screen) gets a **Facilities** section below the room rows and the **Available** footer. There is no separate facilities board.

```
├────────────────┼─────────┼─────────┼─────────┼─────────┼─────────┼───────┤
│ Available      │  4/12   │  1/12   │  2/12   │  6/12   │  7/12   │       │
├ Facilities ⌄ ──┴─────────┴─────────┴─────────┴─────────┴─────────┴───────┤
│ Riverside   P  │         │▒Sabah   │▐ Lim &  │         │         │       │
│ Hall           │         │▒Tourism │  Tan    │         │         │       │
│ BBQ pit     P  │▐ Rm 101 │         │2 booked │         │▐ Bday   │       │
│ Kayak       S  │  4/6    │  0/6    │ 6/6 Full│  2/6    │  0/6    │       │
│ Night Walk  S  │ 12/20   │  8/20   │20/20Full│  5/20   │  0/20   │       │
└────────────────┴─────────┴─────────┴─────────┴─────────┴─────────┴───────┘
  ▐ Confirmed   ▒ Tentative   P Private   S Shared
```

| Row              | Cell                                                                                                    |
| ---------------- | ------------------------------------------------------------------------------------------------------- |
| Private facility | The booking's name (event name, or room and guest). "{n} booked" when there is more than one that day   |
| Shared facility  | The highest quantity booked at one time / capacity. "Full" in the warning tone when it reaches capacity |

- Day columns, as for rooms. Hours are in the peek, so the grid does not get wider.
- Tentative bookings are hatched and muted. Cancelled bookings do not show.
- Facility rows sort by `position`. The section header collapses like a room group, and shows the facility count.
- The **Filters** sheet gets a **Show facilities** switch, remembered in the browser (`estatell:stay-view:show-facilities`), default on when the feature is on.
- **Available** counts rooms only.
- The server sends one `FacilityDayCell` per facility and day (`FacilityTimeline`, a Service in this module). The Timeline only draws them, so the stay view module does not read `facility_bookings`.
- Room View is not changed.

## Facility day peek

A click on a facility cell opens a Popover, like the stay peek.

```
┌──────────────────────────────────────────┐
│ BBQ pit · Fri 12 Dec                   ✕ │
│ 12:00–14:00  WWF lunch          Event    │
│ 19:00–22:00  Room 101 · Lee     Booking  │
│                                          │
│                       [+ Book facility]  │
└──────────────────────────────────────────┘
```

- Each line links to its event or reservation (the Facilities tab).
- **+ Book facility** opens the [Book facility](04-facility-bookings.md#book-facility-dialog) Dialog with the facility and date filled in. From the Timeline, staff pick the owner first: **Event** (an existing event, or a new one) or **Reservation** (search by guest, reference or room).
- The peek loads that day's bookings as an optional prop. The cell data does not carry the lines.

## Facility bookings report

A new report under **Reports**, category Front desk. Columns follow the [column layouts](../22-reports/01-core-reports.md#column-layouts-per-property).

```
Facility bookings      Date [ 12 Dec 2026 ▾ ]   Facility [ Night Walk ▾ ]   [Export]
──────────────────────────────────────────────────────────────────────────────────
Time          Facility     Guest / event          Room / no.   Qty   Status
20:00–22:00   Night Walk   Tan Mei Ling           Room 12       2    Confirmed
20:00–22:00   Night Walk   Mr Ahmad Faizal        Room 07       3    Confirmed
20:00–22:00   Night Walk   Borneo Trails group    BKG-0441     15    Confirmed
                                                   Total        20 / 20
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
- A total row per facility and time sums the quantity against capacity.
- `ReportKey::FacilityBookings`, feature-gated by `Facilities`.

## Report changes

- **Deposit liability:** counts payments on Open event folios whose event date is after the business date, as it does for reservations not yet arrived.
- **Payments, Folio ledger, Tax:** event folio lines show the event number where they show a reservation number today.
