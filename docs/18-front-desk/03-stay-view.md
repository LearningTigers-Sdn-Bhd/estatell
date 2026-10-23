# Stay View

## Purpose

The front desk's main screens: which room or bed each guest is in, with the room status and what needs action. Also called the tape chart.

## Views

Two sidebar items under **Overview**, below Dashboard. Each is its own route. They share one query, one set of Data classes and one filter state.

| View              | Route                 | Shows                                                              | Main use                                               |
| ----------------- | --------------------- | ------------------------------------------------------------------ | ------------------------------------------------------ |
| **Timeline View** | `front-desk.timeline` | Rooms and beds (rows) × nights (columns), with stay and block bars | Planning, assignment, availability                     |
| **Room View**     | `front-desk.rooms`    | One card per room or bed for a single date (`?date=`)              | Today's operations: arrivals, departures, housekeeping |

- Changing view is a normal Inertia visit from the sidebar. There is no in-page mode toggle.
- The sidebar links carry the current filters and Group by choice, so they are kept when you change view.
- Filters, Group by, search and the peek make no request.

## Toolbar

| Control        | Type                                             | Notes                                                                                     |
| -------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| Date controls  | Previous, next, **Today**, date range            | Timeline: a window. Room View: one date                                                   |
| **Room group** | Multi-select combobox with counts                | Filter. Items come from `rooms`. Nothing selected means all. Placeholder "e.g. Left wing" |
| **Room type**  | Multi-select combobox with counts                | Filter. Same rules                                                                        |
| **Status**     | Multi-select                                     | Filter by housekeeping status                                                             |
| **Show**       | Multi-select                                     | Room View only: Arriving, In house, Departing, Vacant, Blocked                            |
| **Group by**   | Select, items from `StayViewGrouping::options()` | None, Room group (default), Room type                                                     |
| **Show rates** | Toggle                                           | Timeline only: shows or hides the rate row. Remembered in the browser                     |
| Search         | Text                                             | Guest, reference or room. Placeholder "e.g. Ahmad or 201"                                 |

- On narrow screens, Room group, Room type, Status, Group by and Show rates collapse into one **Filters** popover. Date controls and Search stay visible.
- Group by sets the row or card headers only. Filters decide which rooms appear.
- Beds stay under their dorm in every option. With Room type, dorm beds group under the dorm's room type. With None, beds list under their room's number.
- Each header shows its row count. On the Timeline a header can collapse, as in the rate calendar.

## Timeline View screen

The page is a Grid calendar: no `PageHeader`, the toolbar on top and the grid filling the window. It uses the `fixed` layout variant, like the [rate calendar](../08-rates/02-daily-rates-and-restrictions.md).

```
┌ Timeline View ───────────────────────────────────────────────────────────────────┐
│ ◀ 12 Jun – 25 Jun ▶  [Today]   Room group ⌄  Room type ⌄  Status ⌄  Group by ⌄ ☑ Show rates 🔍 │  StayViewToolbar
├───────────────┬───────┬───────┬───────┬───────┬───────┬───────┬───────┬──────────┤
│               │ Fri 12│ Sat 13│ Sun 14│ Mon 15│ Tue 16│ Wed 17│ Thu 18│   …      │  DateHeader
│ Deluxe ⌄      │ RM240 │ RM280 │ RM280 │ RM240 │ RM240 │ RM240 │ RM240 │          │  NightlyRateRow
│ Walk-in ⌄     │       │       │       │       │       │       │       │          │
├ Left wing ────┴───────┴───────┴───────┴───────┴───────┴───────┴───────┴──────────┤  GroupHeader
│ 201 Deluxe  ● │▐ Ahmad Faris · 2A ████████▌   │▐ Lim Wei ███████▌                │  RoomRow + StayBar
│ 202 Deluxe  ◐ │       │▐◆ ABC Corp · Tan Mei ██████████████████▌                 │
│ 203 Deluxe  ✕ │░░░░░░ Maintenance ░░░░░░░░░│       │       │       │       │      │  BlockBar
├ Dorm A ───────┴───────┴───────┴───────┴───────┴───────┴───────┴───────┴──────────┤
│   Bed A1      │▐ Sarah T ███▌ │       │▐ Tom K ██████████▌                       │
│   Bed A2      │       │   +   │       │       │       │       │       │          │  empty cell → CellActions
├───────────────┼───────┼───────┼───────┼───────┼───────┼───────┼───────┼──────────┤
│ Available     │  4/12 │  1/12 │  2/12 │  6/12 │  7/12 │  9/12 │  8/12 │          │  InventoryFooter
│ Occupancy     │  67%  │  92%  │  83%  │  50%  │  42%  │  25%  │  33%  │          │
└───────────────┴───────┴───────┴───────┴───────┴───────┴───────┴───────┴──────────┘
 ● clean  ◐ dirty  ✕ out of service   ▌ balance due   ◆ group   ⓘ legend             StatusLegend
```

