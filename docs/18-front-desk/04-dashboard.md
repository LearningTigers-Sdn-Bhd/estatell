# Dashboard

## Purpose

The first page staff see after login: start the day's work in one click, see what is left to do, and read four figures. It is a place to act, not a report.

`GET /p/{code}/dashboard` uses `DashboardController::show` and `pages/dashboard.tsx`.

This doc covers the **Today** tab. Users with `reports.view` also get a **Performance** tab with occupancy, ADR, RevPAR, channel mix and lead time over a period. See [Dashboard: Performance tab](06-dashboard-performance.md). The Today tab does not change.

## Layout

The dashboard uses the fixed-height app layout. Its content scrolls inside the available space, while the navbar and workspace header stay fixed.

A page header comes first on both tabs: the title **Dashboard**, the description "Today's front desk work and how the property is doing.", and **Run night audit** on the right for users with `night-audit.run`. The workspace header already shows the business date, so the page header does not repeat it.

On both tabs the header, rows and cards sit 12px apart (`gap-3`), tighter than the 16px page padding.

```
Dashboard                                                      [Run night audit]
Today's front desk work and how the property is doing.
[Today] [Performance]
┌ ⚠ Night audit for 11 Oct did not finish.                       [Open audit] ┐
└─────────────────────────────────────────────────────────────────────────────┘
[+ New booking]  [Walk-in]  [Find reservation]

┌ Arrivals ──────┐┌ Departures ───┐┌ Housekeeping ────────┐┌ October ──────────────┐
│ 5 of 8        ││ 3 of 5        ││ 4 dirty              ││ MYR 84,200 posted    │
│ to check in   ││ to check out  ││ 2 to inspect · 1 blocked│ 612 nights booked   │
└───────────────┘└───────────────┘└──────────────────────┘└─────────────────────┘
┌ To do today · 12 Oct ─────────────────────── 8 items ┐┌ Tonight ────────────────┐
│ Overdue    Lee Mei Ling   Room 104 · SAR-26100025    ││ 70% occupied            │
│ Arrival    Tan Wei Ming   2 rooms · SAR-26100031     ││ 9 rooms free            │
│ Departure  Ahmad Faiz     Deluxe · SAR-26100027      ││ 7-night chart           │
│ Folio      Master F-0031  Acme · F-0031              ││                         │
└──────────────────────────────────────────────────────┘└─────────────────────────┘
┌ Recommended next ──────────────────────────────────────┐
│ 1 of 7 done · Property logo                  [Add →]   │
└────────────────────────────────────────────────────────┘
```

Everything uses the property **business date** (`CurrentBusinessDate`) and the property time zone, never the calendar date.

### Bento grid

Below the unchanged quick actions, four compact stat tiles form a strip.
At `xl` (1280px), the strip has four equal columns. The main grid has three equal columns:

| Cell                     | Place                               | Why                                    |
| ------------------------ | ----------------------------------- | -------------------------------------- |
| **Arrivals, Departures** | First and second tiles in the strip | Units left to act on and total units   |
| **Housekeeping**         | Third tile in the strip             | Rooms that need cleaning or inspection |
| **{Month}**              | Fourth tile in the strip            | Posted revenue and booked room nights  |
| **To do today**          | Main grid, columns 1–2, top row     | Guest tasks get the most width         |
| **Tonight**              | Main grid, column 3, top row        | Occupancy and the 7-night chart        |
| **Recommended next**     | Columns 1–2, below To do            | Setup guidance stays secondary         |

