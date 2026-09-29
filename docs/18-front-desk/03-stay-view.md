# Stay View

## Purpose

The front desk's main screen: which room or bed each guest is in, night by night, with the room status and what needs action. Also called the tape chart.

## Modes

| Mode         | Shows                                                              | Main use                                               |
| ------------ | ------------------------------------------------------------------ | ------------------------------------------------------ |
| **Timeline** | Rooms and beds (rows) × nights (columns), with stay and block bars | Planning, assignment, availability                     |
| **Rooms**    | One card per room or bed for a single date                         | Today's operations: arrivals, departures, housekeeping |

The mode switch happens in the browser. It never makes a request.

## Screen

```
┌ Stay view ──────────────────────────────────────────────────────────────────┐
│ [Timeline|Rooms]  ◀ 12 Jun – 25 Jun ▶  [Today]  Room type ⌄  Status ⌄  🔍    │  StayViewToolbar
├──────────────┬──────┬──────┬──────┬──────┬──────┬──────┬──────┬────────────┤
│              │Fri 12│Sat 13│Sun 14│Mon 15│Tue 16│Wed 17│Thu 18│ …          │  DateHeader
│              │RM240 │RM280 │RM280 │RM240 │RM240 │RM240 │RM240 │            │  NightlyRateRow
├ Left wing ───┴──────┴──────┴──────┴──────┴──────┴──────┴──────┴────────────┤  GroupHeader
│ 201 Deluxe ● │▐ Ahmad Faris · 2A ████████▌      │▐ Lim Wei ██████▌          │  RoomRow + StayBar
│ 202 Deluxe ◐ │      │▐ ABC Corp (group) ████████████████████▌│             │
│ 203 Deluxe ✕ │░░░░ Maintenance ░░░░░│      │      │      │      │            │  BlockBar
├ Dorm A ──────┴──────┴──────┴──────┴──────┴──────┴──────┴──────┴────────────┤
│   Bed A1     │▐ Sarah T ███▌│      │▐ Tom K █████████████▌   │             │
│   Bed A2     │      │  +   │      │      │      │      │      │            │  empty cell → CellActions
├──────────────┼──────┼──────┼──────┼──────┼──────┼──────┼──────┼────────────┤
│ Available    │ 4/12 │ 1/12 │ 2/12 │ 6/12 │ 7/12 │ 9/12 │ 8/12 │            │  InventoryFooter
│ Occupancy    │ 67%  │ 92%  │ 83%  │ 50%  │ 42%  │ 25%  │ 33%  │            │
└──────────────┴──────┴──────┴──────┴──────┴──────┴──────┴──────┴────────────┘
 ● clean  ◐ dirty  ✕ out of service   ▌ balance due   ⓘ legend                   StatusLegend
```

- Clicking a stay bar opens **`StayPeekSheet`**: guest, dates, room or bed, balance, and actions (Check in, Check out, Move, Record payment, Open reservation).
- Clicking an empty cell opens **`CellActions`**: New booking here, Block room.

## Data loading

The server sends compact, normalized data. The browser derives everything it shows. See [Data loading](../design-system/08-data-loading-and-grids.md).

### Props

| Prop                 | Content                                                                                                                                                                                   | Loaded                   |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| `window`             | from, to, today, business date                                                                                                                                                            | Immediate                |
| `rooms`              | All rooms and beds: id, label, room group, room type, sell unit, room status (`HousekeepingStatus`; out of service comes from `blocks`), priority, do-not-disturb                         | Immediate (bounded list) |
| `stays`              | Units overlapping the window: unit id, reservation id and reference, guest label, pax, unit status, tone, balance-due flag, group name, **segments** (room or bed + first and last night) | Immediate                |
| `blocks`             | id, room or bed, first and last night, reason                                                                                                                                             | Immediate                |
| `rates`              | Standard rate per room type per date                                                                                                                                                      | Immediate                |
| `version`            | Hash of the latest change for this property and window                                                                                                                                    | Immediate                |
| `filters`, `options` | Current filters (from the URL) and `EnumSelect` items                                                                                                                                     | Immediate                |

- Segments come from `unit_nights`. Consecutive nights in the same room or bed form one segment. A room move creates a new segment.
- Default window: 14 days. Maximum: 30 days.

### Done in the browser (no request)

| Work                                                       | Function or component                               |
| ---------------------------------------------------------- | --------------------------------------------------- |
| Place bars: column start, span, clipped left or right edge | `projectBars()` (pure function, unit tested)        |
| Filter by room type, room group, status, search text       | `filterStays()` (pure function, unit tested)        |
| Group rows by room group or room type                      | `StayViewTimeline`                                  |
| Switch Timeline ↔ Rooms                                    | Page state                                          |
| Footer availability and occupancy per date                 | `summarizeInventory()` (pure function, unit tested) |
| Peek sheet summary                                         | From `stays`                                        |

### Done on the server