- A stay bar is one segment: the nights in the same room or bed. A room move starts a new bar in the new row. A bar clipped by the window shows an open edge.
- The bar label is the guest and pax, with the unit status as a `StatusBadge`.
- **Group bookings.** One bar per unit, each in its own room or bed. Every bar of a group has the `◆` marker and the group name. A label reads `ABC Corp · Tan Mei`, or `ABC Corp · Unit 2` with no named guest. Hovering a bar softly highlights the other bars with the same `reservationId`. A group booking is a reservation with a group name, so there is no separate group id. Status is per unit. The balance-due flag is the reservation's.
- The footer counts only the visible rows, so it matches the screen.
- **Rate row.** One row under the date header. Its label has two selects: a **room type** (default: the first room type in the property's order) and a **rate plan** (default: the first plan in the property's plan order). Each cell shows that plan's price for that room type and night, or "–" when the plan has no price or is closed that night. Derived plans use the same calculation as the rate calendar, so the numbers match. Changing a select, or the **Show rates** toggle, makes no request. Both choices are remembered in the browser, not in the URL. There is no hover card.
- Clicking a stay bar opens **`StayPeekSheet`**. Clicking an empty cell opens **`CellActions`**: New booking here, Block room.

## Room View screen

```
┌ Room View ───────────────────────────────────────────────────────────────────────┐
│ ◀ Fri 12 Jun 2026 ▶ [Today]  Room group ⌄ Room type ⌄ Status ⌄ Show ⌄ Group by ⌄ 🔍 │
│ Arriving 5 · Departing 3 · In house 7 · Vacant 4 · Blocked 1                      │  day summary
├ Left wing ───────────────────────────────────────────────────────────────────────┤
│ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐     │
│ │ 201 Deluxe   ● │ │ 202 Deluxe   ◐!│ │ 203 Deluxe   ● │ │ 204 Deluxe   ✕ │     │
│ │ [In house]     │ │ [Departing]    │ │ [Arriving]     │ │ Maintenance    │     │
│ │ Ahmad Faris·2A │ │ ◆ ABC · Tan Mei│ │ ◆ ABC · Raj K  │ │ until 14 Jun   │     │
│ │ out Sun 14     │ │ out today 12:00│ │ in today 14:00 │ │                │     │
│ │ ▌ RM 120 due   │ │                │ │                │ │                │     │
│ └────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘     │
│ ┌────────────────┐ ┌────────────────┐                                            │
│ │ 205 Deluxe   ● │ │ 206 Deluxe   ● │                                            │
│ │ Vacant         │ │ [In house] 🔕  │                                            │
│ │ + New booking  │ │ Lim Wei · 1A   │                                            │
│ │   Block room   │ │ out Mon 15     │                                            │
│ └────────────────┘ └────────────────┘                                            │
└──────────────────────────────────────────────────────────────────────────────────┘
 ● clean  ◐ dirty  ✕ out of service  🔕 do not disturb  ▌ balance due  ◆ group  ! priority
```

- A card shows the room or bed, the type, the housekeeping status icon and label, the stay state for the date, the guest and pax, the arrival or departure time, the balance-due flag and do-not-disturb.
- A room with a departure and an arrival on the date shows both lines, Departing first.
- A dirty room that must be ready today shows `!`, from the housekeeping `priority`.
- A vacant card offers **New booking** and **Block room**.
- Clicking a card with a stay opens the same `StayPeekSheet`.
- Mark room clean and do-not-disturb are quick optimistic changes on the card.
- There is no rate row, no bars and no footer. The day summary line replaces the footer.
- Cards are ordered by the Group by choice, then by room number.

## Peek sheet

`StayPeekSheet` is a Sheet on the right, so the grid stays visible.

```
┌ Tan Mei ──────────────────── ✕ ┐
│ ◆ ABC Corp · group of 3 rooms    │
│ Res. R-10517                     │
│ [Checked in]                     │
│ 14 Jun → 18 Jun · 202 Deluxe     │
│ Balance due: RM 0.00             │
│ ─ This group ────────────────    │
│  202  Tan Mei    Checked in      │
│  203  Raj K      Checked in      │
│  204  Siti A     Confirmed       │
│ ─ Folio ─────────────────────    │
│  ▒▒▒▒▒▒▒▒▒▒▒▒▒▒ (skeleton)       │
│ [Open reservation] [Open group]  │
└──────────────────────────────────┘
```

