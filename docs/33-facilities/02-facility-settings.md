# Facility settings

> **Built** (checklist item 2). Bookings, events and posting are still planned. See [Facilities and events](01-overview.md).

## Purpose

The list of things staff can book: halls, a BBQ pit, kayaks, activities. Each one says whether it is booked by item or by person, how many there are and what it costs. It is set up like rooms: one question, how many do you have.

## Data model

```
facilities   id, property_id, name, description null,
             booked_by (FacilityBookedBy),
             capacity int,
             charge_item_id → charge_items (restrict) UNIQUE,
             rate_basis (FacilityRateBasis),
             position int, active (default true), timestamps
             UNIQUE (property_id, lower(name))
             CHECK capacity > 0

facility_sessions   id, property_id, facility_id → facilities (cascade),
                    starts_at time, ends_at time, timestamps
                    UNIQUE (facility_id, starts_at, ends_at)
                    CHECK ends_at > starts_at
```

- **The price is a charge item**, as a boat fare is ([Fares](../24-boats/01-boat-settings.md#fares-tab)). The charge item holds the amount, the taxes and the transaction code, so routing rules, tax and reports work with no new code. Its amount is the **list price**: a booking copies it and staff can change it on the booking ([Price](04-facility-bookings.md#price)). **Charge by hand** clears the charge item's amount (null): a booking then posts nothing on its own. Every facility keeps its own charge item, so switching back keeps the same code and taxes.
- The charge item uses the new category **Facility** (`TransactionCategory::Facility`), so a routing rule can send all facility charges to one folio.
- `capacity` is **How many**: the number of items (1 hall, 3 BBQ pits, 10 kayaks) or the number of people at once (25 in the public onsen). It is always the limit (see [Clash check](04-facility-bookings.md#clash-check)).
- Any facility can have sessions. They are optional: a facility with no sessions (kayaks) takes any time.

## Booked by (decided 2026-11-23)

Facilities follow rooms: staff answer **Booked by** and **How many**. This replaces the earlier Private / Shared choice, its Count by cards and the pax / units select, which overlapped and confused staff.

| Booked by | A booking takes                 | How many means         | Examples                                        |
| --------- | ------------------------------- | ---------------------- | ----------------------------------------------- |
| Item      | One or more whole items         | Items the property has | Meeting room 1, Hall 1, BBQ pit 3, Kayak 10     |
| Person    | Places for the people it brings | People at one time     | Public onsen 25, Public sauna 10, Night Walk 20 |

- A "private" facility is an Item facility with 1: one group takes it and nobody else can book that time.
- An Item booking does not record the group size. Staff write it in the booking's Notes.

## Enums

| Enum                  | Cases (label)                                            | Notes                                                                                                                                                                              |
| --------------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FacilityBookedBy`    | Item ("Item"), Person ("Person")                         | `description()`: "A group takes one. Like a room." / "People join until it is full." `unit()`: "items" / "people"                                                                  |
| `FacilityRateBasis`   | Day, Session, Hour, Each ("Per day", "Per session", ...) | `quantity(startsAt, endsAt, quantity)`: Day and Session 1, Hour the booked hours rounded up, Each the booking quantity. Each reads "Per item" or "Per person", following Booked by |
| `TransactionCategory` | adds Facility ("Facility")                               | In `chargeItemCases()`, so also a routing category                                                                                                                                 |

## Where it lives

Settings sidebar, **Property** group, after Boats: **Facilities** (icon `tent`). Only when the feature is on and the user has `settings.manage`. A Settings page with one table.

```
Facilities                                                    [+ Add facility]
Halls, equipment and activities guests can book.
───────────────────────────────────────────────────────────────────────────────
Available     Name             Booked by   How many    Rate (MYR)
[on]          Riverside Hall   Item         1          1,500.00 per day       ↑ ↓ Edit
[on]          BBQ pit          Item         3            150.00 per session   ↑ ↓ Edit
[on]          Kayak            Item        10             40.00 per hour      ↑ ↓ Edit
[on]          Night Walk       Person      20             80.00 per person    ↑ ↓ Edit
```

- The **Available** switch in the first column turns a facility on or off. Its charge item follows.
- The name opens the [facility page](#facility-page).
- Rows sort by `position`. The ↑ and ↓ buttons change it, as on Charge items. The Timeline View uses the same order.
- **Rate** shows the charge item's amount and the rate basis, or "Charge by hand".
- **Edit** opens the facility page, as on Charge items. There is no ⋯ menu.

## Add facility (Sheet)

| Field     | Control                                              | Placeholder         |
| --------- | ---------------------------------------------------- | ------------------- |
| Name      | Text                                                 | e.g. Riverside Hall |
| Booked by | Two radio cards with icons and the enum descriptions | –                   |
| How many  | Number, with "items" or "people" after it            | e.g. 3              |
| Rate      | Amount (MYR), or the switch **Charge by hand**       | e.g. 1500           |
| Per       | Select from `FacilityRateBasis::options()`           | Choose a rate basis |
| Available | Switch                                               | –                   |

Saving opens the new facility's page, where staff add taxes and sessions.

## Facility page

`settings/facilities/{facility}`. A Detail page with tab links and a page-scroll `Workspace`, like Booking website. The facility is edited only here, so there is no Edit Sheet and no trip to the charge item page.

```
Settings › Facilities › Night Walk › Details ▾
←  Night Walk                                         [on] Available
[Details] [Sessions (2)]
┌────────────────────────────────────────────┐ ┌────────────────────┐
│ Details                                    │ │ Summary            │
│ Name [Night Walk]                          │ │ Booked by Person   │
│ Booked by (○) Item  (●) Person             │ │ How many  20 people│
│ How many [20 people]                       │ │                    │
│ Rate [MYR 80.00]      Per [Per person]     │ │ Rate  80.00 /person│
│ [ ] Charge by hand                         │ │ Taxes     SST      │
│ Taxes [SST 8%]                             │ │ Code   NIGHT_WALK  │
│                             [Save details] │ │ Sessions  2        │
└────────────────────────────────────────────┘ └────────────────────┘
```

| Tab      | Route                                | Holds                                                         |
| -------- | ------------------------------------ | ------------------------------------------------------------- |
| Details  | `settings.facilities.show`           | Name, Booked by, How many, Rate or Charge by hand, Per, Taxes |
| Sessions | `settings.facilities.sessions.index` | The facility's fixed times, see [Sessions](#sessions)         |

- The header's **Available** switch saves at once, like the one on the list. A facility that is off shows **Unavailable** beside its name.
- The summary in the aside shows the saved facility on every tab.
- Save updates the facility and its charge item in one transaction (`UpdateChargeItem`): category Facility, charging unit Per item, `allow_override` on, `auto_add` off. The charge item's name, price, taxes and active state follow the facility. The code is derived from the name when the facility is added (Riverside Hall → `RIVERSIDE_HALL`) and does not change. This is the boat fare pattern.
- Settings › Charge items does not list facility charge items, and its category picker has no Facility, so only a facility makes one. A direct link to such a charge item's edit page opens the facility page (`ChargeItemFacility`). Post charge on a folio, reports, routing rules and tax still use the charge item.
- The name must be free among facilities and among charge items, because the charge item takes it.
- `booked_by` cannot change while the facility has a future booking that is not Cancelled: "Cancel or move its future bookings first." (`booked_by_locked`). The quantities of those bookings would mean something else.

## Sessions

Any facility can have fixed times (Private onsen 19:00–20:00, BBQ pit lunch and dinner). When it has any, the [Book facility](04-facility-bookings.md#book-facility-sheet) Sheet shows a **Session** select instead of From and To. Every booking of a session then has the same times, and the report adds them up in one row.

Sessions are repeating rows, so they get their own tab (DESIGN.md §5): `settings/facilities/{facility}/sessions`.

```
Sessions                                              [+ Add session]
Staff pick one of these when they book Night Walk.
──────────────────────────────────────────────────────────────────────
From     To
06:30    08:30     ⋯
20:00    22:00     ⋯
```

- **+ Add session** and **⋯ › Edit** open a Dialog with two time fields: From (e.g. 20:00) and To (e.g. 22:00). **⋯ › Delete** asks first.
- Rows sort by `starts_at`.
- A booking copies the session's times and has no link to the session. Changing or deleting a session does not change saved bookings.

## Actions

| Action                  | Does                                                                                                                                  |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateFacility`        | Creates the facility and its charge item in one transaction. Logs `facility.created`                                                  |
| `UpdateFacility`        | Updates both, including the Available switches. Without `tax_ids` the taxes stay. Logs `facility.updated`. Refuses `booked_by_locked` |
| `ReorderFacilities`     | Saves `position`. Refuses a list that is not exactly the property's facilities                                                        |
| `SaveFacilitySession`   | Creates or updates one session. The same times twice is a validation error                                                            |
| `DeleteFacilitySession` | Deletes one session                                                                                                                   |

## Routes

All need `settings.manage` and the `Facilities` feature.

| Route                                                                | Name                                   |
| -------------------------------------------------------------------- | -------------------------------------- |
| `GET /p/{code}/settings/facilities`                                  | `settings.facilities.index`            |
| `POST /p/{code}/settings/facilities`                                 | `settings.facilities.store`            |
| `GET /p/{code}/settings/facilities/{facility}`                       | `settings.facilities.show`             |
| `PUT /p/{code}/settings/facilities/{facility}`                       | `settings.facilities.update`           |
| `PUT /p/{code}/settings/facilities/order`                            | `settings.facilities.reorder`          |
| `GET /p/{code}/settings/facilities/{facility}/sessions`              | `settings.facilities.sessions.index`   |
| `POST /p/{code}/settings/facilities/{facility}/sessions`             | `settings.facilities.sessions.store`   |
| `PUT /p/{code}/settings/facilities/{facility}/sessions/{session}`    | `settings.facilities.sessions.update`  |
| `DELETE /p/{code}/settings/facilities/{facility}/sessions/{session}` | `settings.facilities.sessions.destroy` |