- To do and Tonight share the top row and end at the same height on desktop. A short To do list keeps its card height, and its empty state centers in the card.
- Column 3 holds only Tonight. When Recommended next disappears, its row is left out and leaves no gap.
- At `xl`, the To do and Tonight row fills the rest of the page height, so the page has no blank area below the cards. The Tonight chart grows with its card. When Recommended next shows, the rows keep their natural height.
- A long To do list scrolls inside its cell on desktop.
- To do has no footer or See all arrivals link. Its business date sits below the heading as the section description.
- The header centers the icon vertically beside the title and date column. Both text lines share the same left edge.
- The item count uses an outline badge at the top-right. Task rows run edge to edge inside the card, with a border above, below and between them. There is no inner box.
- Padding sits inside each task row, not around the list. Row text lines up with the card heading.
- A row tints on hover. Its action stretches over the whole row, so a click anywhere on the row runs it; the button keeps the keyboard focus. The empty state centers in the available body space.
- Borders separate work areas. To do and recommendation rows stay flat, without nested cards.
- Every section heading has one muted 20px Lucide icon. Recommended next uses `Sparkles`; housekeeping uses `Brush`.
- Quick actions are compact buttons, not cards. Only New booking uses the primary style.
- Hidden cells leave no reserved gap. Without Tonight, To do and recommendations take the full width.
- At 768–1279px, the strip is 2×2. To do spans the full width, followed by Tonight and Recommended next side by side.
- Below 768px, the strip stays 2×2. The remaining cards stack: To do, Tonight, Recommended next.
- A task row has two lines and the button on the right. Line 1: the task badge and the guest. Line 2: muted parts with 14px icons and space between them, no dots: time (`Clock`), Today or Tomorrow for a boat row (`CalendarDays`), room (`BedDouble`), then the reference. A folio note has no icon. The parts wrap on narrow screens, never mid-part.
- Every button has an icon. The server sends its name in `ReadinessActionData::icon`, and `components/pms/action-icons.ts` maps it for both the dashboard and the night audit sheet.

### Color

- Stat tiles use a normal card and border. Only the small icon chip carries color. No left accents.
- One green: the brand `primary` token, the same green as the buttons. `success` is an olive shade and is not used on the dashboard.
- Arrivals use a primary chip. October uses a softer primary chip (`primary/5`). Departures use a warning chip. Housekeeping uses a neutral chip, and warning only when Dirty is above zero.
- A positive Dirty count shows only the word "dirty" in `warning-text`. The number keeps the plain strong style.
- Task badges use soft tints, never solid fills. Arrival uses a primary tint, Departure a warning tint with `warning-text`, and Overdue the danger tint.
- Blocked and Overdue use the danger tint with a danger dot. The label keeps strong text.
- Tonight uses the existing emerald `chart-3` token for its line and area fill. The chart is at least 84px high and grows with the card. The occupancy figure keeps strong text.
- Recommended next has a subtle primary tint. Its action and completed checks use the primary style.
- All colors use existing semantic tokens in both themes. There are no custom colors or theme overrides.
- Amber text on a warning tint uses the `warning-text` token (dark amber in light mode, light amber in dark mode), not `warning`.

### Recommended next

The checklist is a bento cell, not a separate full-width section. It starts collapsed, with progress and the first incomplete item.
**Show all** expands the remaining items inside the cell. The browser remembers this choice per property.
Existing actions, completion rules, permissions and **Not needed** controls stay unchanged. The cell disappears when every item is complete or dismissed.
The **You're live** card stays above quick actions when sign-in details still need sharing.

### Housekeeping

The cell requires `housekeeping.view`. It links to the room status board and shows Dirty, To inspect and Blocked counts.
`HousekeepingSummary` counts the existing board rows for the business date. Clean rooms count as To inspect.
Archived rooms stay out. A whole-room block replaces its underlying status, so a blocked dirty room does not count as Dirty.

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

A user with none of them sees no button row. **Run night audit** is not in this row: it sits in the page header on both tabs.

Every button that is a `Link` uses `buttonVariants()`, which runs the classes through tailwind-merge like `<Button>` does, so outline links keep their border.

## Tiles

The stat strip and Tonight card use the existing data and links. A tile the user may not see is left out.

| Tile           | Shows                                                                                                                                                                       | Link                       | Source                                                         | Permission          |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | -------------------------------------------------------------- | ------------------- |
| **Arrivals**   | "5 of 8" and "to check in": units arriving on the business date, and how many are still Confirmed                                                                           | Arrivals report, today     | `ArrivalsReport`                                               | `reservations.view` |
| **Departures** | "3 of 5" and "to check out": units departing on the business date, and how many are still CheckedIn                                                                         | Departures report, today   | `DeparturesReport`                                             | `reservations.view` |
| **Tonight**    | Occupancy % and rooms free tonight (beds as well for a property with dorms), then a 7-night area chart. Tapping or hovering opens a popover by room type: sold, total, left | Rate calendar              | `AvailabilityService::nightsForRoomTypes()` for all room types | `reservations.view` |
| **{Month}**    | "MYR 84,200 posted" and "612 nights booked" for the month of the business date                                                                                              | Revenue report, this month | `DailyRevenueQuery`, `unit_nights`                             | `reports.view`      |

**The month tile counts two different things, and says so.**

