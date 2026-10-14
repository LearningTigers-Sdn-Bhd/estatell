# Dashboard

## Purpose

The first page staff see after login: start the day's work in one click, see what is left to do, and read four figures. It is a place to act, not a report.

Today `GET /p/{code}/dashboard` is the starter kit placeholder (`Route::inertia('dashboard', 'dashboard')` and `pages/dashboard.tsx` with grey boxes). This item replaces it.

## Layout

```
Dashboard · Sandbay Resort                          Business date 12 Oct 2026
┌ ⚠ Night audit for 11 Oct did not finish.                       [Open audit] ┐
└─────────────────────────────────────────────────────────────────────────────┘
[+ New booking]  [Walk-in]  [Find reservation]                 [Run night audit]

┌ To do today ───────────────────── 8 items ┐┌ Tonight ────────────────────────┐
│ Overdue  Room 104 · Lee Mei Ling          ││ 70%  occupied                   │
│          was due 12:00       [Check out]  ││ 9 rooms free                    │
│ 14:00    Tan Wei Ming · WA1-0142          ││                                 │
│          2 rooms              [Check in]  ││ ▁▃▅▆▇▆▅                         │
│ 15:00    Ahmad Faiz · WA1-0150            ││ Mon ─────────────────────── Sun │
│          Deluxe Twin          [Check in]  │└─────────────────────────────────┘
│ Folio    Master F-0031 · Acme Sdn Bhd     │┌ Arrivals ──────┐┌ Departures ───┐
│          MYR 1,700 not billed [Open folio]││ 5              ││ 3             │
│ …                                         ││ to check in    ││ to check out  │
│                     [See all arrivals]    ││ of 8           ││ of 5          │
│                                           │└────────────────┘└───────────────┘
│                                           │┌ October ────────────────────────┐
│                                           ││ MYR 84,200 posted               │
│                                           ││ 612 nights booked               │
└───────────────────────────────────────────┘└─────────────────────────────────┘
```

Everything uses the property **business date** (`CurrentBusinessDate`) and the property time zone, never the calendar date.

### Bento grid

Below the quick actions the parts sit in a bento grid from `lg`:

| Cell                     | Place                                      | Why                                       |
| ------------------------ | ------------------------------------------ | ----------------------------------------- |
| **To do today**          | Left, about 55% wide, the full grid height | Where staff act, so it gets the most room |
| **Tonight**              | Right, top, full right-column width        | The 7-night bar needs width to read       |
| **Arrivals, Departures** | Right, middle, side by side                | Two short counts                          |
| **{Month}**              | Right, bottom, full right-column width     | Two figures with their labels             |

- The grid height follows the right column. A long To do list scrolls inside its cell, so the right column never leaves a gap.
- Each cell is one card with a rounded border. To do rows are plain rows, not cards in a card.
- A cell the user may not see is left out and the others in its column grow to fill it. With no tile at all, To do takes the full width.
- Below `lg` the parts stack: alert, quick actions, tiles (two by two), then the list.

## Night audit alert

Shown only when something is wrong, with **Open audit** (the Run Night Audit sheet):

| State                                                            | Text                                                                       |
| ---------------------------------------------------------------- | -------------------------------------------------------------------------- |
| The last audit Failed                                            | "Night audit for 11 Oct did not finish. The business date did not change." |
| The last run was refused by a blocker (`blocked_by_readiness`)   | "Night audit for 11 Oct is waiting: 2 items need a fix before it can run." |
| The business date is behind the calendar date by more than a day | "The business date is 10 Oct. Run the night audit to catch up."            |

## Quick actions

| Button               | Does                                                                                                             | Shown with            |
| -------------------- | ---------------------------------------------------------------------------------------------------------------- | --------------------- |
| **+ New booking**    | Opens `reservations.new`                                                                                         | `reservations.manage` |
| **Walk-in**          | Opens `reservations.walk-in`                                                                                     | `reservations.manage` |
| **Find reservation** | Opens the reservations list with the search box focused. It becomes the command palette when that item is built. | `reservations.view`   |
| **Run night audit**  | Opens the Run Night Audit sheet                                                                                  | `night-audit.run`     |

A user with none of them sees no button row.

## Tiles

