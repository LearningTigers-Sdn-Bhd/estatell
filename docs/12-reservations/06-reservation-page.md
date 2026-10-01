# Reservations list and reservation page

## Purpose

Find a reservation and see everything about it on one page, with the same flow staff know from the wastays booking workspace.

## Routes

| URL                                                 | Name                        | Page                           |
| --------------------------------------------------- | --------------------------- | ------------------------------ |
| `GET /p/{code}/reservations`                        | `reservations.index`        | `pages/reservations/index.tsx` |
| `GET /p/{code}/reservations/{reservation}`          | `reservations.show`         | `pages/reservations/show.tsx`  |
| `GET /p/{code}/reservations/{reservation}/guests`   | `reservations.guests`       | `pages/reservations/show.tsx`  |
| `GET /p/{code}/reservations/{reservation}/add-ons`  | `reservations.add-ons`      | `pages/reservations/show.tsx`  |
| `GET /p/{code}/reservations/{reservation}/activity` | `reservations.activity`     | `pages/reservations/show.tsx`  |
| `PUT /p/{code}/reservations/{reservation}/group`    | `reservations.group.update` | – (`UpdateReservationGroup`)   |

The tabs share one controller with a private `renderTab()`, like `GuestController`. The server sends only the open tab's data. The selected unit is `?unit={id}`, so a refresh keeps it.

## Permissions

| Permission            | Allows                                                                     | Default roles                       |
| --------------------- | -------------------------------------------------------------------------- | ----------------------------------- |
| `reservations.view`   | Reservations list and reservation page                                     | Owner, Manager, Front desk, Finance |
| `reservations.manage` | Create reservations, add units, attach guests, edit group name and billing | Owner, Manager, Front desk          |

The [booking form](05-booking-form.md) item adds both permissions, because it ships the first reservation route. This item uses them. Add both rows to [Roles and permissions](../03-staff/02-roles-and-permissions.md) in that item.

## Reservations list (Index archetype)

```
Reservations                                      [New ▾: New booking · Walk-in]
🔍 Ref, guest, phone, ext. ref   Status [All ▾]  Source [All ▾]  Stay dates [▾]
Ref       │ Booker           │ Arrival │ Depart. │ Units │ Rooms     │ Source  │ Status
WA1-0142  │ Tan Wei Ming 👥  │ 12 Oct  │ 15 Oct  │   5   │ 101, D1-2…│ Phone   │ ●Confirmed
WA1-0141  │ Sarah Lim        │ 10 Oct  │ 11 Oct  │   1   │ —         │ Walk-in │ ●In house
```

- Reservations are unbounded: search, filters and pagination run on the server (`spatie/laravel-query-builder`, like `GuestDirectory`).
- Arrival is the earliest unit arrival, departure the latest unit departure. 👥 marks a group (2 or more units).
- Search matches the reference, `external_reference`, and the booker's or any unit guest's name, phone or email.
- The sidebar item is **All reservations**. Arrivals, In-house and Departures come with [front desk](../18-front-desk/01-check-in-and-check-out.md).

## Reservation page (Detail archetype)