- The summary and the "This group" list come from `stays`. Folio lines load on open with `useHttp` and a `Skeleton`.
- **Open reservation** and **Open group** link to the reservation or group page. Check in, Check out, Move and Record payment are done there for now, because the project allows one overlay level and their dialogs would sit on top of the Sheet. In-sheet actions are a later item.
- Block room and New booking from `CellActions` open Sheets.

## Data loading

The server sends compact, normalized data. The browser derives everything it shows. See [Data loading](../design-system/08-data-loading-and-grids.md).

### Props

| Prop                 | Content                                                                                                                                                                                                                                                                            | Loaded                   |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| `window`             | from, to, today, business date                                                                                                                                                                                                                                                     | Immediate                |
| `rooms`              | All rooms and beds: id, label, room group, room type, sell unit, room status (`HousekeepingStatus`; out of service comes from `blocks`), priority, do-not-disturb                                                                                                                  | Immediate (bounded list) |
| `stays`              | Units overlapping the window: unit id, reservation id and reference, guest label, pax, unit status, tone, balance-due flag (the reservation's), group name, arrival and departure time, **segments** (row key `room-{id}` or `bed-{id}` + first and last night, cut to the window) | Immediate                |
| `blocks`             | id, room or bed, first and last night, reason                                                                                                                                                                                                                                      | Immediate                |
| `rates`              | Price per room type, rate plan and date for the active rate plans, and the plan list in order. Timeline View only                                                                                                                                                                  | Immediate                |
| `version`            | Hash of the latest change for this property and window                                                                                                                                                                                                                             | Immediate                |
| `filters`, `options` | Current filters, Group by and date (from the URL) and `EnumSelect` items                                                                                                                                                                                                           | Immediate                |

- Segments come from `unit_nights`. Consecutive nights in the same room or bed form one segment. A room move creates a new segment.
- Timeline default window: 14 days. Maximum: 30 days.
- Room View uses the same query with `from = to = date`, and leaves out `rates`.
- The balance-due flag comes from one grouped query for all stays in the window, not one query per stay.

### Done in the browser (no request)

| Work                                                       | Function or component                               |
| ---------------------------------------------------------- | --------------------------------------------------- |
| Place bars: column start, span, clipped left or right edge | `projectBars()` (pure function, unit tested)        |
| Filter by room group, room type, status, search text       | `filterStays()` (pure function, unit tested)        |
| Group rows or cards by room group or room type             | `StayViewTimeline`, `RoomsBoard`                    |
| Footer availability and occupancy per date                 | `summarizeInventory()` (pure function, unit tested) |
| Group highlight, "This group" list, peek summary           | From `stays`                                        |

### Done on the server

| Work                           | How                                                                                                                                             |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Load another date or window    | Inertia visit with the new date in the URL. Previous and next windows (or days) are **prefetched**. Visited windows are cached for the session. |
| Change view                    | Sidebar visit to the other route, with the current filters in the URL                                                                           |
| Folio lines in the peek sheet  | Loaded on open with `useHttp`, shown with a `Skeleton`                                                                                          |
| Every write                    | Existing Actions (`AssignRoom`, `AssignBed`, `CreateRoomBlock`, `UpdateRoomStatus`; the rest through the reservation page)                      |
| Availability for a new booking | `AvailabilityService` in the booking flow. Footer counts are display only.                                                                      |

## Liveness

| Mechanism         | When                                                    | How                                                                                                               |
| ----------------- | ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Version polling   | Every 15 seconds while the tab is visible               | `useHttp` GET `stay-view/version?from=…&to=…`. If the hash changed, partial reload of `stays`, `blocks`, `rooms`. |
| After an Action   | Block, room status change from the Sheet or card        | The Action redirects back; partial reload of `stays`, `blocks`, `rooms`                                           |
| Optimistic update | Quick changes (mark room clean, do-not-disturb)         | Inertia 3 optimistic update, rolled back if the server rejects                                                    |
| Conflict          | Two staff assign the same room or bed on the same night | Database constraint rejects; `toast` "Bed A1 was just taken"; partial reload                                      |

Future, only if 15-second polling is not enough: a `StayViewChanged` broadcast per property channel that triggers the same partial reload. Needs Laravel Reverb and Echo (new dependencies).

## Code layout

```
resources/js/
├── pages/front-desk/
│   ├── timeline.tsx
│   └── rooms.tsx
└── components/pms/
    ├── grid/                               shared with the rate calendar (DateGrid, date-columns, use-viewport-fit)
    └── stay-view/
        ├── stay-view-toolbar.tsx           shared by both views
        ├── stay-view-filters.tsx           Room group, Room type, Status, Group by, Filters popover
        ├── stay-view-timeline.tsx
        ├── room-row.tsx
        ├── stay-bar.tsx
        ├── block-bar.tsx
        ├── cell-actions.tsx
        ├── nightly-rate-row.tsx            room type and rate plan selects, prices
        ├── inventory-footer.tsx
        ├── stay-peek-sheet.tsx
        ├── rooms-board.tsx
        ├── room-card.tsx
        ├── status-legend.tsx
        ├── project-bars.ts
        ├── filter-stays.ts
        ├── summarize-inventory.ts
        └── use-stay-view-version.ts
```

```
app/
├── Enums/StayViewGrouping.php                            None, RoomGroup, RoomType
├── Http/Controllers/FrontDesk/StayViewController.php     timeline, rooms, version
├── Services/StayView/StayViewQuery.php                   loads rows, stays (with segments), blocks and rates
├── Services/StayView/StayViewVersion.php                 hash of latest change in the window
└── Data/StayView/
    ├── StayViewWindowData.php
    ├── RoomRowData.php
    ├── StayData.php
    ├── StaySegmentData.php
    ├── BlockData.php
    └── DateRateData.php
```

TypeScript types come from the Data classes through `spatie/laravel-typescript-transformer`.

## Grid engine

- Built first for the [rate calendar](../08-rates/02-daily-rates-and-restrictions.md), then reused here (`DateGrid`).
- CSS grid with one column per night and a sticky first column, header and footer. Rows and columns are virtualized with `@tanstack/react-virtual`.
- Fixed row height. `DateGrid` places cells with absolute offsets, so a stay bar is an overlay layer in each row. It uses the same column width and offsets, and `projectBars()` clips it to the window. `DateGrid` gets an optional `renderRowOverlay` prop that draws this layer inside each row. The rate row stays in view under the date header, so `DateGrid` may also need an optional pinned row prop (to verify: it has a sticky footer today, not a sticky row under the header). Both props are additive. Run the rate calendar tests after the change.

## Rules

- Stay bars use `StatusBadge` tones from `UnitStatus::tone()`. The label is always shown; color never carries meaning alone.
- Balance-due is a single flag on `StayData`: the reservation's balance is above 0, from `FolioBalance::forReservations`. It is shown as an edge marker and in the peek sheet.
- Props are built per permission. Guests' contact details and ID data are never in `stays`.
- Filters, Group by and the date live in the URL so views can be bookmarked and the back button works.
- Forms opened from the Stay View use a **Sheet** (see [Form containers](../design-system/05-form-containers.md)), so the grid stays visible.
- Every input gets an "e.g. …" placeholder.

## Permissions

`front-desk.view` guards both views and the version route. It is a new permission, granted by default to Owner, Manager and Front desk (see [Roles and permissions](../03-staff/02-roles-and-permissions.md)). Add it with a migration that calls `SyncPermissions`.

Other permissions decide what the Sheets and cards show: `rooms.block` (Block room), `reservations.manage` (New booking here), `housekeeping.update` (mark clean, do-not-disturb) and `reservations.view` (Open reservation). Actions without permission are hidden.

## Budgets

| Measure                         | Budget                               |
| ------------------------------- | ------------------------------------ |
| Page props response time (p95)  | under 200 ms                         |
| Version poll response time      | under 50 ms                          |
| Payload for 100 rooms × 14 days | under ~60 KB gzipped                 |
| Queries for the page            | Fixed count, asserted in a Pest test |
| Filter, Group by, open peek     | zero requests                        |

## Tests

- Pest: `StayViewQuery` returns correct segments for moves, groups and bed stays; query count is fixed and does not grow with the number of stays; version hash changes after an Action; props exclude data the user may not see; a user without `front-desk.view` gets 403.
- Browser unit tests (`vp test`) for `projectBars()`, `filterStays()` and `summarizeInventory()`.

## Out of scope for now

- Drag-and-drop to move or resize bars. Use the reservation page.
- Check in, Check out, Move and Record payment inside the peek sheet.
- Real-time push.
- Overbooking resolution tools beyond the night audit readiness panel.

## Lessons from wastays

wastays' Stay View was about 6,500 lines: 15 services (`BuildBoard`, `LoadInventory`, `ProjectBooking`, `ResolveFinancialSignals`, …), 15 ViewComponents, 5 Stimulus controllers (`interaction_controller.js` 494 lines) and a 349-line helper.

- Every filter change called `requestSubmit()` and the server rebuilt the whole board as HTML.
- Side sheets were fetched as Turbo frames on each open.
- There was no live update.
- `ResolveFinancialSignals` (272 lines) is replaced by one balance-due flag.