| Work                           | How                                                                                                                                     |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| Load another date window       | Inertia visit with the new window in the URL. Previous and next windows are **prefetched**. Visited windows are cached for the session. |
| Folio lines in the peek sheet  | Loaded on open with `useHttp`, shown with a `Skeleton`                                                                                  |
| Every write                    | Existing Actions (`CheckInUnit`, `MoveUnit`, `AssignRoom`, `AssignBed`, `CreateRoomBlock`, `RecordPayment`, `UpdateRoomStatus`)         |
| Availability for a new booking | `AvailabilityService` in the booking flow. Footer counts are display only.                                                              |

## Liveness

| Mechanism         | When                                                    | How                                                                                                               |
| ----------------- | ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Version polling   | Every 15 seconds while the tab is visible               | `useHttp` GET `stay-view/version?from=…&to=…`. If the hash changed, partial reload of `stays`, `blocks`, `rooms`. |
| After an Action   | Check-in, move, block, payment from the sheet           | The Action redirects back; partial reload of `stays`, `blocks`, `rooms`                                           |
| Optimistic update | Quick changes (mark room clean, do-not-disturb)         | Inertia 3 optimistic update, rolled back if the server rejects                                                    |
| Conflict          | Two staff assign the same room or bed on the same night | Database constraint rejects; `toast` "Bed A1 was just taken"; partial reload                                      |

Future, only if 15-second polling is not enough: a `StayViewChanged` broadcast per property channel that triggers the same partial reload. Needs Laravel Reverb and Echo (new dependencies).

## Code layout

```
resources/js/
├── pages/front-desk/stay-view.tsx
└── components/pms/
    ├── grid/                               shared with the rate calendar
    │   ├── grid-viewport.tsx
    │   ├── use-grid-virtualizer.ts
    │   └── date-columns.tsx
    └── stay-view/
        ├── stay-view-toolbar.tsx
        ├── stay-view-timeline.tsx
        ├── room-row.tsx
        ├── stay-bar.tsx
        ├── block-bar.tsx
        ├── cell-actions.tsx
        ├── nightly-rate-row.tsx
        ├── inventory-footer.tsx
        ├── stay-peek-sheet.tsx
        ├── rooms-board.tsx
        ├── status-legend.tsx
        ├── project-bars.ts
        ├── filter-stays.ts
        ├── summarize-inventory.ts
        └── use-stay-view-version.ts
```

```
app/
├── Http/Controllers/FrontDesk/StayViewController.php     index, version
├── Services/StayView/StayViewQuery.php                    loads rooms, stays (with segments), blocks, rates
├── Services/StayView/StayViewVersion.php                  hash of latest change in the window
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

- Built first for the [rate calendar](../08-rates/02-daily-rates-and-restrictions.md), then reused here.
- CSS grid with one column per night and a sticky first column and header.
- `@tanstack/react-virtual` virtualizes rows (100+ rooms and beds) and columns.
- Fixed row height. Bars are placed with `grid-column: start / span n`. No layout measuring.

## Rules

- Stay bars use `StatusBadge` tones from `UnitStatus::tone()`. The label is always shown; color never carries meaning alone.
- Balance-due is a single flag on `StayData`, shown as an edge marker and in the peek sheet.
- Props are built per permission. Guests' contact details and ID data are never in `stays`.
- Filters and the date window live in the URL so views can be bookmarked and the back button works.
- Forms opened from Stay View use a **Sheet** (see [Form containers](../design-system/05-form-containers.md)), so the grid stays visible.

## Permissions

`front-desk.view`, plus the permissions of each Action (`front-desk.check-in`, `front-desk.check-out`, `payment.record`, `housekeeping.update`, and so on). Actions without permission are hidden in `StayPeekSheet` and `CellActions`.

## Budgets

| Measure                         | Budget                               |
| ------------------------------- | ------------------------------------ |
| Page props response time (p95)  | under 200 ms                         |
| Version poll response time      | under 50 ms                          |
| Payload for 100 rooms × 14 days | under ~60 KB gzipped                 |
| Queries for the page            | Fixed count, asserted in a Pest test |
| Filter, mode switch, open peek  | zero requests                        |

## Tests

- Pest: `StayViewQuery` returns correct segments for moves, groups and bed stays; query count is fixed; version hash changes after an Action; props exclude data the user may not see.
- Browser unit tests for `projectBars()`, `filterStays()` and `summarizeInventory()` (runner decided when first written: `vp test` or Vitest).

## Out of scope for now

- Drag-and-drop to move or resize bars. Use Move in the peek sheet.
- Real-time push.
- Overbooking resolution tools beyond the night audit readiness panel.

## Lessons from wastays

wastays' Stay View was about 6,500 lines: 15 services (`BuildBoard`, `LoadInventory`, `ProjectBooking`, `ResolveFinancialSignals`, …), 15 ViewComponents, 5 Stimulus controllers (`interaction_controller.js` 494 lines) and a 349-line helper.

- Every filter change called `requestSubmit()` and the server rebuilt the whole board as HTML.
- Side sheets were fetched as Turbo frames on each open.
- There was no live update.
- `ResolveFinancialSignals` (272 lines) is replaced by one balance-due flag.