Four tiles in the right column of the [bento grid](#bento-grid). Each is a link, and a tile the user may not see is left out.

| Tile           | Shows                                                                                                                                                                     | Link                       | Source                                                         | Permission          |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | -------------------------------------------------------------- | ------------------- |
| **Arrivals**   | "5 to check in" and "of 8": units arriving on the business date, and how many are still Confirmed                                                                         | Arrivals report, today     | `ArrivalsReport`                                               | `reservations.view` |
| **Departures** | "3 to check out" and "of 5": units departing on the business date, and how many are still CheckedIn                                                                       | Departures report, today   | `DeparturesReport`                                             | `reservations.view` |
| **Tonight**    | Occupancy % and rooms free tonight (beds as well for a property with dorms), then a 7-night mini bar. Tapping or hovering opens a popover by room type: sold, total, left | Rate calendar              | `AvailabilityService::nightsForRoomTypes()` for all room types | `reservations.view` |
| **{Month}**    | "MYR 84,200 posted" and "612 nights booked" for the month of the business date                                                                                            | Revenue report, this month | `DailyRevenueQuery`, `unit_nights`                             | `reports.view`      |

**The month tile counts two different things, and says so.**

| Figure     | Counts                                                                                                                                                                                      | Tooltip                                                       |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| **Posted** | Revenue posted to folios from the 1st to the business date, by posting date: charges and discounts, without tax and payments. The same figure as the Revenue report for those dates         | "Posted to folios from 1 Oct to today."                       |
| **Booked** | Room nights in the month by stay date (`unit_nights.date`) of live units (Confirmed, CheckedIn, CheckedOut), past and future. The tooltip adds their room total (`SUM(unit_nights.amount)`) | "Room nights staying in October, booked so far: MYR 118,400." |

Showing one revenue number without saying which date it uses was the wastays bug (`c34f82a00`). Room nights post on their own date, so the two agree for past nights; "Booked" also looks ahead.

## To do today

One list of what is left to act on, with a button on each row.

| Order | Row           | When                                                                                                                     | Button         |
| ----- | ------------- | ------------------------------------------------------------------------------------------------------------------------ | -------------- |
| 1     | **Overdue**   | CheckedIn, departure before the business date, or on it and past its `departure_time` (else the property check-out time) | **Check out**  |
| 2     | **Arrival**   | Confirmed, arriving on or before the business date, by expected `arrival_time`                                           | **Check in**   |
| 3     | **Departure** | CheckedIn, departing on the business date and not yet due, by `departure_time`                                           | **Check out**  |
| 4     | **Folio**     | The night audit's folio warnings: `MasterNotBilled`, `FolioOpenAfterCheckOut`, `MoneyHeldOnClosedBooking`                | **Open folio** |

- A reservation with several units in the same state is **one row** ("2 rooms"). Its button opens **Check in all**, or links to the [group check-out](05-group-check-out.md) page for check-out.
- The rows reuse the night audit readiness rules (`AuditReadiness`), so the dashboard and the audit never disagree. A day use shows its hours ("Day use · 10:00–14:00").
- **Check in** and **Check out** open the reservation page's own Dialogs over the dashboard through `AuditDialogHost`, which loads what a Dialog needs only when the button is pressed. The dashboard is a page, so this is one overlay level. After the Dialog closes the list and the tiles reload (`only`).
- A user without `front-desk.check-in` or `front-desk.check-out` gets **Open** (the reservation page) instead. **Open folio** is a link and needs `reservations.view`.
- The first 10 rows show, then **See all arrivals**. With no rows: "Nothing left for today. All arrivals are in and all departures are out."
- With the Boats feature, a **No boat** row follows the arrivals: Confirmed units arriving today or tomorrow with no Boat in trip (see [Boat trips](../24-boats/02-boat-trips.md#dashboard)).
- Rooms to clean join the list when the [room status board](../19-housekeeping/01-room-status-board.md) is built.

## Not on the dashboard

| Left out                   | Where it lives                            |
| -------------------------- | ----------------------------------------- |
| Recent bookings            | The reservations list                     |
| Overdue AR invoices        | Finance: city ledger and aging            |
| Room types in detail       | The Tonight popover and the rate calendar |
| Charts beyond the 7 nights | The Occupancy and Revenue reports         |

## Loading

- **Archetype:** Dashboard (see [Page archetypes](../design-system/04-page-archetypes.md)).
- `DashboardController::show` replaces the `Route::inertia` line. Read services in `app/Services/Dashboard/`: `DashboardAlert`, `DashboardTiles`, `DashboardTodo`.
- The tiles and the list are **deferred props**, each with a skeleton, so the page opens at once and one slow part does not hold the others. The alert and the quick actions come with the page.
- No polling at first. A reload or a finished Dialog refreshes it.
- A fixed number of queries, however many units and room types (the reports and `AvailabilityService::nightsForRoomTypes()` read in grouped queries).

## Tests

- Each tile and row follows its permission; a hidden tile is not sent.
- The counts and the To do rows match the Arrivals and Departures reports and the readiness checks for the same business date.
- "Posted" equals the Revenue report total without tax for the month to date; "Booked" counts live units' nights by stay date only.
- A group shows as one row. An empty day shows the empty text.
- The alert shows for a Failed audit, a blocked run and a business date behind the calendar.