`DetailLayout`: link tabs on the left (two thirds), the summary card on the right.

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ ← Reservations                                                                           │
│ WA1-0142 · Tan family trip  [Group]  ●Confirmed ▾                [Add unit] [⋯ More ▾]   │
│ 12 Oct → 15 Oct 2026 · 5 units · Phone · Ext. ref —                                      │
├────────────────────────────────────────────────────────────┬─────────────────────────────┤
│ [🛏 Units] [👤 Guests] [🧾 Add-ons] [🕘 Activity]          │ Booker                      │
│ ─────────                                                  │ Tan Wei Ming  VIP           │
│ ┌ Choose unit ───────┐┌──────────────────────────────────┐ │ +60 12-345 6789             │
│ │ ▸ Deluxe King      ││ Deluxe King · BAR Room Only      │ │ [Open guest]                │
│ │   101 · Confirmed  ││ ●Confirmed                  [⋯]  │ │ ─────────────────────────── │
│ │   Dorm 6-Bed       ││ Stay   12 Oct → 15 Oct · 3 nights│ │ Earliest arrival  12 Oct    │
│ │   D1-2 · Confirmed ││ Guests 2 adults, 2 children (4,9)│ │ Latest departure  15 Oct    │
│ │   Dorm 6-Bed       ││ Room   101 (all nights)          │ │ Units             5         │
│ │   — · Confirmed    ││ Primary Tan Wei Ming             │ │ Room nights       15        │
│ │   Dorm 6-Bed       ││                                  │ │ Stay total   RM 2,390.00    │
│ │   — · Cancelled    ││ Night      Room  Rate     Amount │ │ Balance      from folio     │
│ │   Family Suite     ││ Mon 12 Oct 101   BAR RO   280.00 │ │ ─────────────────────────── │
│ │   auto · Confirmed ││ Tue 13 Oct 101   BAR RO   280.00 │ │ Billing   Master pays rooms │
│ │                    ││ Wed 14 Oct 101   BAR RO   280.00 │ │ Source    Phone             │
│ │                    ││                  Total    840.00 │ │ Company   —                 │
│ │                    ││ ⓘ Price override by Aina:        │ │ Created   02 Oct · Aina     │
│ │                    ││   "Corporate rate"               │ │                             │
│ └────────────────────┘└──────────────────────────────────┘ │                             │
└────────────────────────────────────────────────────────────┴─────────────────────────────┘
```

### Header

- Reference, `group_name` (or the booker's name), a **Group** badge with 2 or more units, and the reservation status.
- The status opens a **Status by unit** popover: each unit's room type, room or bed, and status.
- **Add unit** opens a Sheet with one booking line (room type, rate plan, dates, guests, room or bed) and calls `AddUnit` (needs `reservations.manage`).
- **⋯ More**: Edit group (a Dialog with group name and billing mode, `UpdateReservationGroup`), Cancel whole reservation (front desk), Print confirmation (later).

### Units tab

- **Choose unit** list on the left: room type, assigned room or bed (`—` unassigned, `auto` when `auto_assign_rooms` is on), status. Hidden when the reservation has one unit. Below `lg` it becomes a select.
- The unit panel: room type and rate plan, status, stay dates and nights, adults and child ages, room or bed (per night when it changes mid-stay), primary guest, and a night table from `unit_nights` (date, room or bed, rate plan, amount). An override shows the reason from the activity log.
- The unit **⋯** menu shows only the actions that exist and that `UnitStatus::canTransitionTo()` allows: Change room, Cancel unit, Mark no-show, Change dates, Check in, Check out. They arrive with [front desk](../18-front-desk/01-check-in-and-check-out.md). Until then the menu is hidden.

### Guests tab

```
👤 Guests                                              [+ Attach guest]
 Guest            Phone             Unit                 Role
 Tan Wei Ming     +60 12-345 6789   Deluxe King · 101    Booker · Primary
 Ahmad Faiz       +60 13-888 2211   Dorm Bed · D1-2      Primary
 —                                  Dorm Bed · —         [Attach guest]
```

Attach guest opens a Sheet with the guest autocomplete field and "Stays in" (`AttachGuest`).

### Add-ons tab

Unit, charge item, quantity, service date, amount. **+ Add add-on** uses `AddUnitAddOn` (see [Add-ons](04-add-ons.md)).

### Activity tab

The reservation's activity log entries (`spatie/laravel-activitylog`), newest first, paginated: creation, units added, overrides with their reasons, group edits, status changes.

### Summary card

Booker (name, VIP, phone, email, link to the guest), earliest arrival, latest departure, units, room nights, stay total (sum of `unit_nights.amount` of units that hold inventory), balance ("from folio" until [module 13](../13-folio/01-folios-and-lines.md)), billing mode (2 or more units), source, company, created date and user.

## Data

- `Reservations/ReservationListItemData`, `Reservations/ReservationData` (header and summary), `Reservations/ReservationUnitData` (unit panel with nights), `Reservations/ReservationGuestData`. Totals and dates are calculated on the server.
- The page gets a query-count test with lazy loading prevented (see `.ai/rules/tests.md`).

## Comes later

| Part                                            | Module                                                                    |
| ----------------------------------------------- | ------------------------------------------------------------------------- |
| Folios and Billing tabs, real balance           | [Folio](../13-folio/01-folios-and-lines.md)                               |
| Payments and deposits                           | [Payments and refunds](../14-payments/01-payments-and-refunds.md)         |
| Unit actions, Arrivals, In-house and Departures | [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md)   |
| Room & Rate editing, room moves                 | [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md) |
| Registration card in a Documents tab            | [Registration](../16-registration/04-signing-flow.md)                     |

## Lessons from wastays

- The booking workspace had 9 tabs and a 2,300-line `WorkspacePresenter`. estatell starts with 4 tabs, and each later module adds its own tab with its own controller method and Data class.
- wastays showed a left rail only on some tabs. estatell shows the unit list only on the Units tab, and only for 2 or more units.
