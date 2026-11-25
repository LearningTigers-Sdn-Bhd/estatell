# Guides and boats

## Purpose

An activity session (River Safari 06:00, Night Walk 20:00) needs a guide and, on the river, a boat. Staff pick both for each session on a date, and the participant list the guide carries names them. Asked for by Tanini (requirement 4.4) on 2026-11-25.

**Status: built** (2026-11-25).

## Decided

| #   | Decision                                                                                                                                                                                   |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Guides and boats are lists of the Facilities module (Settings › Facilities), not the island Boats module's boats and crew. Activities work without the Boats feature (decided 2026-11-25). |
| 2   | The pick belongs to a **slot**: one facility on one date at one time. All the bookings of a session share its guide and boat.                                                              |
| 3   | One guide and one boat per slot. A second boat for a big group is a second session.                                                                                                        |
| 4   | A boat's seats only warn. The facility's How many stays the limit of the clash check.                                                                                                      |

## Data model

```
facility_guides      id, property_id, name (unique per property, case-insensitive), phone nullable, active
facility_boats       id, property_id, name (unique per property, case-insensitive), seats nullable (> 0), active
facility_slot_crews  id, property_id, facility_id → facilities (cascade), slot_date, starts_at, ends_at,
                     facility_guide_id → facility_guides (restrict) nullable,
                     facility_boat_id → facility_boats (restrict) nullable,
                     UNIQUE (facility_id, slot_date, starts_at, ends_at),
                     CHECK (facility_guide_id IS NOT NULL OR facility_boat_id IS NOT NULL)
```

- Guides and boats are never deleted; the **Available** switch hides them from the pickers. A slot keeps a guide or boat that was turned off after it was picked.
- A `facility_slot_crews` row exists only while a guide or a boat is set. Picking **Not set** for both deletes it.

## Settings

Settings › Facilities gets link tabs: **Facilities**, **Guides** and **Boats**, each with its own URL. Guides: Available, Name, Phone. Boats: Available, Name, Seats ("Guest seats, not the boatman or guide."). A Dialog adds and edits one; another guide or boat of the property with the same name is refused. `SaveFacilityGuide` and `SaveFacilityBoat` log `facility_guide.*` and `facility_boat.*`.

## Activities run sheet

Front desk › **Activities** (`activities.index`, after Transfers, with the Facilities feature), one date at a time with the same date bar as Transfers.

```
Time         Activity                   Booked         Guide          Boat
06:00–08:00  River Safari               11 / 12 people [Jamil ▾]      [Kingfisher · 10 seats ▾]   ☑
                                        Boat seats 10
16:00–18:00  River Safari                0 / 12 people [Not set ▾]    [Not set ▾]
20:00–22:00  Night Walk  Other time      4 / 20 people [Rosli ▾]      [Not set ▾]                 ☑
```

- **Rows** (`ActivityRunSheet`): every session of each available Person facility, and every other time a Tentative or Confirmed booking of a Person facility takes ("Other time"). By facility order, then start time. Item facilities (halls, kayaks) are left out.
- **Booked** sums the Tentative and Confirmed quantities against How many, in `warning` when over. When the boat's seats are fewer than the people booked, "Boat seats 10" shows in `warning` under it.
- **Guide** and **Boat** are Selects with **Not set**, the available guides or boats, and the current pick if it was turned off since. A change saves at once with an optimistic update (`AssignFacilitySlotCrew`).
- The list icon opens the [Facility bookings report](05-timeline-and-report.md#facility-bookings-report) for that facility and date. That report is the participant list: each section heading ends with "Guide: Jamil · Boat: Kingfisher" when set, on screen and in the PDF, and the Dietary remarks column is on by default.

`AssignFacilitySlotCrew` refuses, with an error toast and nothing written or logged:

| Refusal           | When                                                                      | Toast                                                          |
| ----------------- | ------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `guide_not_found` | A new guide that is turned off or belongs to another property             | "That guide is not available. Pick another."                   |
| `boat_not_found`  | The same for a boat                                                       | "That boat is not available. Pick another."                    |
| `no_slot`         | The times are no session of the facility and no live booking on that date | "This session is no longer on the run sheet. Reload the page." |

A pick that changes nothing writes and logs nothing. A change logs `facility.slot_crew_assigned` on the facility.

## Routes

| Route                                              | Name                                     | Needs                                 |
| -------------------------------------------------- | ---------------------------------------- | ------------------------------------- |
| `GET /p/{code}/activities?date=`                   | `activities.index`                       | `front-desk.view`, Facilities feature |
| `PUT /p/{code}/activities/{facility}/crew`         | `activities.assign`                      | `facilities.book`, Facilities feature |
| `GET/POST /p/{code}/settings/facilities/guides`    | `settings.facilities.guides.index/store` | `settings.manage`, Facilities feature |
| `PUT /p/{code}/settings/facilities/guides/{guide}` | `settings.facilities.guides.update`      | `settings.manage`, Facilities feature |
| `GET/POST /p/{code}/settings/facilities/boats`     | `settings.facilities.boats.index/store`  | `settings.manage`, Facilities feature |
| `PUT /p/{code}/settings/facilities/boats/{boat}`   | `settings.facilities.boats.update`       | `settings.manage`, Facilities feature |

Another property's facility, guide or boat in the URL is a 404 (scoped bindings).

## Not built

- A guide's or boat's own schedule across activities (one guide on two sessions at the same time is not refused).
- Guides and boats on the Timeline View's Facilities rows.

## Tests

`tests/Feature/Facilities/ActivityCrewTest.php`: settings with unique names and the permission; the rows (sessions, other times, places, Cancelled left out, Item facilities left out); picking, the no-change pick, Not set deleting the row, the report heading; the three refusals and another property's facility; the permissions and the feature flag; a constant query count. The dietary column is in `tests/Feature/Reports/FacilityBookingsReportTest.php`.