| Figure     | Counts                                                                                                                                                                                      | Tooltip                                                       |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| **Posted** | Revenue posted to folios from the 1st to the business date, by posting date: charges and discounts, without tax and payments. The same figure as the Revenue report for those dates         | "Posted to folios from 1 Oct to today."                       |
| **Booked** | Room nights in the month by stay date (`unit_nights.date`) of live units (Confirmed, CheckedIn, CheckedOut), past and future. The tooltip adds their room total (`SUM(unit_nights.amount)`) | "Room nights staying in October, booked so far: MYR 118,400." |

Showing one revenue number without saying which date it uses was the wastays bug (`c34f82a00`). Room nights post on their own date, so the two agree for past nights; "Booked" also looks ahead.

## To do today

One list of what is left to act on, with a button on each row.

| Order | Row           | When                                                                                                                     | Button           |
| ----- | ------------- | ------------------------------------------------------------------------------------------------------------------------ | ---------------- |
| 1     | **Overdue**   | CheckedIn, departure before the business date, or on it and past its `departure_time` (else the property check-out time) | **Check out**    |
| 2     | **Arrival**   | Confirmed, arriving on or before the business date, by expected `arrival_time`                                           | **Check in**     |
| 3     | **Departure** | CheckedIn, departing on the business date and not yet due, by `departure_time`                                           | **Check out**    |
| 4     | **Folio**     | The night audit's folio warnings: `MasterNotBilled`, `FolioOpenAfterCheckOut`, `MoneyHeldOnClosedBooking`                | **Review folio** |

- A reservation with several units in the same state is **one row** ("2 rooms"). Its button opens **Check in all**, or links to the [group check-out](05-group-check-out.md) page for check-out.
- The rows reuse the night audit readiness rules (`AuditReadiness`), so the dashboard and the audit never disagree. A day use shows its hours ("Day use 10:00–14:00").
- **Check in** and **Check out** open the existing Dialogs through the shared `components/pms/audit-dialog-host.tsx`.
- `GET /p/{code}/dashboard/reservations/{reservation}` (`dashboard.reservation`) loads dialog data on demand. It requires `reservations.view`, not `night-audit.run`.
- The loader scopes reservations to the property. Existing submission routes still enforce check-in, check-out and payment permissions.
- After a Dialog closes, the list, tiles, housekeeping and alert reload through `only`.
- A user without `front-desk.check-in` or `front-desk.check-out` gets **View booking** (the reservation page) instead. **Review folio** is a link and needs `reservations.view`.
- The first 10 rows show. The outline badge shows the total item count. With no rows: "Nothing left for today. All arrivals are in and all departures are out."
- With the Boats feature, a **No boat** row follows the arrivals: Confirmed units arriving today or tomorrow with no Boat in trip (see [Boat trips](../24-boats/02-boat-trips.md#dashboard)). Its button is **Set boats** (`Ship`), a link to the reservation page where the boats Dialog lives.
- Rooms to clean appear in the separate Housekeeping cell, not as guest tasks.

## Not on the dashboard

| Left out                   | Where it lives                                             |
| -------------------------- | ---------------------------------------------------------- |
| Recent bookings            | The reservations list                                      |
| Overdue AR invoices        | Finance: city ledger and aging                             |
| Room types in detail       | The Tonight popover and the rate calendar                  |
| Charts beyond the 7 nights | The Performance tab, and the Occupancy and Revenue reports |

## Loading

- **Archetype:** Dashboard (see [Page archetypes](../design-system/04-page-archetypes.md)).
- `DashboardController::show` replaces the `Route::inertia` line. Read services in `app/Services/Dashboard/`: `DashboardAlert`, `DashboardTiles`, `DashboardTodo`.
- Tiles, To do and housekeeping use separate **deferred prop groups**, each with a skeleton. The alert and quick actions come with the page.
- No polling at first. A reload or a finished Dialog refreshes it.
- A fixed number of queries, however many units and room types (the reports and `AvailabilityService::nightsForRoomTypes()` read in grouped queries).

## Tests

- Each tile and row follows its permission; a hidden tile is not sent.
- The counts and the To do rows match the Arrivals and Departures reports and the readiness checks for the same business date.
- "Posted" equals the Revenue report total without tax for the month to date; "Booked" counts live units' nights by stay date only.
- A group shows as one row. An empty day shows the empty text.
- The alert shows for a Failed audit, a blocked run and a business date behind the calendar.
