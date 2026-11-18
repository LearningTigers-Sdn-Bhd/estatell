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
```

- **The price is a charge item**, as a boat fare is ([Fares](../24-boats/01-boat-settings.md#fares-tab)). The charge item holds the amount, the taxes and the transaction code, so routing rules, tax and reports work with no new code. Null means "charge by hand": the booking posts nothing on its own.
- The charge item uses the new category **Facility** (`TransactionCategory::Facility`), so a routing rule can send all facility charges to one folio.
- `capacity` is the size for a Private facility (shown, not checked) and the limit for a Shared one (checked, see [Clash check](04-facility-bookings.md#clash-check)).

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
- The **⋯** menu: **Edit** (the Sheet), **Turn off** or **Turn on**, and **More settings** (the charge item page, for taxes and child prices).

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

## Actions

| Action              | Does                                                                                   |
| ------------------- | -------------------------------------------------------------------------------------- |
| `CreateFacility`    | Creates the facility and its charge item in one transaction. Logs `facilities.created` |
| `UpdateFacility`    | Updates both. Refuses `sharing_locked`. Logs `facilities.updated`                      |
| `SetFacilityActive` | Turns it on or off. An inactive facility keeps its bookings but takes no new ones      |
| `ReorderFacilities` | Saves `position`                                                                       |

## Routes

All need `settings.manage` and the `Facilities` feature.

| Route                                          | Name                          |
| ---------------------------------------------- | ----------------------------- |
| `GET /p/{code}/settings/facilities`            | `settings.facilities.index`   |
| `POST /p/{code}/settings/facilities`           | `settings.facilities.store`   |
| `PUT /p/{code}/settings/facilities/{facility}` | `settings.facilities.update`  |
| `PUT /p/{code}/settings/facilities/order`      | `settings.facilities.reorder` |
