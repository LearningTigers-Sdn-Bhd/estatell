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
| `PUT …/{reservation}/company`                          | `reservations.company.update`         | – (`UpdateReservationCompany`; also needs `company.view`) |
| `POST …/{reservation}/units`                           | `reservations.units.store`            | – (`AddUnit`)                                             |
| `POST …/{reservation}/units/{unit}/guests`             | `reservations.units.guests.store`     | – (`AddGuestToUnit`, then `AttachGuest`)                  |
| `PUT …/{reservation}/units/{unit}/guests/{guestId}`    | `reservations.units.guests.update`    | `guests.manage` (`UpdateGuest`)                           |
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

The booker's phone, email and the link on the booker's name need `guests.view`. Without it, staff see the booker's name and flags only.

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
│ │             │ + MYR 1,400 │             │ │  billed to company, only above 0
│ │             │ billed to   │             │ │
│ │             │ company     │             │ │
│ └─────────────┴─────────────┴─────────────┘ │
└─────────────────────────────────────────────┘
```

| Part        | Shows                                                                                                                                                                                            |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Room box    | See [Room box](#room-box)                                                                                                                                                                        |
| Title       | `group_name`, or the booker's name. For a channel booking without a group name: the booker.                                                                                                      |
| Markers     | ★ VIP (`is_vip`), ⛔ blacklisted, ↻ repeat guest (the booker has another Completed reservation with a lower id), 👥 group (2 or more units)                                                      |
| Subtitle    | Booker (groups only), reference. Channel bookings add `channel_name` and `external_reference`.                                                                                                   |
| Menu `[⋯]`  | Open reservation, Open guest. Unit actions come with front desk.                                                                                                                                 |
| Stay strip  | Earliest arrival, nights, latest departure of units that hold inventory. When none does (a cancelled reservation), of all units. A day use reads "Day use · 4h" (`stayLabel`), never "0 nights". |
| Badges      | `StatusBadge`, source icon (label on hover), unit count (groups only), adults and children of units that hold inventory                                                                          |
| Details     | Room types grouped as `N× name`, booked date                                                                                                                                                     |
| Money strip | Three columns: Total, Paid and Balance. See [Money strip](#money-strip).                                                                                                                         |
| Card tone   | Cancelled: muted. Blacklisted booker: destructive border and muted background.                                                                                                                   |

### Money strip

Three columns in the muted strip, each with a small muted label above the amount: **Room total**, **Paid** and **Balance**. Room total is room nights only, so the label says so, with an info tooltip "Room nights only, before tax and extras." The full total (every charge, with tax) is on the reservation page's [summary card](#summary-card) and in the [booking summary](08-booking-summary.md#rules). Amounts are left-aligned, `tabular-nums`, with no currency code (one code per amount does not fit). The List view shows the same three figures as columns.

| Figure            | Source                                                                                                                                                        | Notes                                                                                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Room total        | Sum of `unit_nights.amount` and `unit_day_uses.amount` of units that hold inventory                                                                           | Labelled "Room total" with the tooltip. Sortable (`total`). No tax, no add-ons.                                                                                |
| Paid              | `-SUM(folio_lines.amount)` of Payment lines of the folios that are not Voided, except DIRECT_BILL lines (transaction code `system_key` `direct_bill_payment`) | Net of refunds. 0.00 while the reservation has no folio. Real money only                                                                                       |
| Billed to company | `-SUM(folio_lines.amount)` of the DIRECT_BILL lines (Settle to City ledger)                                                                                   | A muted sub-line under Paid, "+ MYR 1,700.00 billed to company", only above 0. A reversed DIRECT_BILL line nets out. `ReservationListItemData.billedToCompany` |
| Balance           | `SUM(folio_lines.amount)` of the folios that are not Voided: the posted balance, as on the folio tab                                                          | Danger tone above 0. Below 0, a **Deposit held** badge (`BadgeData`, info tone) under the amount. 0.00 with no folio. Sortable (`balance`).                    |

- Room total is kept instead of the full total on the list: nights post only at the night audit, and the full total needs the Scheduled rows with their tax worked out live, which one list query cannot do or sort by. The label and tooltip say what it counts, so a correct payment never looks like an overpayment (the wastays "false credit" lesson).
- `Room total - Paid` is not the Balance. Balance counts only posted lines, with tax and add-ons. A negative Balance is money held before the charges post, and shows the **Deposit held** badge.
- Paid, Billed to company and Balance come from one more grouped sub-query joined into the list query, so the list runs no extra queries.
- Before item 21, a settled company folio read as fully Paid while the company had paid nothing. DIRECT_BILL is found by its system code, not by "a Payment line with no `payments` row", because older and factory lines have no `payments` row.
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

A fixed-height workspace (see [Detail workspace](../design-system/04-page-archetypes.md)), not `DetailLayout`. The page fills the shell height and never scrolls as a whole. Row 1 is the header, row 2 the link tabs, row 3 up to three panels. Each panel is a rounded, bordered card with small padding and its own scroll.

| Tab               | Panel 1 (14rem)               | Panel 2 (rest)                | Panel 3 (20rem) |
| ----------------- | ----------------------------- | ----------------------------- | --------------- |
| Units             | Choose unit list              | Open unit and night table     | Summary card    |
| Folio             | Folio rail                    | Folio table and actions       | Summary card    |
| Guests            | Unit rail (Grouped, 2+ units) | Guest table                   | Summary card    |
| Add-ons, Activity | – (two panels instead)        | Tab content, full panel width | Summary card    |

The Units list is hidden for one unit, and the Folio rail for one folio, so those tabs also show two panels. On the Folio and Guests tabs an icon button hides the summary card, and the panel takes its width (see [Folio page](../13-folio/05-folio-page.md#layout) and [Guests tab](#guests-tab)). The Units, Add-ons and Activity tabs always show the summary. Below `lg` the panels stack in one scrolling column, with the summary card first, and the unit list becomes a select.

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ ← Reservations                                                                           │
│ WA1-0142 · Tan family trip  [Group]  ●Confirmed ▾                [Add unit] [⋯ More ▾]   │
│ 12 Oct → 15 Oct 2026 · 5 units · Phone · Ext. ref —                                      │
├────────────────────────────────────────────────────────────┬─────────────────────────────┤
│ [🛏 Units] [👤 Guests] [🧾 Add-ons] [🕘 Activity]          │ Booker                      │
│ ─────────                                                  │ Tan Wei Ming  VIP           │
│ ┌ Choose unit ───────┐┌──────────────────────────────────┐ │ +60 12-345 6789             │
│ │ ▸ Deluxe King      ││ Deluxe King · BAR Room Only      │ │                             │
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
- **Add unit** opens a Sheet with one booking line (room type, rate plan, dates, adults, child ages) and calls `AddUnit` (needs `reservations.manage`). Room or bed is not picked here: `AssignRoom` and `AssignBed` come with the booking form, so the new unit shows as not assigned. A collapsed **Price override** section holds the stay total and reason. After a `restricted` refusal, a restriction override reason field appears for staff with `rates.override-restrictions`. Add-ons, the catalogue discount and a live price preview are not in this Sheet. The server adds the [auto-add items](04-add-ons.md#added-automatically) to the new unit and the toast names them. The room types and plans load as an Inertia optional prop when the Sheet opens. The Sheet has no time fields: the new unit copies the arrival and departure times of the reservation's first unit (see [Expected times](01-reservation-shape.md#expected-times)). Hidden on Cancelled and Completed reservations.
- **⋯ More**: Edit group (a Dialog with group name, and billing mode when there are 2 or more units, `UpdateReservationGroup`) and **Change company** (a Dialog with the company combobox, `UpdateReservationCompany`; needs `company.view`, hidden on closed reservations). A `master_has_payments`, `master_has_ar_invoice`, `company_plan_conflict` or `company_not_available` refusal shows inline on the combobox. The Active company list loads as an Inertia optional prop when the Dialog opens. Edit group works on closed reservations too. **Print booking summary** opens the [booking summary](08-booking-summary.md) PDF in a new tab, and **Print without prices** is added when the reservation has a company (needs `reservations.view`, shown on closed reservations too). **Cancel reservation** opens the [Cancel reservation](../18-front-desk/01-check-in-and-check-out.md#cancel-reservation) Dialog, with `reservations.manage`, when at least one unit is Confirmed. **Check out all** goes to the [group check-out](../18-front-desk/05-group-check-out.md) page, with `front-desk.check-out`, for 2 or more units with one CheckedIn or one holding money. **Print receipts** opens one PDF with every receipt of the reservation (all payments that are not refunds, through `reservations.check-out.receipts`) in a new tab, with `payment.record`, when the reservation has a payment; shown on closed reservations too, so receipts stay printable after a group check-out.
- **Each unit pays with a company:** when a reservation has a company and `billing_mode` EachPays, nothing is billed to the company (the Master folio exists but no rule routes to it). The Edit group Dialog shows a hint under the billing mode: "Guests pay everything. Nothing is billed to Acme Sdn Bhd. Choose Master folio pays to bill the company." The Change company Dialog replaces its description "The company is billed through the master folio." with "This reservation is set to Each unit pays. Guests pay everything. Nothing is billed to Acme Sdn Bhd. Change it in Edit group." The hint is derived in the browser from the chosen company and mode (`eachPaysHint`, display only).

### Units tab

The page shows no dash for an empty value: a missing room is `Not assigned` (an `Unassigned` badge in the night table), no primary guest says so, a missing balance says `No folio` or `Not started`, and the company says `None`.

- **Choose unit** list on the left: room type, assigned room or bed (`Not assigned`, or `Auto` when `auto_assign_rooms` is on), status. Hidden when the reservation has one unit. Below `lg` it becomes a select.
- The unit panel: room type and rate plan, status, then three detail rows, each label with an icon before it: Stay (dates with the expected times, and nights); Room and Guests side by side (room or bed, per night when it changes mid-stay; adults and child ages); Primary guest. Below them a rounded, bordered night table from `unit_nights` (date, room or bed, rate plan, amount). An override shows the reason from the activity log. With `reservations.change-price` the table has a checkbox per night and **Change price** for a Confirmed, CheckedIn or CheckedOut unit (see [Change a night's price](09-change-night-price.md)).
- The unit **⋯** menu shows only the actions that exist and that `UnitStatus::canTransitionTo()` allows: Change room, Cancel unit, Mark no-show, Reinstate, Change dates, Check in, Check out. They arrive with [front desk](../18-front-desk/01-check-in-and-check-out.md). The server sends the list as `unit.actions`, and the menu is hidden when it is empty. Built so far: Check in, Check out, Late check-out, Cancel unit, Mark no-show, Reinstate and Change dates.

### Guests tab

**Status: built.** The table, rows and footer came first; the All / Grouped toggle, the unit rail and the summary icon came after.

The tab is one table of guests, edited in place. It reuses the booking form's Guests table: collapsible rows, `GuestAutocompleteField`, `DuplicateWarning` and `GuestDetailsFields`. The panel has no title row. Like the folio panel, it opens with one thin toolbar and then the table.

```
┌─────────────────────────────────────────────────────────┬──────────────┐
│ [ All | Grouped ]                                  [⇥]  │ Summary card │
│ ┌─────────────────────────────────────────────────────┐ │              │
│ │    GUEST          PHONE        STAYS IN     ROLE    │ │              │
│ ├─────────────────────────────────────────────────────┤ │              │
│ │ ›  Chloe Turner   +6013520…    Std Dbl 101  Booker  │ │              │
│ │                                            Primary⋯ │ │              │
│ │ ›  Nattaya S.     +6013520…    Std Dbl 103  Primary⋯│ │              │
│ │    Unit folio billed to Chloe Turner                │ │              │
│ │ ›  Arif Danial    +6013520…    Std Dbl 103  Guest  ⋯│ │              │
│ │    Std Dbl 104    No guest yet               [ Add ]│ │              │
│ ├─────────────────────────────────────────────────────┤ │              │
│ │ 1 unit without a guest             [ + Add guest ]  │ │              │
│ └─────────────────────────────────────────────────────┘ │              │
└─────────────────────────────────────────────────────────┴──────────────┘
```

| Part           | Rule                                                                                                                                                                                                                                                                                                       |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Toolbar        | Left: the **All / Grouped** toggle. Right: the summary icon button with a tooltip ("Hide summary" or "Show summary"). With one unit the toggle is hidden and only the icon shows.                                                                                                                          |
| Summary icon   | The same state as on the Folio tab (`useSummaryHidden`, one remembered choice for both tabs, key `reservations.summary-hidden`). It applies on the Folio and Guests tabs. The other tabs always show the summary.                                                                                          |
| All            | One flat table in unit order. Columns: chevron, Guest, Phone, Stays in, Role and the **⋯** menu. A booker who does not stay is the first row, with "Not staying" in Stays in.                                                                                                                              |
| Grouped        | A left rail (14rem) lists the units like the Units tab: unit label, status when not open, guest count. "Not staying" is the first entry when the booker does not stay. The table shows the picked unit only, with no Stays in column. The rail shows for 2 or more units, and becomes a select below `lg`. |
| View choice    | All is the default. The choice is kept in the browser (`reservations.guests-view`). Switching makes no request: the server sends all rows and the browser groups them.                                                                                                                                     |
| Row            | Main row: Name (autocomplete), Phone (autocomplete), Stays in (select, read only on a saved row), Role badges (Booker, Primary, Guest) and **⋯**. The expanded part has Email, the guest card, **Update guest profile**, `DuplicateWarning` and the guest details.                                         |
| Unit, no guest | In All view a muted row "No guest yet" with a small **Add** button. It adds a draft row with that unit picked. In Grouped view the draft row uses the open unit.                                                                                                                                           |
| Footer         | Left: "N units without a guest" (All) or "N guests in this unit" (Grouped). Right: **+ Add guest**, outline, like the booking form. It adds a draft row at the end and focuses its Name.                                                                                                                   |
| Draft row      | Opens expanded. Buttons: **Discard** and **Save**. Leaving the page with a draft row asks first (`useUnsavedChanges`).                                                                                                                                                                                     |
| Saved row      | **Save** and **Cancel** appear only after an edit in the expanded part. Name, Phone, Email and the details are the CRM profile of the guest, so Save always updates it and there is no **Update guest profile** switch. The unit link does not change.                                                     |

### Save rules

There is no per-stay snapshot. `unit_guests` is a link to a CRM guest (`reservation_unit_id`, `guest_id`, `is_primary`), and a row is either a link to an existing guest or a new guest.

| Row                             | Save does                                                                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Draft, an existing guest picked | `AttachGuest` for the picked guest. With **Update guest profile** on, `UpdateGuest` runs first with the typed changes.                |
| Draft, no guest picked          | `CreateGuest` with the typed profile, then `AttachGuest`. The `DuplicateWarning` (Use existing, Create new anyway) shows before save. |
| Saved row, profile edited       | `UpdateGuest` only, through `reservations.units.guests.update`. The unit link and the guest's tags do not change.                     |

- One route saves a draft row: `reservations.units.guests.store` (`ReservationUnitController::storeGuest`). The Action `AddGuestToUnit` runs `CreateGuest` or `UpdateGuest` and `AttachGuest` in one transaction and rolls back on a refusal, like `CreateBooking`, and keeps the tags of an updated guest. A saved row's profile edit uses `reservations.units.guests.update` (`guests.manage`), not the guest page route, which redirects to the guest page and syncs tags. The `{guestId}` is read through the unit's guests, so a guest who is not on the unit is a 404.
- Refusals are the `AttachGuest` codes in the table below. They show inline on the row: `already_attached` and `bed_unit_full` on Stays in, `guest_not_found` on Name, `unit_not_open` as a row error.
- Permissions: `reservations.manage` to attach. `guests.manage` to create a guest or update a profile. Without `guests.manage`, a draft row accepts an existing guest only, and **Update guest profile** is hidden (same rule as the booking form).
- Stays in lists Confirmed, CheckedIn and CheckedOut units. A CheckedOut unit ends with "Checked out" and shows the muted note below. A Bed unit that has a guest is disabled.
- A blacklisted guest is allowed, with the reason as a warning on the row.
- The tax warning toast for a new primary on a CheckedOut unit stays (see [Adding a guest after check-out](#adding-a-guest-after-check-out)).

### Out of scope for the first build

Moving a guest to another unit, removing a guest from a unit and changing the primary guest have no Action yet. Stays in is read only on a saved row. They come later with `DetachGuest` and the primary change.

### Attach rules

`AttachGuest(User $actor, ReservationUnit $unit, Guest $guest): AttachGuestResult` locks the unit, writes a `unit_guests` row (the unit's first guest becomes `is_primary`) and logs `reservation.guest_attached` with ids only.

| Check, in order                                                   | Refusal            |
| ----------------------------------------------------------------- | ------------------ |
| The guest belongs to the property and is not merged or anonymized | `guest_not_found`  |
| The unit is Confirmed, CheckedIn or CheckedOut                    | `unit_not_open`    |
| The guest is not already on this unit                             | `already_attached` |
| A Bed room type unit has no guest yet                             | `bed_unit_full`    |

Room units have no guest limit.

**Bill to this guest** is an item in a guest row's **⋯** menu, for users with `folio.transfer`, on a Confirmed, CheckedIn or CheckedOut unit. It runs `BillToGuest` (see [Routing](../13-folio/03-routing.md#bill-to-guest)). A small grey line under the name shows the result: "Pays unit folio", "Own folio", or on the primary guest "Unit folio billed to {name}" when the payer is someone else. The item is hidden for a guest who already pays. The action has no dialog; a toast names the folio. The **Registration card** item stays in the same menu.

#### Previous build (replaced)

A title row with the **Attach guest** button sits above a rounded, bordered table, grouped by unit with one shaded group row per unit ("Not staying" for a booker who does not stay), with the columns Guest, Phone and Role. Attach guest opens a Dialog (2 fields) with `GuestPicker` (existing guests only, through `guests.search`) and "Stays in". Creating a guest here is not supported. A unit with no guest shows an **Attach guest** button in its row.

### Adding a guest after check-out

The desk often learns who else stayed only at or after departure. A CheckedOut unit takes guests, so the police report, the non-national guests report, meal counts and the guest's stay history are complete. Cancelled and NoShow units stay refused (`unit_not_open`): nobody stayed.

| Decision          | Rule                                                                                                                                                                                                                                                                         |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Charges           | Adding a guest never changes the folio, prices, taxes or the pax count, also on a CheckedOut unit. Nothing is posted or reversed.                                                                                                                                            |
| Primary           | The usual rule: the unit's first guest becomes primary. A CheckedOut unit has no nights left to post, so nothing is charged on the new nationality.                                                                                                                          |
| Tax warning       | When the guest becomes primary on a CheckedOut unit, the property has an enabled foreign-only tax, and the guest and the booker differ in being foreign (nationality other than `MY`), the save succeeds with a warning toast (below). Either nationality empty: no warning. |
| Time limit        | None. The activity log shows who added the guest and when.                                                                                                                                                                                                                   |
| Registration card | None for a guest added after check-out. Signing a card is a check-in step.                                                                                                                                                                                                   |

- **Why only a new primary warns.** Foreign-only taxes follow the unit's primary guest, or the booker when there is none (`UnitNationality`). A guest who is not primary never changed a tax, so adding one never warns.
- **Warning toast:** "Guest added. Tourism tax was charged on the booker's nationality (Malaysia). If it is different for this guest, correct it on the folio." It names the foreign-only taxes of the property and the booker's country. `AttachGuestResult` carries the notice; the controller flashes it as a warning toast, like `RoutingChangeNotice`.
- **Dialog.** "Stays in" lists CheckedOut units too, with "Checked out" after the room. With one picked, a muted note under the field says "This stay has ended. Adding a guest changes no charges."
- **Reports change for past dates.** The police and non-national guests reports read the unit's guests, so a reprint after the guest is added lists them. A copy printed earlier does not match. This is the correction staff want.

Built: `AttachGuest`, `AttachGuestResult` and the form, a Dialog on `FormOverlay` (2 fields). Its unit select lists Confirmed, CheckedIn and CheckedOut units (a CheckedOut one ends with "Checked out" and shows the muted note), and a blacklisted guest shows a warning. The tax warning is built in `AttachGuest`, and `ReservationUnitController::storeGuest` flashes it as a warning toast.

Built (guest table): `AddGuestToUnit` (`CreateGuest` or `UpdateGuest`, then `AttachGuest`, one transaction), `AttachGuestRequest` (the profile fields, `update_profile`, and the `guests.manage` checks on the keys `full_name` and `update_profile`), `UpdateUnitGuestRequest` and `reservations.units.guests.update`. `ReservationGuestData.guest` carries the guest with its profile (null without `guests.view`). The Guests tab sends `canManageGuests`, `countries`, `genders` and `locales`. The page is `GuestsTab` with `SavedGuestRow` and `DraftGuestRow` (`guest-table-row.tsx`) and the pure helpers in `guest-rows.ts`; `GuestAutocompleteField` gained `lockLink`, which hides Change guest on a saved row. Leaving the page with a draft or an edited row asks first. The Attach guest Dialog (`attach-guest-dialog.tsx`) is removed.

Built (grouped view): `useGuestsView` (`reservations.guests-view`), `GuestsRail` (a rail from `lg`, a select below it) with `guestGroups()` in `guest-rows.ts`, and the toolbar of `GuestsTab`: the All / Grouped `ToggleGroup` (hidden with one unit) and the summary icon button, which shares `useSummaryHidden` with the Folio tab (`reservations.summary-hidden`; the old `reservations.folio-summary-hidden` is read once as a fallback). The page holds the view and the picked unit and passes the rail as the `list` panel. In Grouped view the rows drop Stays in, a draft row keeps only the open unit, and the footer count reads "N guests in this unit"; Add guest is hidden under Not staying and on a unit that is not open.

### Add-ons tab

A title row (Add-ons, with the **+ Add add-on** button on the right) above one rounded, bordered table. Rows are grouped by unit, with a shaded group row per unit: charge item, quantity, service date, unit amount, total. With no add-ons the table still shows its header, with the empty state in one body row and no footer. A footer row shows the Total of all add-ons (a display sum of the row totals, in cents). A nightly add-on shows one row per night. **+ Add add-on** opens a Dialog (`AddUnitAddOn`, see [Add-ons](04-add-ons.md)). Each row has **Remove** with an AlertDialog confirm (`RemoveUnitAddOn`), except a posted row, which has no Remove: correct it with Reverse on the folio.

A **Billing** column shows where each add-on is billed, as icons with tooltips (`AddOnBilling` service, read only, fixed number of queries):

| State                                   | Icon                                      | Tooltip                                                                           |
| --------------------------------------- | ----------------------------------------- | --------------------------------------------------------------------------------- |
| Posted (`folio_line_id` set)            | Tick, and a link to the folio (`?folio=`) | "Posted to {folio} on {date}"                                                     |
| Not posted                              | Clock                                     | "Posts in the night audit of {date}", or "Posts at check-in" with no date         |
| Not posted, routed away from unit folio | Clock, then an arrow                      | "Posts to {folio}" (a routing rule sends it to the Master or a third-party folio) |

An add-on on a Cancelled, NoShow or CheckedOut unit never posts, so it shows no routing arrow.

### Activity tab

A title row (Activity), then a timeline grouped by day (`ActivityTab`): a muted day header, and under it one entry per row with a dot on a thin line, the text, the time on the right, and who did it under the text. An override reason shows as a muted line under the text. Grouping is display only, on the loaded page. Each entry has an icon in a small circle on the line, chosen from the entry's `kind` (the log `event`, for example `reservation.unit_added`) by a fixed map in the page: created, unit, room or bed, guest, add-on, group, payment, folio. An unknown or missing `kind` gets a neutral dot. The reservation's activity log entries (`spatie/laravel-activitylog`), newest first, paginated by 25: creation, units added, overrides with their reasons, group edits, guests attached, add-ons added and removed. Status changes appear when the [front desk](../18-front-desk/01-check-in-and-check-out.md) Actions log them; `SyncReservationStatus` does not log.

### Summary card

Booker (name, VIP, phone, email; the name is an underlined link to the guest that opens in a new tab), earliest arrival, latest departure, units, room nights, the money figures (below), billing mode (2 or more units, with the `BillingMode` label), source, company (the name, a link to the [company page](../15-city-ledger/01-companies.md#pages) for staff with `company.view`, or "None"), Created at (the date) and Created by (the user, or "System" when there is none) as two rows. Every row has an icon before its label, and only the top row (the Booker block) has `ps-3 pt-2`, like the title rows of the tab panels. Notes, when the booking has them, show in a rounded, bordered box of fixed height (`h-32`, the text scrolls inside) under the summary figures (added with the booking form).

**Money figures.** The summary card shows the full figures of the reservation, from `BookingSummary` (the same service and numbers as the [booking summary](08-booking-summary.md) PDF):

| Figure            | Value                                                                                     |
| ----------------- | ----------------------------------------------------------------------------------------- |
| Total             | Every charge, posted and Scheduled, with tax and discounts, on folios that are not Voided |
| Paid              | Payments net of refunds                                                                   |
| Billed to company | City ledger amounts, only above 0                                                         |
| Still to pay      | Total − Paid − Billed to company. Below 0 it reads "Credit"                               |

Room nights stay in the card as a count. The folio tab keeps each folio's own Balance (posted) and Projected total.

### Page build status

Built, view only: the four tab routes with `renderTab()`, the header with the Group badge and the Status by unit popover, the Units tab (Choose unit list or select, `?unit=`, night table, price override reason), the Guests, Add-ons and Activity tabs, and the summary card. The reservation page sends `ReservationData`, a `ReservationUnitOptionData` list and the open tab's own data. `ReservationOverview`, `ReservationGuestRows` and `ReservationActivityLog` (`app/Services/Reservations/`) read the data in a constant number of queries. The unit **⋯** menu holds Check in, Check out, Late check-out, Change dates, Cancel unit, Mark no-show and Reinstate (see [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md#built-cancel-no-show-reinstate-and-change-dates)). Change room comes with room assignment.

Built, folio tab: `reservations.folio` with the folio rail, and the summary card's Balance from the folios (see [Folio page](../13-folio/05-folio-page.md#build-status)).

Built, actions: the five `reservations.manage` routes, each in its own small controller (`ReservationGroupController`, `ReservationUnitController`, `ReservationUnitAddOnController`) with a Form Request that checks the shape only. The Add unit Sheet, Edit group Dialog, Attach guest Dialog, Add add-on Dialog and the Remove add-on AlertDialog call the Actions. The room type and rate plan lists (`addUnitOptions`) and the charge items (`chargeItems`) are optional props that load when their overlay opens, and a submit leaves them out with `except` so a refusal keeps them. `ReservationRefusals` (`app/Services/Reservations/`) is the one map from refusal code to field and message; a refusal with no field uses the `form` key, shown as an alert at the top of the overlay. A `restricted` refusal goes to the restriction override reason field for staff with `rates.override-restrictions`, and to `form` for everyone else. `guests.search` is `GuestController::search`, backed by `GuestDirectory::lookup` (8 guests, 2 characters at least, no merged or anonymized guests). The five overlays (Add unit, Attach guest, Add add-on, Edit group, Change company) use `FormOverlay`. Each keeps its own `useForm` in a component keyed by `useFreshOnOpen`, so every opening starts with a clean form; `FormOverlay` owns the shell, the discard confirm and the mobile layout. A dirty Sheet (Add unit) asks before it closes. Child ages in the Add unit Sheet are one comma-separated field.

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
