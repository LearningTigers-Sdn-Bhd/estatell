# Booking form

## Purpose

Create a booking in as few fields as possible, with the same flow front desk staff know from wastays.

The [company portal booking form](../23-company-portal/01-company-portal.md#booking) uses the same layout with fewer fields. It reuses the lines table header and columns, and `BookingQuoteCell` (no override). Its line rows, guest rows and summary rail are its own components.

## Container and layout

A full page (Form archetype): it has repeating lines and many fields. Two columns from `xl`: the form on the left, a sticky summary rail on the right. Below `xl` the rail moves under the form. The page uses the `fixed` layout variant (see [Page archetypes](../design-system/04-page-archetypes.md)): it is as tall as the window, the breadcrumb header stays on top, the title, Stay, tabs and rail scroll together in one region, and `FormActions` is below that region, always visible. The title row scrolls with the content.

```
┌────────────────────────────────────────────────────────────┬─────────────────────────┐
│ STAY                                                       │ SUMMARY        (sticky) │
│ Arrival [12 Oct│03:00 PM] 3 nights Departure [15 Oct│12:00 PM] │ Units       5           │
│ Source [Phone ▾]  Ext. ref [____]  Group name [Tan family] │ Rooms     RM 2,310.00   │
│                                                            │ Add-ons   RM    80.00   │
│ Rooms & guests   Discounts   Add-ons (2)   Notes           │ Tax       from folio    │
│ ───────────────                                            │ Total     RM 2,390.00   │
│ ROOMS AND BEDS                                             │                         │
│ Room type │ Rate plan │ Room / Bed │ Ad │ Ch │ Rate     │   │ Billing (2+ units)      │
│ Deluxe ▾  │ BAR RO ▾  │ Select later ▾│ 2 │ 2 │ 840.00 ⓘ ✎ 🗑│ │ (•) Master pays rooms   │
│   Children's ages: [4] [9]                                 │ ( ) Each pays           │
│ Dorm ▾    │ Backpacker▾│ Beds [3]            │ 270.00 ⓘ ✎ 🗑│ │                         │
│   Bed 1 [D1-2 ▾]  Bed 2 [Select later ▾]  Bed 3 [ … ▾]     │ Guests                  │
│                                           [+ Add line]     │  Tan Wei Ming  booker   │
│ GUESTS                                                     │  Ahmad Faiz    Bed 1    │
│ Guest · Phone · Stays in (one collapsible row each)        │  2 units without guest  │
│ 2 units without a guest                   [+ Add guest]    │                         │
├────────────────────────────────────────────────────────────┴─────────────────────────┤
│                                                          [Cancel]  [Create booking]  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

Stay is always visible above the tabs. The tab strip uses the `line` variant of `Tabs`, with an icon in each tab (bed, percent, package, notebook). **Rooms & guests** holds Rooms and beds and Guests, stacked. **Discounts**, **Add-ons** and **Notes** are the other tabs.

## Entry points

The page header has a description in both modes: "Reserve rooms or beds for a guest who arrives on a later date." for a new booking, and "A guest at the desk now. Every unit needs a room or bed." for a walk-in. The Reservations page has `New ▾` with **New booking** and **Walk-in**. Walk-in opens the same page with the Walk-in [booking source](11-booking-sources.md) and today's business date as arrival. The **Reservation mode** field on the form switches between the two pages without losing what was typed (see [Stay](#sections)). The walk-in check-in itself comes with [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md).

| URL                                         | Name                          | Needs                                    |
| ------------------------------------------- | ----------------------------- | ---------------------------------------- |
| `GET /p/{code}/reservations/new`            | `reservations.new`            | `reservations.manage`                    |
| `GET /p/{code}/reservations/walk-in`        | `reservations.walk-in`        | `reservations.manage`                    |
| `GET /p/{code}/reservations/past`           | `reservations.past`           | `reservations.manage` and `folio.manual` |
| `POST /p/{code}/reservations/past`          | `reservations.store-past`     | `reservations.manage` and `folio.manual` |
| `POST /p/{code}/reservations`               | `reservations.store`          | `reservations.manage`                    |
| `POST /p/{code}/reservations/quote`         | `reservations.quote`          | `reservations.manage`                    |
| `GET /p/{code}/reservations/free-units`     | `reservations.free-units`     | `reservations.manage`                    |
| `GET /p/{code}/reservations/day-use-offers` | `reservations.day-use-offers` | `reservations.manage`                    |

`quote` and `free-units` also answer a day use line (`stay_type=day_use`, `start_time`, `hours`), and `day-use-offers` lists the hours a day use plan sells (see [Day use](10-day-use.md#booking-form)). `new` and `walk-in` render the same page (`pages/reservations/create.tsx`). All five sit before `{reservation}` in `routes/reservations.php`, so `new` is never read as a reservation id.

## Day use

When the property has an active Day use plan, the Stay section starts with a **Stay type** switch. Day use replaces Arrival and Departure with **Date and start** and the lines table with day use lines (see [Day use](10-day-use.md#booking-form)). The booking posts `stay_type`, `arrival_date`, `start_time`, and for each line `hours` and `room_id`. A refusal of the start comes back as `start_time` or `lines.N.start_time` and shows on the Date and start field. Hours and Room errors show on their line cells.

## Sections

1. **Stay**: arrival, departure, nights (shown), source, external reference, group name. Arrival and departure apply to every line.
    - Layout: the labels are Arrival and Departure. Each has one `InputGroup` with the date (`DatePicker`) and a native `type="time"` input. A nights badge sits between the two groups and takes no input. Source, External reference and Group name are on the row below. Below `lg` the badge sits between the groups, which stack in order.
    - Times: `arrival_time` and `departure_time` default to the property's `check_in_time` and `check_out_time` and apply to every line. They are the expected times and never change nights or prices (see [Expected times](01-reservation-shape.md#expected-times)). The native input shows 12-hour or 24-hour by the staff PC's locale, and the server always gets `HH:mm`. A stay is at least one night, so the times are never compared.
    - Arrival starts as today's business date on both pages. Staff can pick a later date on a new booking.
    - Walk-in: the arrival date is today and is fixed. The arrival time defaults to now, rounded to the minute, and staff can edit it.
    - Package plans lock the departure date, not the time.
    - Reservation mode: a select after Departure, on the same row from `lg` (about 12rem wide; below `lg` it wraps to the next line). Options: **New booking** and **Walk-in**, plus **Past stay** and **Migrated stay** for users with `folio.manual` (see [Past and migrated stays](12-past-and-migrated-stays.md#booking-form-modes)). Picking the other mode visits `reservations.walk-in` or `reservations.new` as a partial Inertia visit with `preserveState`, so the same page stays mounted and the dates, lines and guests are kept. The URL and the breadcrumb change, and no unsaved-changes prompt appears. To Walk-in: the source becomes Walk-in and is locked, and the arrival becomes today with the current time and is locked. To New booking: the source goes back to the first offered source and the arrival unlocks. A departure that is then not after the arrival gets the normal error on save. Both pages need `reservations.manage`, and the backend does not change.
    - Grid: Source, External reference, Company and Group name sit in 3 columns from `lg`. When Company and Group name both show (4 fields), the row stays at 2 columns.
    - Group name: the third field of the row after External reference, shown when the form has 2 or more units. Leave it empty to show the booker's name. `billing_mode` stays in the rail.
    - Company: a combobox before Group name, shown only to staff with `company.view` and when the property has an Active company. It lists the property's Active companies, with the market after the name when set (for example "Acme (Local)") and "No company" as the empty choice, and fills `company_id`. Plans limited to companies ([Who can use this rate](../08-rates/01-rate-plans.md#who-can-use-this-rate)) show on a line by their access: All companies plans with Front desk on once any company is picked, Only these companies plans when the picked company is listed with Front desk on, Everyone plans always. A plan limited to Local or International also needs the company's market to match ([Market](../08-rates/01-rate-plans.md#market)); a company with no market sees only plans open to every market. Changing the company clears the plan of a line that is now hidden, and the staff pick another. A Suspended or foreign id is refused as `company_not_available` on the `company_id` field.
    - Source: a select of the property's active [booking sources](11-booking-sources.md), grouped by type (`BookingSourceOptions`). It posts `booking_source_id`.
        - **New booking** lists the active Direct and OTA sources, and **Company / Travel agent** for staff with `company.view` when the property has an Active company. The first Direct source is the default.
        - An OTA source makes External reference required (`external_reference` error "Enter the OTA booking number.").
        - Company / Travel agent makes Company required (`company_id` error "Select the company."). A Direct or OTA booking can still pick a company.
        - **Walk-in** fixes the source to Walk-in.
        - `StoreBookingRequest` checks the same rules: the source must be active, of the property, and its type must be allowed for the staff member (Company / Travel agent needs `company.view`). It checks the type and the permission, not the form mode, because both modes post to `reservations.store`. The walk-in room rule follows the Walk-in type.

        ```
        Source [Booking.com ▾]   External reference* [BK-12345 ]   Company [Search…   ▾]   Group name [____]
                ├ Direct:   Phone · WhatsApp · Email
                ├ OTA:      Booking.com · Agoda
                └ Company:  Company / Travel agent
        ```

2. **Rooms and beds** (tab **Rooms & guests**): one line per room type + rate plan.
    - Room lines: adults and children. When children > 0, one required age field per child, under the line.
    - Bed lines: "Beds: N", expanded into N units, with one bed picker per unit.
    - Package plans: departure is locked, the stay total is shown.
    - Children: one required age field per child under the line (not a comma list, unlike the Add unit Sheet on the reservation page).
    - Rate cell: the server quote from `RateQuoteService`. ⓘ opens the night breakdown. ✎ sets an override (logged, needs a reason).
    - A restriction that fails (stop-sell, closed to arrival or departure, min or max stay) shows a warning under the lines. Staff with `rates.override-restrictions` can continue after entering a reason (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).
3. **Guests** (tab **Rooms & guests**, under Rooms and beds): see [Guests section](#guests-section).
4. **Discounts** and **Add-ons** (optional, one tab each; both tables keep their header and footer when empty, with an `EmptyState` in the body: "Pick a room type in Rooms & guests first" until a room type is picked, "No room discounts" when the property has none, and "No add-ons yet" when the Add-ons table has no rows; the Add add-on button is disabled until a room type is picked). A tab with an error turns red and opens itself on submit; an error on a room, bed or guest opens **Rooms & guests**. Both tabs are tables, in the same style as the lines table.
    - Discounts: one row for each line, with the line label (room type and rate plan) and a RoomOnly discount select from the [catalogue](../06-catalogues/05-discounts.md) ("No discount" is the empty choice). One note under the table says the discount is applied on the folio, not in the rate. The booking stores only the discount id in the line's `NewUnitData`.
    - Add-ons: one flat table for all lines, one row for each add-on (see [Add-ons](04-add-ons.md)). Columns: Line, Charge item, Quantity (per-item charges), Service date (charges that are not nightly), Amount (when the item allows an override or has no default), and remove. The Line column is a select of the lines that moves the add-on to another line; with one line it is plain text. A Bed line gives every bed the same add-ons. The footer shows the count and an outline **Add add-on** button, right-aligned, that adds a row for the first line. The request still nests add-ons by line. The Add-ons tab shows a count badge.
5. **Notes** (tab **Notes**): one free text field in an `InputGroup` with a top addon (notebook icon and "Special requests or internal notes") and a bottom addon with a character counter ("0/2000") for special requests or internal notes, up to 2000 characters. It is stored in `reservations.notes` (see [Reservation shape](01-reservation-shape.md)) and shown in the summary card of the reservation page. The creation log records only that notes exist, never the text.

With a company picked and the billing mode Each unit pays (2+ units), the rail shows the same `eachPaysHint` under Billing: "Guests pay everything. Nothing is billed to Acme Sdn Bhd. Choose Master folio pays to bill the company."

The rail shows units, room and add-on totals (the add-on total comes from the quote, see [Implementation](#implementation)), the billing mode and the guest list. All amounts come from the server. A catalogue discount is not in the total, because the folio applies it (once per stay for a Fixed discount, when the folio applies it); while any line has one, a note under the total says so. Tax shows "from folio" until [Folios and lines](../13-folio/01-folios-and-lines.md) exists.

## Room / Bed column

Optional. A picked room or bed fills `room_id` or `bed_id` on every night of the unit.

| Case                             | Empty option says | Must pick? |
| -------------------------------- | ----------------- | ---------- |
| Booking, `auto_assign_rooms` off | Select later      | No         |
| Booking, `auto_assign_rooms` on  | Auto-assign       | No         |
| Walk-in                          | Select room       | Yes        |

- The list shows only rooms or beds free on every night of the unit, from `AvailabilityService`. A room picked on another line is disabled.
- Changing the room type or dates reloads the list. A picked room that is no longer free is cleared.
- `AssignRoom` and `AssignBed` lock the room type row, then check that the room or bed is free on every night. A refusal writes nothing and comes back as an inline error on the line. Two unique indexes on `unit_nights` (room and night, bed and night) enforce it in the database. See [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md).
- Mid-stay moves, `AutoAssignRooms` and later changes are in [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md).

## Guests section

A table with one collapsible row for each guest, and a footer with the count of units without a guest and **+ Add guest** (outline button, right-aligned, like **Add line** in Rooms and beds).

```
 GUESTS
 ┌───┬──────────────────────┬───────────────────┬───────────────────────┬──────┐
 │   │ GUEST                │ PHONE             │ STAYS IN              │      │
 ├───┼──────────────────────┼───────────────────┼───────────────────────┼──────┤
 │ ▾ │ Tan Wei Ming  Booker │ +60 12-345 6789   │ Deluxe King #1      ▾ │      │
 │   │ Email [tan@x.com]   ⚠ Blacklisted: "Damaged room, 2025"        │      │
 │   │ [ ] Update guest profile with my changes      [Change guest]   │      │
 │   │ Date of birth · Gender · Nationality · Address …               │      │
 ├───┼──────────────────────┼───────────────────┼───────────────────────┼──────┤
 │ ▸ │ Ahmad Faiz  Existing │ +60 13-888 2211   │ Dorm · Bed 1        ▾ │  ✕   │
 ├───┴──────────────────────┴───────────────────┴───────────────────────┴──────┤
 │ 2 units without a guest                                    [+ Add guest]    │
 └──────────────────────────────────────────────────────────────────────────────┘
```

Typing in Name opens the match list under the Name cell. The list floats over the rows below:

```
 │ ▾ │ ahmad▌               │ +60 ____________  │ Dorm · Bed 1        ▾ │  ✕   │
 │   ┌ Existing guests ───────────────────────────────────┐              │
 │   │ ▸ Ahmad Faiz      +60 12-345 6789  ahmad@x.com  VIP │              │
 │   │   Ahmad Zulkifli  +60 19-222 1010                   │              │
 │   └─────────────────────────────────────────────────────┘              │
```

### Rows

| Part           | Rules                                                                                                                                                                                                                                             |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Main row       | Chevron, **Name** (autocomplete), **Phone** (autocomplete), **Stays in** (select) and ✕. Most guests need nothing more.                                                                                                                           |
| Expanded row   | Email (autocomplete), the selected-guest card with the blacklist reason, **Change guest**, **Update guest profile**, the `DuplicateWarning`, and the guest details (date of birth, gender, nationality, address, language) with no second toggle. |
| Booker (first) | Always present, cannot be removed (no ✕). Becomes the booker of the reservation. "Stays in" also offers **Not staying**. Starts open.                                                                                                             |
| Other rows     | One guest staying in a unit. The name shows "Guest N" while empty. A new row opens when it is added.                                                                                                                                              |
| Badges         | Booker, Existing (linked guest) or New. A VIP or blacklisted guest shows an icon in the main row, so a warning is visible when the row is closed.                                                                                                 |
| Open state     | Several rows can be open. A row with an error opens itself.                                                                                                                                                                                       |
| Stays in       | Lists every unit on the form (Deluxe King #1, Dorm Bed 1). The first guest in a unit becomes `is_primary`. A bed unit takes one guest only.                                                                                                       |
| Narrow screens | Below `lg` each row is a stacked card with the same collapsible part.                                                                                                                                                                             |

Units without a guest are allowed. The footer counts them, and they show "No guest yet" with an **Add** button on the [Guests tab](06-reservation-page.md#guests-tab) of the reservation page.

### Autocomplete

- Name, Phone and Email are autocompletes. The match list floats under the cell that is being typed in. Search starts after 2 characters, to `guests.search`, debounced and cached per query. It returns up to 8 guests of the property. Merged and anonymized guests never show. Each result carries a `GuestProfileData` (date of birth, gender, nationality, language, address and the blacklist reason), so a pick needs no second request. The same data comes with the `guests.matches` results.
- Selecting a guest links the row to that `guest_id` and fills the fields, including the guest details.
- Typed changes to a linked guest update the profile only when **Update guest profile** is on (off by default).
- **Change guest** unlinks the guest, keeps the typed values and focuses Name.
- A blacklisted guest shows the reason as a warning. The booking is still allowed.
- With no guest picked, a new guest is created on save. The `DuplicateWarning` (`guests.matches`, `GuestMatcher`) shows under the fields with "Use existing" and "Create new anyway".

### Guest details

Shown in the expanded part of the row, with no second toggle. Email and the personal fields (nationality, date of birth, gender, preferred language) share one grid of three columns from `lg`; the address keeps its own layout below. The fields are: date of birth, gender, nationality, address line 1 and 2, city, state, postcode, country, preferred language. The fields come from `GuestDetailsFields`, extracted from `guest-form.tsx` and used by both forms, so they cannot drift apart. No ID document: ID is collected on the [registration card](../16-registration/04-signing-flow.md) at check-in and kept on the guest profile.

### Permissions

`reservations.view` and `reservations.manage` come with the [reservation page](06-reservation-page.md#permissions) item, which ships first. The booking form needs `reservations.manage`.

| Permission      | Allows                                     |
| --------------- | ------------------------------------------ |
| `guests.view`   | Search and select existing guests          |
| `guests.manage` | Create a guest or update a guest's profile |

Without `guests.manage`, a row accepts only an existing guest, and **Update guest profile** is hidden. The page gets `canManageGuests`. `CreateBooking` does not check it: `StoreBookingRequest` does, and answers `guests.{i}.full_name` "Pick an existing guest." for a row that would create or update a guest.

## Comes later

| Feature                                  | Module                                                                    |
| ---------------------------------------- | ------------------------------------------------------------------------- |
| Edit notes on the reservation page       | Not planned yet                                                           |
| Database constraint on room and night    | [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md) |
| Tax in the summary, MasterPays routing   | [Folio](../13-folio/01-folios-and-lines.md)                               |
| Collect payment, deposit, payment method | [Payments and refunds](../14-payments/01-payments-and-refunds.md)         |
| Walk-in check-in                         | [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md)   |
| Past stay and migrated stay modes        | [Past and migrated stays](12-past-and-migrated-stays.md)                  |

## Implementation

- Inertia `useForm` with a Form Request (`StoreBookingRequest`, shape only). No client-side validation library.
- `CreateBooking` (`app/Actions/Reservations/`) only runs other Actions in one transaction, so there is no god service. In order: `CreateGuest` or `UpdateGuest` for each guest tab, `CreateReservation` (which calls `AddUnit` for each line), `AttachGuest` for each unit with a guest, then `AssignRoom` or `AssignBed` for each unit with a pick. Any refusal rolls everything back, including new guests and the reference number, and names the line. `ReservationRefusals` turns the codes into inline errors.
- `NewReservationData` gains `notes`. `CreateReservation` writes it to `reservations.notes`.
- `AssignRoom` and `AssignBed` (whole stay) are new Actions, one concern each. Each logs `reservation.room_assigned` or `reservation.bed_assigned` with ids only. The Room & Rate editing around them stays in module 18.
- `FreeUnits` (`app/Services/Availability/`) lists the rooms or beds that are free on every night of a stay, from the same data as `AvailabilityService`. It reads only.
- Two JSON routes feed the form. **Quote** takes one line and returns the night breakdown, the total, restriction violations and the free unit count, all from `RateQuoteService`, `RestrictionCheck` and `AvailabilityService`. Commit 2 adds `add_ons` to the request (the same shape as `NewAddOnData`) and `addOnsTotal` to the answer, from the read-only `ChargeQuantityResolver`, so the rail never calculates a nightly or per-guest add-on in the browser. **Free units** takes a room type and dates and returns the pickable rooms or beds, and it is called only when a picker opens. The browser never calculates a price.
- After save, the form redirects to the reservation page with a toast that names the reference. This item shows **New ▾** on the Reservations list, for staff with `reservations.manage`.
- `FormActions` (`components/pms/form-actions.tsx`) is built with this page: a footer with an outline Cancel button (X icon) and the primary button (calendar-plus icon, a spinner while saving), on a muted background. It sticks to the bottom of the window by default; with `fixed` it is a plain row below the scroll region of a `fixed` page. The form uses the `use-unsaved-changes` guard when staff leave the page.
- `guests.search` and `GuestPicker` come from the [reservation page](06-reservation-page.md) item. This item adds the autocomplete field on the same route.
- The profile rules (name, phone checked against the property's country, email, date of birth and the rest) live in one class, `GuestProfileRules`, used by `GuestRequest` and `StoreBookingRequest`.
- Components: Base UI `Combobox` and `Collapsible`. The autocomplete field is `components/pms/guests/guest-autocomplete-field.tsx`. The guest details use `GuestDetailsFields`. `DuplicateWarning` gains **Use existing** (links the row to the match) and **Create new anyway** (hides the warning for the values typed; nothing is sent to the server). Form parts live in `components/pms/reservations/`.
- The page gets a query-count test with lazy loading prevented (see `.ai/rules/tests.md`).

## Saving and errors

- `GET reservations/new`, `GET reservations/walk-in` and `POST reservations` (`reservations.store`) are in `ReservationBookingController`. `new` and `walk-in` share one private render method.
- The page gets the lists it needs with the page, because they are bounded: room types and rate plans (`ReservationFormOptions::addUnit`), charge items, active RoomOnly discounts, the sources (`BookingSourceOptionData`: `{id, name, type, typeLabel, isActive}`, from `BookingSourceOptions::forBooking`), the billing modes, `autoAssignRooms`, the currency, `canOverrideRestrictions` and `today`, the business date, which is the default arrival. The prop is not named `businessDate`, because the app shell shares a prop of that name for the header indicator.
- `StoreBookingRequest` checks the shape and builds `NewBookingData`. It expands each line into units in the order `CreateReservation` adds them (a Bed line with N beds is N units), so the unit index of a pick is the running count. On a walk-in every unit needs a room or bed pick. From commit 3 the request has a `guests` array: for each tab `guest_id`, the profile fields, `update_profile` and `stays_in` (the unit index, or empty for a booker who does not stay). The first tab is the booker. `toBookingData()` builds one `NewBookingGuestData` for each tab. `booker_guest_id`, which commit 2 used, is gone.
- A refusal comes back as errors keyed by position: `lines.{i}.room_type_id`, `lines.{i}.rate_plan_id`, `lines.{i}.child_ages`, `lines.{i}.room_id`, `lines.{i}.bed_ids.{n}` and so on, plus `arrival_date`, `departure_date`, `guests.{i}.guest_id`, `guests.{i}.full_name`, `guests.{i}.stays_in` and `form`. A guest refusal (`guest_not_found`, `already_attached`, `bed_unit_full`, `unit_not_found`, `guest_name_required`) names the tab through the guest index of the result; `bed_unit_full` and `already_attached` show on "Stays in". The key comes from `ReservationRefusals` (the field) and the line or unit index of the result. `restricted` shows the override reason field of the line for staff with `rates.override-restrictions`, and a `form` error for everyone else.
- A `restricted` or other line-level refusal never saves anything: `CreateBooking` rolls back.

## Build order

One checklist item per commit, in this order:

1. **Backend:** the `notes` column, `AssignRoom`, `AssignBed`, `FreeUnits`, the quote and free-units routes, and `CreateBooking`, with tests. No UI.
2. **The page, with the booker as an existing guest:** the three routes, `StoreBookingRequest`, Stay, Rooms and beds, Group, Extras, Notes, the summary rail, `FormActions`, `New ▾` and Walk-in, the quote with add-ons, and notes in the summary card of the reservation page. Guests are not on the page yet: the booker is picked with `GuestPicker`, and units get no guest. Until commit 3, a walk-in of a person who is not yet a guest cannot be booked, so commit 3 follows straight after.
3. **Guest tabs:** the Guests section with the autocomplete field, `DuplicateWarning`, create and update of guests, and "Stays in".

4. **Stay times:** the `arrival_time` and `departure_time` columns, backfilled from the property settings; `NewUnitData`, `NewBookingData`, `AddUnit` (copies the times from the first unit when none are given), `CreateReservation` and `StoreBookingRequest`; the `InputGroup` Stay layout; the times on the reservation page. The Add unit Sheet has no time fields.
5. **Guest table:** the Guests section becomes a table with collapsible rows and an Add guest footer, replacing the tabs. UI only: the request, error keys and Actions do not change.
6. **Tabs and group name:** Group name moves into Stay. Rooms and beds and Guests share the **Rooms & guests** tab, and Discounts, Add-ons and Notes get a tab each, in a `line` tab strip. UI only: the request, error keys and Actions do not change.
7. **Discounts and add-ons tables:** the Discounts and Add-ons tabs become tables (a flat add-ons table with a Line column). UI only: the request, error keys and Actions do not change.
8. **Fixed height:** the page uses the `fixed` layout variant (renamed from `grid-only`), with one scroll region and `FormActions` below it. UI only.
9. **Reservation mode:** the field after Departure switches between New booking and Walk-in with a partial visit that keeps the form. UI only.
10. **Booking sources:** the source select lists the property's booking sources, with the OTA and Company rules. See [Booking sources](11-booking-sources.md#build-order). Built.

## Build status

Built (commit 1, backend only): the `notes` column, `NewReservationData.notes`, `AssignRoom`, `AssignBed` and `AssignmentResult`, `FreeUnits` (with `unitsByRoom` and `blockedUnits` made public on `AvailabilityService`), `BookingQuote`, `reservations.quote` and `reservations.free-units` (`ReservationBookingController`), and `CreateBooking` with `NewBookingData`, `NewBookingGuestData`, `UnitPickData` and `BookingResult`. `ReservationRefusals` knows the new codes: `room_not_found`, `bed_not_found`, `room_not_free`, `bed_not_free`, `booker_required`, `guest_name_required` and `unit_not_found`. `CreateBooking` does not check `guests.manage`: the Form Request of the booking form does, when it builds the guest tabs. The page and the guest tabs were not built in commit 1.

The quote answers 200 even when the line cannot be priced: `errors` holds the refusal keyed by form field, as the Add unit Sheet uses it. It prices one bed for a Bed room type, and `lineTotal` is that times `beds`. The catalogue discount and price overrides are not in the preview.

Built (commit 2, the page): `reservations.new`, `reservations.walk-in` and `reservations.store` in `ReservationBookingController`; `StoreBookingRequest` (shape, walk-in picks, `toBookingData()` and `unitsPerLine()`) and `BookingErrorKeys`; the quote with `add_ons`, `override_total` and `addOnsTotal`; `ReservationFormOptions::roomDiscounts` and `fixedNights` on the rate plan options; `canManage` on the list and `notes` on `ReservationData`. The page is `pages/reservations/create.tsx` with `FormActions`, `MoneyInput`, `BookingLineRow`, `BookingUnitPicker`, `BookingQuoteCell`, `BookingExtrasSection`, `BookingSummaryRail` and `NewBookingMenu`, and the pure helpers in `booking-lines.ts`. The billing mode in the rail is a select, not radio buttons. A package line locks the departure for the whole form, because the dates are shared. The booker was picked with `GuestPicker` and units got no guest until commit 3.

Built (commit 3, guest tabs): the `guests` array on `StoreBookingRequest` (the tabs are put back in form order after validation, because the validated array lists a tab where its first present field appears), the `guests.manage` and "stays in" checks, `BookingErrorKeys` for guest refusals, `GuestProfileData` on the search and match results, `GuestProfileRules` (shared with `GuestRequest`), `GuestDetailsFields` (shared with the guest page, as `GuestPersonalFields` and `GuestAddressFields` there), the shared `useGuestSearch` hook, `GuestAutocompleteField`, `DuplicateWarning` with Use existing and Create new anyway, and `BookingGuestsSection` with the helpers in `booking-guests.ts`. A tab is a Base UI `TabsTrigger` with a separate remove button beside it, because a button cannot sit inside a trigger. The unit list for "Stays in" and the bed-unit rule (one guest) are shown in the browser and checked again by `AttachGuest`. Switching tabs resets the duplicate warning of the tab.

Built (commit 4, stay times): the `arrival_time` and `departure_time` columns on `reservation_units` (backfilled from `property_settings`), `NewUnitData.arrivalTime` and `departureTime`, `AddUnit` (explicit time, else the first unit of the reservation, else the property time), `arrival_time` and `departure_time` on `StoreBookingRequest` (optional `H:i`; a missing time takes the property time), the `checkInTime`, `checkOutTime` and `nowTime` page props, `StayDateTime` (`InputGroup` with `DatePicker` and a native time input) with the nights badge, and the times in the unit panel of the reservation page. The Add unit Sheet is unchanged and inherits.

Built (commit 5, guest table): `BookingGuestsSection` is a table with one collapsible `GuestRow` for each guest (a Base UI `Collapsible`; `Tabs` is no longer used here), a header row, and a footer with the count of units without a guest (`unitsWithoutGuest`) and **Add guest**. `GuestAutocompleteField` keeps the search state and passes its parts (Name with badges, Phone, Email, the guest card, the notes) to a render function, so the row puts Name and Phone in its cells and Email in the expanded part; the match list sits under the field being typed in. A row opens by itself when a field outside the main row has an error. `guestTabLabel` is now `guestLabel`, and the section takes `guests` instead of `tabs`. The request, error keys and Actions did not change.

Built (commit 6, tabs and group name): Group name is a Stay field after External reference (shown with 2 or more units), and the Group section is gone. Below Stay a `line` tab strip holds **Rooms & guests**, **Discounts**, **Add-ons** (with a count badge) and **Notes**. Every panel stays mounted (`keepMounted`), so the rate quotes and the rail keep their totals while another tab is open. `BookingExtrasSection` is split into `BookingDiscountsSection` and `BookingAddOnsSection`. `booking-tabs.ts` maps each error key to its tab (`errorTabs`), turns the tab red, and on a failed submit opens the first tab with an error (`firstErrorTab`). The request, error keys and Actions did not change.

**Added automatically.** Each new room on the form gets the active auto-add charge items in its Add-ons, from the form options (`AutoAddOns`), tagged **Added automatically** with **×** to remove. The request sends the kept ones as ordinary add-ons and the removed item ids as `declined_auto_add_ons`, which are logged. See [Add-ons](04-add-ons.md#added-automatically).

Built (commit 7, discounts and add-ons tables): `BookingDiscountsSection` is a table of one row for each line with the discount select and a folio note in the footer. `BookingAddOnsSection` is one flat table with the columns Line, Charge item, Quantity, Service date, Amount and remove, a count and an outline **Add add-on** button in the footer (it adds a row for the first line). The Line column moves an add-on to another line with `moveAddOn` in one change to `lines`, so neither line is left stale; with one line it is plain text. A line-level add-on error shows under the first add-on row of that line. The request, error keys and Actions did not change.

Built (commit 8, fixed height): the layout variant `grid-only` is renamed `fixed` (`PageLayoutVariant`, `AppSidebarLayout`, the rate calendar and its docs). `ReservationCreate.layout` returns `variant: 'fixed'`. The form is a flex column with one scroll region (`scroll-region`, `overflow-y-auto`) that holds the title row and the two columns, and `FormActions fixed` below it. The window does not scroll, the breadcrumb header stays on top and the footer is always visible. The rail's `sticky` works inside the scroll region.

Built (commit 9, reservation mode): a **Reservation mode** select after Departure (New booking or Walk-in, from `lg` a 12rem column). Picking the other mode calls `guard.visitWithout`, a new function of `useUnsavedChanges` that visits without the unsaved-changes dialog, as a partial visit (`only: isWalkIn, sources, nowTime`) with `preserveState` and `preserveScroll`. The page stays mounted, so the dates, lines and guests are kept; an effect on `isWalkIn` then sets the source (WalkIn, or the first offered source) and, for Walk-in, the arrival date and time. A test checks that the walk-in page answers the partial request with only those props.

Built (commit 10, booking sources): the form posts `booking_source_id`. `EnumSelect` takes an optional `group` on an option and shows a heading per group, so the Source select lists Direct, OTA and Company / Travel agent. `sourceSelectOptions` maps the options. An OTA source adds "(required)" to External reference and a Company / Travel agent source to Company, and the server enforces both (`external_reference` "Enter the OTA booking number.", `company_id` "Select the company."). A Company source without `company.view` is refused on `booking_source_id`. The mode switch sets the first offered source.

The lines are one table-like grid from `lg` (`BookingLinesHeader` and `LINE_COLUMNS` in `booking-line-row.tsx`): a header row, then one row per line with Room type, Rate plan, Room / Bed, Ad, Ch, Rate and remove. A dorm line shows the Beds count in the Room / Bed cell and the bed pickers on a second line; children's ages, quote errors and "Only N free" also go on a second line. Below `lg` the cells stack and show their labels.

## Lessons from wastays

- wastays only asked for child ages at per-guest hotels and fell back to a flat child price when they were missing, which overcharged infants and undercharged older children.
- The booking creation form was 335 lines of ERB plus a 761-line `booking_room_rows_controller.js`. `CreateManualBooking` (439 lines) mixed guest matching, rate override, prepayment and audit.
- wastays stored one `room_number` per booking. estatell stores the room per night, so a picked room fills every night.
- wastays asked for ID type and number at booking. estatell leaves ID to the registration card, so it does not store ID for guests who never arrive.
