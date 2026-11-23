# Facility settings

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

The list of things staff can book: halls, a BBQ pit, kayaks, activities. Each one says whether it is Private or Shared, how many it holds and what it costs.

## Data model

```
facilities   id, property_id, name, description null,
             sharing (FacilitySharing),
             capacity int, capacity_unit (FacilityCapacityUnit),
             charge_item_id null → charge_items (restrict),
             rate_basis (FacilityRateBasis),
             position int, active (default true), timestamps
             UNIQUE (property_id, name)
             CHECK capacity > 0

facility_sessions   id, property_id, facility_id → facilities (cascade),
                    starts_at time, ends_at time, timestamps
                    UNIQUE (facility_id, starts_at, ends_at)
                    CHECK ends_at > starts_at
```

- **The price is a charge item**, as a boat fare is ([Fares](../24-boats/01-boat-settings.md#fares-tab)). The charge item holds the amount, the taxes and the transaction code, so routing rules, tax and reports work with no new code. Its amount is the **list price**: a booking copies it and staff can change it on the booking ([Price](04-facility-bookings.md#price)). Null means "charge by hand": the booking posts nothing on its own.
- The charge item uses the new category **Facility** (`TransactionCategory::Facility`), so a routing rule can send all facility charges to one folio.
- `capacity` is the size for a Private facility (shown, not checked) and the limit for a Shared one (checked, see [Clash check](04-facility-bookings.md#clash-check)).
- Only a Shared facility has sessions. They are optional: a Shared facility with no sessions (kayaks) takes any time.

## Enums

| Enum                   | Cases (label)                                              | Notes                                                                                                         |
| ---------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `FacilitySharing`      | Private ("Private"), Shared ("Shared")                     | `description()`: "One booking takes the whole facility." / "Several bookings share it until it is full."      |
| `FacilityCapacityUnit` | Pax ("pax"), Units ("units")                               | Kayaks are units; halls and activities are pax                                                                |
| `FacilityRateBasis`    | Day, Session, Hour, Person ("Per day", "Per session", ...) | `quantity(FacilityBooking)`: Day and Session 1, Hour the booked hours rounded up, Person the booking quantity |
| `TransactionCategory`  | adds Facility ("Facility")                                 | In `chargeItemCases()`, so also a routing category                                                            |

## Where it lives

Settings sidebar, **Property** group, after Boats: **Facilities** (icon `tent`). Only when the feature is on and the user has `settings.manage`. A Settings page with one table.

```
Facilities                                       [+ Add facility]
Halls, equipment and activities guests can book.
────────────────────────────────────────────────────────────────
Name             Sharing    Capacity   Rate (MYR)          Status
Riverside Hall   Private    150 pax    1,500.00 / day      Active   ⋯
BBQ pit          Private     20 pax      150.00 / session  Active   ⋯
Kayak            Shared       6 units     40.00 / hour     Active   ⋯
Night Walk       Shared      20 pax       80.00 / person   Active   ⋯
```

- Rows sort by `position`, with drag to reorder, like charge items. The Timeline View uses the same order.
- **Rate** shows the charge item's amount and the rate basis, or "Charge by hand".
- The **⋯** menu: **Edit** (the Sheet), **Sessions** (Shared only, see below), **Turn off** or **Turn on**, and **More settings** (the charge item page, for taxes).

## Add or edit facility (Sheet)

| Field    | Control                                              | Placeholder         |
| -------- | ---------------------------------------------------- | ------------------- |
| Name     | Text                                                 | e.g. Riverside Hall |
| Sharing  | Two radio cards with icons and the enum descriptions | –                   |
| Capacity | Number, with the unit select (pax, units) beside it  | e.g. 150            |
| Rate     | Amount (MYR), or the switch **Charge by hand**       | e.g. 1500           |
| Per      | Select from `FacilityRateBasis::options()`           | Choose a rate basis |
| Active   | Switch                                               | –                   |

- Save creates or updates the charge item in the same transaction (`CreateChargeItem`, `UpdateChargeItem`), category Facility, charging unit Per item, `auto_add` off. The charge item's name follows the facility name. This is the boat fare pattern.
- `sharing` cannot change while the facility has a future booking that is not Cancelled: "Cancel or move its future bookings first."
- Saving a facility as Private deletes its sessions.

## Sessions

A Shared facility can have fixed times. When it has any, the [Book facility](04-facility-bookings.md#book-facility-sheet) Sheet shows a **Session** select instead of From and To. Every booking of a session then has the same times, and the report adds them up in one row.

Sessions are repeating rows, so they get a page of their own (DESIGN.md §5): **⋯ › Sessions** opens `settings/facilities/{facility}/sessions`.

```
Settings › Facilities › Night Walk › Sessions
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

| Action                  | Does                                                                                                          |
| ----------------------- | ------------------------------------------------------------------------------------------------------------- |
| `CreateFacility`        | Creates the facility and its charge item in one transaction. Logs `facilities.created`                        |
| `UpdateFacility`        | Updates both. Refuses `sharing_locked`. Deletes the sessions when saved as Private. Logs `facilities.updated` |
| `SetFacilityActive`     | Turns it on or off. An inactive facility keeps its bookings but takes no new ones                             |
| `ReorderFacilities`     | Saves `position`                                                                                              |
| `SaveFacilitySession`   | Creates or updates one session. Refuses `facility_not_shared` and `session_exists`                            |
| `DeleteFacilitySession` | Deletes one session                                                                                           |

## Routes

All need `settings.manage` and the `Facilities` feature.

| Route                                                                | Name                                   |
| -------------------------------------------------------------------- | -------------------------------------- |
| `GET /p/{code}/settings/facilities`                                  | `settings.facilities.index`            |
| `POST /p/{code}/settings/facilities`                                 | `settings.facilities.store`            |
| `PUT /p/{code}/settings/facilities/{facility}`                       | `settings.facilities.update`           |
| `PUT /p/{code}/settings/facilities/order`                            | `settings.facilities.reorder`          |
| `GET /p/{code}/settings/facilities/{facility}/sessions`              | `settings.facilities.sessions.index`   |
| `POST /p/{code}/settings/facilities/{facility}/sessions`             | `settings.facilities.sessions.store`   |
| `PUT /p/{code}/settings/facilities/{facility}/sessions/{session}`    | `settings.facilities.sessions.update`  |
| `DELETE /p/{code}/settings/facilities/{facility}/sessions/{session}` | `settings.facilities.sessions.destroy` |
