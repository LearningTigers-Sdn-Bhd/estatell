# Reservations list and reservation page

## Purpose

Find a reservation and see everything about it on one page, with the same flow staff know from the wastays booking workspace.

## Routes

| URL                                                    | Name                                  | Page                                                      |
| ------------------------------------------------------ | ------------------------------------- | --------------------------------------------------------- |
| `GET /p/{code}/reservations`                           | `reservations.index`                  | `pages/reservations/index.tsx`                            |
| `GET /p/{code}/reservations/{reservation}`             | `reservations.show`                   | `pages/reservations/show.tsx`                             |
| `GET /p/{code}/reservations/{reservation}/guests`      | `reservations.guests`                 | `pages/reservations/show.tsx`                             |
| `GET /p/{code}/reservations/{reservation}/add-ons`     | `reservations.add-ons`                | `pages/reservations/show.tsx`                             |
| `GET /p/{code}/reservations/{reservation}/folio`       | `reservations.folio`                  | `pages/reservations/show.tsx`                             |
| `…/{reservation}/folios/{folio}/…` (six action routes) | `reservations.folios.*`               | – (see [Folio page](../13-folio/05-folio-page.md#routes)) |
| `GET /p/{code}/reservations/{reservation}/activity`    | `reservations.activity`               | `pages/reservations/show.tsx`                             |
| `PUT /p/{code}/reservations/{reservation}/group`       | `reservations.group.update`           | – (`UpdateReservationGroup`)                              |
| `POST …/{reservation}/units`                           | `reservations.units.store`            | – (`AddUnit`)                                             |
| `POST …/{reservation}/units/{unit}/guests`             | `reservations.units.guests.store`     | – (`AttachGuest`)                                         |
| `POST …/{reservation}/units/{unit}/add-ons`            | `reservations.units.add-ons.store`    | – (`AddUnitAddOn`)                                        |
| `DELETE …/{reservation}/units/{unit}/add-ons/{addOn}`  | `reservations.units.add-ons.destroy`  | – (`RemoveUnitAddOn`)                                     |
| `GET /p/{code}/guests/search`                          | `guests.search` (JSON, `guests.view`) | –                                                         |

Routes live in `routes/reservations.php` with `scopeBindings()`, so another property's reservation, unit or add-on returns 404. Pages are guarded with `can:` middleware, like the guests routes (no Policy classes). The tabs share one controller with a private `renderTab()`, like `GuestController`. The server sends only the open tab's data. The selected unit is `?unit={id}`, so a refresh keeps it.

## Permissions

| Permission            | Allows                                                                     | Default roles                       |
| --------------------- | -------------------------------------------------------------------------- | ----------------------------------- |
| `reservations.view`   | Reservations list and reservation page                                     | Owner, Manager, Front desk, Finance |
| `reservations.manage` | Create reservations, add units, attach guests, edit group name and billing | Owner, Manager, Front desk          |

This item adds both permissions (a migration calls `SyncPermissions`, like the guest permissions), because it ships the first reservation routes. Add both rows to [Roles and permissions](../03-staff/02-roles-and-permissions.md) in this item. This item also adds `AttachGuest`, `guests.search` and `GuestPicker`. The [booking form](05-booking-form.md) comes next and reuses them.

The booker's phone, email and **Open guest** need `guests.view`. Without it, staff see the booker's name and flags only.

## Reservations list (Index archetype)

Status tabs, filters, and two views of the same page of results: **Cards** (default, like the wastays "Rooms" view) and **List**.

```
┌──────────────────────────────────────────────────────────────────────────────────────────────┐
│ Reservations                                                      [+ New booking ▾]          │
│ [📅 All 312] [● Confirmed 41] [🛏 In house 12] [✓ Completed 240] [✕ Cancelled 19]            │
│ ════════                                                                                     │
│ 🔍 [Ref, guest, phone, email, ext. ref ]  [All sources ▾]  Stay [02 Oct → … 📅] [Reset]      │
│                                        Sort [Arrival ↑ ▾]   View [▦ Cards | ☰ List]          │
├──────────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────────────────┐ ┌───────────────────────────┐ ┌───────────────────────────┐    │
│ │ (reservation card)        │ │ (reservation card)        │ │ (reservation card)        │    │
│ └───────────────────────────┘ └───────────────────────────┘ └───────────────────────────┘    │
│ 1–24 of 312                                                              ‹ Prev   Next ›     │
└──────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Controls

| Control                  | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **New booking ▾**        | New booking, Walk-in (see [booking form](05-booking-form.md#entry-points)). Needs `reservations.manage`. Hidden until the booking form item ships.                                                                                                                                                                                                                                                                        |
| **Status tabs**          | All, Confirmed, In house, Completed, Cancelled, each with an icon and a count. Each tab is a link that sets `filter[status]`. The counts follow the current search, source and stay filters (one grouped count query).                                                                                                                                                                                                    |
| Search                   | Reference, `external_reference`, and the booker's or any unit guest's name, phone or email. A local phone number (`012-345 6789`) matches the stored E.164 number, using the `GuestMatcher` normalisation. Debounced (`FilterBar`).                                                                                                                                                                                       |
| Source                   | All, or one `ReservationSource`                                                                                                                                                                                                                                                                                                                                                                                           |
| Stay dates               | Reservations whose stay overlaps the range. **Default: from the current business date onward** (`CurrentBusinessDate`, or `PropertyToday`), an open-ended range: `DateRangePicker` gains an optional end. Clearing the range shows all dates (`filter[stay]=all`). The Completed and Cancelled tabs show a "Showing stays from {date}. Show all dates" hint. **Reset** clears search, source and stay, and keeps the tab. |
| Sort                     | Arrival (default, earliest first), departure, reference, total, booked date. List view sorts by column header; Cards view uses the Sort select. Both use the same `sort` URL value.                                                                                                                                                                                                                                       |
| **View** (Cards or List) | Switches the view of the loaded page and **makes no request**. Kept in the URL (`?view=list`) and remembered in the browser (`localStorage`). Default: Cards.                                                                                                                                                                                                                                                             |

- Reservations are unbounded: search, filters and pagination run on the server (`spatie/laravel-query-builder`, like `GuestDirectory`). All filters live in the URL.
- One row or card per reservation. A group is not expanded into units.
- Clicking a card or a row opens the reservation.
- The sidebar item is **All reservations**. Arrivals, In-house and Departures come with [front desk](../18-front-desk/01-check-in-and-check-out.md) and can reuse the card and the view toggle.

### Reservation card

`components/pms/reservations/reservation-card.tsx`, based on the wastays front desk stay card. Grid: 1 column, 2 from `md`, 4 from `xl`.

```
┌─────────────────────────────────────────────┐
│ ┌───────┐│ Tan family trip  👥 ★ ↻    [⋯] │  header: room box │ title + markers │ menu
│ │ 101   ││ Tan Wei Ming · WA1-0142          │  subtitle: booker (groups) and reference
│ │ D1-2  ││                                  │
│ │ +3    ││                                  │
│ └───────┘│                                  │
├─────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────┐ │
│ │ Arrival    │  Stay     │     Departure  │ │  muted strip
│ │ 12 Oct     │ 3 nights  │        15 Oct  │ │
│ └─────────────────────────────────────────┘ │
│ [●Confirmed] ☎  5 units     👤 6   🧒 2     │  status, source, units (groups), occupancy
│ Room type               Booked              │
│ 1× Deluxe King, 3× Dorm  02 Oct 2026        │
│ 6-Bed, 1× Family Suite                      │
│ ┌─────────────┬─────────────┬─────────────┐ │
│ │ Total       │ Paid        │ Balance     │ │  money strip (tabular-nums)
│ │ 2,390.00    │ 500.00      │ 412.60      │ │
│ └─────────────┴─────────────┴─────────────┘ │
└─────────────────────────────────────────────┘
```

| Part        | Shows                                                                                                                                       |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Room box    | See [Room box](#room-box)                                                                                                                   |
| Title       | `group_name`, or the booker's name. For a channel booking without a group name: the booker.                                                 |
| Markers     | ★ VIP (`is_vip`), ⛔ blacklisted, ↻ repeat guest (the booker has another Completed reservation with a lower id), 👥 group (2 or more units) |
| Subtitle    | Booker (groups only), reference. Channel bookings add `channel_name` and `external_reference`.                                              |
| Menu `[⋯]`  | Open reservation, Open guest. Unit actions come with front desk.                                                                            |
| Stay strip  | Earliest arrival, nights, latest departure of units that hold inventory. When none does (a cancelled reservation), of all units.            |
| Badges      | `StatusBadge`, source icon (label on hover), unit count (groups only), adults and children of units that hold inventory                     |
| Details     | Room types grouped as `N× name`, booked date                                                                                                |
| Money strip | Three columns: Total, Paid and Balance. See [Money strip](#money-strip).                                                                    |
| Card tone   | Cancelled: muted. Blacklisted booker: destructive border and muted background.                                                              |

### Money strip

Three columns in the muted strip, each with a small muted label above the amount. Amounts are left-aligned, `tabular-nums`, with no currency code (one code per amount does not fit). The List view shows the same three figures as columns.

| Figure  | Source                                                                                     | Notes                                                          |
| ------- | ------------------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| Total   | Sum of `unit_nights.amount` of units that hold inventory                                   | Same as before. Sortable (`total`). No tax, no add-ons.        |
| Paid    | `-SUM(folio_lines.amount)` of Payment lines of the folios that are not Voided              | Net of refunds. 0.00 while the reservation has no folio.       |
| Balance | `SUM(folio_lines.amount)` of the folios that are not Voided, as the summary card's Balance | Danger tone above 0. 0.00 with no folio. Sortable (`balance`). |

- Total is kept instead of posted charges: nights post only at the night audit, so posted charges read 0.00 before check-in.
- `Total - Paid` is not always the Balance. Balance counts only posted lines, with tax and add-ons. A negative Balance is a deposit held.
- Paid and Balance come from one more grouped sub-query joined into the list query, so the list runs no extra queries.
- No placeholder dash: a reservation with no folio shows 0.00, and the balance sort reads it as 0.

### Room box

Room or bed numbers when assigned (from the first night of each unit). Every other state shows a Lucide icon with a tooltip and an `aria-label`.

| State                                | Box                     | Icon           | Tone    | Tooltip                     |
| ------------------------------------ | ----------------------- | -------------- | ------- | --------------------------- |
| Room or bed assigned                 | `101`, `D1-2`           | –              | Default | –                           |
| Group, some assigned                 | Up to 2 numbers, `+N`   | –              | Default | Every unit with its room    |
| Room type, not assigned              | Icon                    | `BedDouble`    | Warning | Room not assigned           |
| Bed type, not assigned               | Icon                    | `BedSingle`    | Warning | Bed not assigned            |
| Not assigned, `auto_assign_rooms` on | Icon                    | `WandSparkles` | Muted   | Room assigned automatically |
| Group, none assigned                 | Icon with a count badge | `BedDouble`    | Warning | N units without a room      |
| Cancelled                            | Icon                    | `Ban`          | Muted   | Cancelled                   |
| Every live unit is a no-show         | Icon                    | `UserX`        | Muted   | No-show                     |

`+N` counts only assigned rooms. Unassigned units of a partly assigned group are listed in the tooltip.

### List view

| Column | Shows                                                             | Sort |
| ------ | ----------------------------------------------------------------- | ---- |
| Ref    | `reference`                                                       | Yes  |
| Booker | Title, markers and subtitle as on the card                        | –    |
| Stay   | Earliest arrival → latest departure, nights                       | Yes  |
| Units  | Unit count                                                        | –    |
| Rooms  | The room box rules, inline                                        | –    |
| Source | Label. Channel bookings: `channel_name` and `external_reference`. | –    |
| Total  | As on the card                                                    | Yes  |
| Status | `StatusBadge`                                                     | –    |

Below `lg`, List view shows the cards, as wastays does.

### Empty states

- The property has no reservation at all: "No reservations yet", "Bookings you take appear here.", with **New booking** (hidden until the booking form).
- The default stay window hides everything (no filter set, but the property has reservations): "No upcoming reservations", with **Show all dates** (`filter[stay]=all`).
- Filters with no match: "No reservations match these filters", with **Clear filters**.

### Build status

Built: `reservations.index` with the status tabs, search, source and stay filters, sorts, Cards and List views, the card, the room box and the sidebar item **All reservations**. `ReservationDirectory` (`app/Services/Reservations/`) runs the query and the counts, and `ReservationRoomBox` builds the room box. The URL values are `filter[status]`, `filter[source]`, `filter[search]`, `filter[stay]` (`all`, or `from_to` with either date empty) and `sort`. A card or row opens `reservations.show` (the card title is a stretched link, and the **⋯** menu has **Open reservation** and, with `guests.view`, **Open guest**). **New booking** is hidden until the booking form.

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
- **Add unit** opens a Sheet with one booking line (room type, rate plan, dates, adults, child ages) and calls `AddUnit` (needs `reservations.manage`). Room or bed is not picked here: `AssignRoom` and `AssignBed` come with the booking form, so the new unit shows as not assigned. A collapsed **Price override** section holds the stay total and reason. After a `restricted` refusal, a restriction override reason field appears for staff with `rates.override-restrictions`. Add-ons, the catalogue discount and a live price preview are not in this Sheet. The room types and plans load as an Inertia optional prop when the Sheet opens. The Sheet has no time fields: the new unit copies the arrival and departure times of the reservation's first unit (see [Expected times](01-reservation-shape.md#expected-times)). Hidden on Cancelled and Completed reservations.
- **⋯ More**: only Edit group for now (a Dialog with group name, and billing mode when there are 2 or more units, `UpdateReservationGroup`). Edit group works on closed reservations too. Cancel whole reservation (front desk) and Print confirmation (later) are added when they exist.

### Units tab

- **Choose unit** list on the left: room type, assigned room or bed (`—` unassigned, `auto` when `auto_assign_rooms` is on), status. Hidden when the reservation has one unit. Below `lg` it becomes a select.
- The unit panel: room type and rate plan, status, stay dates with the expected times, and nights, adults and child ages, room or bed (per night when it changes mid-stay), primary guest, and a night table from `unit_nights` (date, room or bed, rate plan, amount). An override shows the reason from the activity log.
- The unit **⋯** menu shows only the actions that exist and that `UnitStatus::canTransitionTo()` allows: Change room, Cancel unit, Mark no-show, Change dates, Check in, Check out. They arrive with [front desk](../18-front-desk/01-check-in-and-check-out.md). Until then the menu is hidden.

### Guests tab

```
👤 Guests                                              [+ Attach guest]
 Guest            Phone             Unit                 Role
 Tan Wei Ming     +60 12-345 6789   Deluxe King · 101    Booker · Primary
 Ahmad Faiz       +60 13-888 2211   Dorm Bed · D1-2      Primary
 —                                  Dorm Bed · —         [Attach guest]
```

Attach guest opens a Sheet with `GuestPicker` (existing guests only, through `guests.search`) and "Stays in". Creating a guest here is not supported; the booking form adds the autocomplete field that can.

`AttachGuest(User $actor, ReservationUnit $unit, Guest $guest): AttachGuestResult` locks the unit, writes a `unit_guests` row (the unit's first guest becomes `is_primary`) and logs `reservation.guest_attached` with ids only. A blacklisted guest is allowed, with a warning in the Sheet.

| Check, in order                                                   | Refusal            |
| ----------------------------------------------------------------- | ------------------ |
| The guest belongs to the property and is not merged or anonymized | `guest_not_found`  |
| The unit is Confirmed or CheckedIn                                | `unit_not_open`    |
| The guest is not already on this unit                             | `already_attached` |
| A Bed room type unit has no guest yet                             | `bed_unit_full`    |

Room units have no guest limit. Detaching a guest and changing the primary guest come later.

Built: `AttachGuest`, `AttachGuestResult` and the Sheet. The Sheet's unit select lists only open units, and a blacklisted guest shows a warning. The header button and the **Attach guest** button on a unit without a guest both open it.

### Add-ons tab

Flat rows grouped by unit: charge item, quantity, service date, unit amount, total. A nightly add-on shows one row per night. **+ Add add-on** opens a Dialog (`AddUnitAddOn`, see [Add-ons](04-add-ons.md)). Each row has **Remove** with an AlertDialog confirm (`RemoveUnitAddOn`).

### Activity tab

The reservation's activity log entries (`spatie/laravel-activitylog`), newest first, paginated by 25: creation, units added, overrides with their reasons, group edits, guests attached, add-ons added and removed. Status changes appear when the [front desk](../18-front-desk/01-check-in-and-check-out.md) Actions log them; `SyncReservationStatus` does not log.

### Summary card

Booker (name, VIP, phone, email, link to the guest), earliest arrival, latest departure, units, room nights, stay total (sum of `unit_nights.amount` of units that hold inventory), balance ("from folio" until the [folio page](../13-folio/05-folio-page.md) lands), billing mode (2 or more units, with the `BillingMode` label), source, company ("—" until [Companies](../15-city-ledger/01-companies.md)), created date and user. Notes, when the booking has them, show under the summary figures (added with the booking form).

### Page build status

Built, view only: the four tab routes with `renderTab()`, the header with the Group badge and the Status by unit popover, the Units tab (Choose unit list or select, `?unit=`, night table, price override reason), the Guests, Add-ons and Activity tabs, and the summary card. The reservation page sends `ReservationData`, a `ReservationUnitOptionData` list and the open tab's own data. `ReservationOverview`, `ReservationGuestRows` and `ReservationActivityLog` (`app/Services/Reservations/`) read the data in a constant number of queries. The unit **⋯** menu is not built and has no button.

Built, folio tab: `reservations.folio` with the folio rail, and the summary card's Balance from the folios (see [Folio page](../13-folio/05-folio-page.md#build-status)).

Built, actions: the five `reservations.manage` routes, each in its own small controller (`ReservationGroupController`, `ReservationUnitController`, `ReservationUnitAddOnController`) with a Form Request that checks the shape only. The Add unit Sheet, Edit group Dialog, Attach guest Sheet, Add add-on Dialog and the Remove add-on AlertDialog call the Actions. The room type and rate plan lists (`addUnitOptions`) and the charge items (`chargeItems`) are optional props that load when their overlay opens, and a submit leaves them out with `except` so a refusal keeps them. `ReservationRefusals` (`app/Services/Reservations/`) is the one map from refusal code to field and message; a refusal with no field uses the `form` key, shown as an alert at the top of the overlay. A `restricted` refusal goes to the restriction override reason field for staff with `rates.override-restrictions`, and to `form` for everyone else. `guests.search` is `GuestController::search`, backed by `GuestDirectory::lookup` (8 guests, 2 characters at least, no merged or anonymized guests). The overlays follow the guests pattern (`Dialog` or `Sheet` with `Field` and `useForm`); `FormOverlay` is not built. Child ages in the Add unit Sheet are one comma-separated field.

## Refusals and messages

- Forms in a Sheet or Dialog (Add unit, Attach guest, Add add-on, Edit group) show a refusal as an inline error on the matching field, or a form-level error, and stay open (see [form containers](../design-system.md)). Success closes the overlay with a toast.
- Buttons without a form (Remove add-on) show a refusal as an error toast, as `.ai/rules/actions.md` says.
- One map turns refusal codes into messages. The booking form reuses it.

## Shared components

New in `components/pms/`: `MoneyText` and `MoneyInput` (decimal strings, the property currency as one page prop), `GuestPicker`, and a pagination footer extracted from `DataTable` for the Cards view. `DateRangePicker` gains an optional end date.

## Build order

One checklist item per commit, in this order:

1. Permissions and `AttachGuest` (backend), with the two `AddUnit` fixes in [Group bookings](02-group-bookings.md#addunit).
2. The reservations list.
3. The reservation page, view only.
4. The page actions: Add unit, Edit group, Attach guest with `guests.search` and `GuestPicker`, add and remove add-ons.

## Data

- `Reservations/ReservationListItemData` (one per card or row, including the room box state and the status counts in a separate `ReservationStatusCountData`), `Reservations/ReservationData` (header and summary), `Reservations/ReservationUnitData` (unit panel with nights), `Reservations/ReservationGuestData`. Totals and dates are calculated on the server.
- The page gets a query-count test with lazy loading prevented (see `.ai/rules/tests.md`).

## Comes later

| Part                                            | Module                                                                    |
| ----------------------------------------------- | ------------------------------------------------------------------------- |
| Folio tab (with a folio rail), real balance     | [Folio page](../13-folio/05-folio-page.md)                                |
| Payments and deposits                           | [Payments and refunds](../14-payments/01-payments-and-refunds.md)         |
| Unit actions, Arrivals, In-house and Departures | [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md)   |
| Room & Rate editing, room moves                 | [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md) |
| Registration card in a Documents tab            | [Registration](../16-registration/04-signing-flow.md)                     |

## Lessons from wastays

- The booking workspace had 9 tabs and a 2,300-line `WorkspacePresenter`. estatell starts with 4 tabs, and each later module adds its own tab with its own controller method and Data class.
- The wastays front desk had 5 tabs, each with its own table and card partial. estatell has one card and one set of columns; the tabs only filter by status.
- wastays showed a left rail only on some tabs. estatell shows a left rail on two tabs, only for 2 or more entries: the unit list on the Units tab and the folio list on the Folio tab ([Folio page](../13-folio/05-folio-page.md#folio-rail)).
