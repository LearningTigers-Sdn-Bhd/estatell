# Timeline and report

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

Show facility bookings next to the rooms, on one screen, like Bookinglayer's reservation calendar. Give staff a daily list of who booked what, which is also the participant list for activities.

## Facilities section on the Timeline View

> **Built** (items 7 and 7b).

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

### Book facility from the Timeline

Staff with `facilities.book` see **+ Book facility** in the peek. It closes the peek and opens a Sheet on the **For** step.

```
┌ Book BBQ pit ──────────────────────────┐
│ Tue 20 Oct. Who is the booking for?    │
│ For   [● Reservation] [○ Event]        │
│ Reservation                            │
│ [ lee                              ⌄ ] │
│   Lee Wei · ES-2041                    │
│   Deluxe · 101 · 18–21 Oct             │
│                       [Cancel] [Next]  │
└────────────────────────────────────────┘
```

| For         | How staff pick                                                                                                                                                                               | Needs               |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| Reservation | Search by booker name, reference or room (2 letters or more, up to 5). Only reservations with a Confirmed or In-house unit                                                                   | `reservations.view` |
| Event       | A list of the Tentative and Confirmed events on the date. **+ New event** (`events.manage`) opens the [New event page](03-events-and-event-folios.md#new-event-page) with `?date=&facility=` | `events.view`       |

A choice the user lacks the permission for does not show.

**Next** swaps the Sheet for the [Book facility](04-facility-bookings.md#book-facility-sheet) Sheet with the facility, date and owner filled in. A **For** row names the owner, and **Change** goes back to the For step. Book saves through `facility-bookings.store`, which checks again, and the Timeline reloads with the new cell.

**Decided:** **+ New third-party folio…** is not offered from the Timeline. The Sheet's Charge to options come from one request when the reservation is picked, and a new folio would need them again. Staff make a third-party folio on the reservation's Facilities tab.

The For step reads `FacilityOwnerSearch` (a Service in this module) through `FacilityOwnerController`. See [Routes](04-facility-bookings.md#routes).

## Facility bookings report

**Built 2026-11-23.** A report under **Reports › Front desk**, shown only while the `Facilities` feature is on. Columns follow the [column layouts](../22-reports/01-core-reports.md#column-layouts-per-property).

```
Facility bookings   Date [ 14–15 Oct 2026 ▾ ]  Facility [ All facilities ▾ ]  Status [ Tentative, Confirmed ▾ ]  [Export]
───────────────────────────────────────────────────────────────────────────────────────────────────────────────
Night Walk · 15 Oct · 20:00–22:00
Date         Time          Facility     Guest / event          Room / no.      Qty   Status
15 Oct 2026  20:00–22:00   Night Walk   Tan Mei Ling           12               2    Confirmed
15 Oct 2026  20:00–22:00   Night Walk   Borneo Trails group    BSAA-26900012   15    Tentative
                                                                         17 / 20 people
```

| Key        | Label           | Default visible |
| ---------- | --------------- | --------------- |
| `date`     | Date            | ✓               |
| `time`     | Time            | ✓               |
| `facility` | Facility        | ✓               |
| `owner`    | Guest / event   | ✓               |
| `party`    | Guests          | ✓               |
| `ref`      | Room / no.      | ✓               |
| `quantity` | Qty             | ✓               |
| `status`   | Status          | ✓               |
| `dietary`  | Dietary remarks | ✓               |
| `phone`    | Phone           | ✓               |
| `folio`    | Charge to       |                 |
| `amount`   | Amount          |                 |
| `notes`    | Notes           |                 |

- **Date** is a column too, because the spreadsheet is flat (sections do not export).
- **Guests** (added 2026-11-26, for the guides): everyone named in the reservation's live rooms, primary guests first, with the nationality code and the age on the booking date, and the people not named yet: "Hannah Müller (DE, 34) · Mia Müller (DE, 7) · +1 not named" (`GuestParty`, shared with the Transfer manifest). An event has none. **Phone** is now shown by default too, so the guide can call the booker.
- **Dietary remarks** (added 2026-11-25) is the reservation's [dietary remark](../12-reservations/16-remarks.md), shown by default so the guide sees it on the list. An event has none.
- **Guest / event**: the event name, or the reservation's booker. **Room / no.**: the event number, or the first-night rooms of the reservation's live units ("12, 14"), or its reference when no room is set. **Phone**: the event payer's (guest, else company contact), or the booker's. **Amount**: the price before tax (unit price × rate basis quantity), blank when charged by hand.
- **Filters**: a date range (up to 31 days; Today, Tomorrow, Next 7 days, This week, Next week), facilities and statuses (both multi-select). In the URL they are comma lists: `?facilities=3,5&statuses=tentative,confirmed`. No statuses means Tentative and Confirmed; Cancelled shows only when picked. Unknown values are dropped.
- **Order**: date, facility (settings order), start time. The rows keep this order, so the headers do not sort.
- **Sections**: one for each facility, date and time (this covers sessions and facilities without sessions). The subtotal is the quantity of the Tentative and Confirmed bookings against the capacity ("17 / 20 people", "1 / 1 item"), in warning colour when over. A picked Cancelled booking shows but does not count.
- `ReportKey::FacilityBookings` (`facility-bookings`, Landscape, icon `tent`), `FacilityBookingsReport`, `FacilityBookingRow`, `reports.facility-bookings`. The PDF and the spreadsheet use the standard export routes.

## Report changes

**Built 2026-11-23.** Event folios have no reservation. The finance reports show the event in its place:

| Report                                 | For an event folio                                                                                           |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Payments, Folio ledger                 | The event number in the Reservation column, and the folio payer.                                             |
| Deposit liability, Outstanding balance | The event number, the folio payer, the event status, and `starts_on` and `ends_on` as Arrival and Departure. |
| Tax                                    | No change. It does not show the reservation.                                                                 |

- **Deposit liability** lists every folio in credit on the date, so an event deposit shows there until charges use it up, the same as a reservation deposit.
