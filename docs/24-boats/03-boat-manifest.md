# Boat manifest

## Purpose

One list per boat for the jetty and the boat crew: who travels on each departure of a date, with head counts. The front desk also sees the charter and own-boat trips of the day.

Needs the `Boats` feature.

## Report

A report in the [core reports](../22-reports/01-core-reports.md) registry, so it gets the shared frame: quick dates, column layout, row selection and PDF, CSV and Excel export.

| Report        | `ReportKey`    | Source                                        | Dates    | Default orientation | Category                     | Icon   |
| ------------- | -------------- | --------------------------------------------- | -------- | ------------------- | ---------------------------- | ------ |
| Boat manifest | `BoatManifest` | `BoatManifest` service over `unit_boat_trips` | One date | Landscape           | Front desk (sidebar: Guests) | `ship` |

- The report shows in the Front desk sidebar under Guests only when the Boats feature is on. Its page, PDF, spreadsheet and layout routes return 404 when it is off (`ReportKey::feature()`, `EnsureReportIsAvailable`).
- The rows are not paged and keep their order (departure `leaves_at`, then id; Charter and Own by unit time, last). The headers do not sort. Each Charter and Own row's time is the unit's `arrival_time` (In) or `departure_time` (Out).
- **Filter:** Direction (Boat in, Boat out, Boat in and out). Default: Boat in and out. **Boat in and out** (`direction=both` or no direction, `ReportFilters::$bothDirections`) lists every departure of the date in time order, as Sand Bay's sheet does (08:00 out, 09:00 in, 14:30 out…), and each section title starts with "Boat out · " or "Boat in · ".
- **Quick dates:** Today, Tomorrow.

## Rows

One row per trip: Confirmed, CheckedIn and CheckedOut units whose trip is on the chosen date and direction. Cancelled and No-show units are left out. The row key is the trip id.

**Boat not set.** An overnight unit that arrives (Boat in, Confirmed) or leaves (Boat out, Confirmed or Checked in) on the date with no trip in that direction gets a row too, in a last section **Boat not set: follow up** (warning tone, printed by default), so the desk can call the guest or the agent. Its Departure reads "Not set · 15:00" (the unit's expected time). Its row key is negative (`BoatManifestRow::notSetKey()`: `-(unit id × 2)` for Boat in, `-(unit id × 2 + 1)` for Boat out), so it never meets a trip id.

| Column      | Key           | Default visible | Content                                                                                                |
| ----------- | ------------- | --------------- | ------------------------------------------------------------------------------------------------------ |
| Departure   | `departure`   | Yes             | "09:00 Kuala Besut jetty → 09:45", or "Charter 10:30", "Own 11:30"                                     |
| Room        | `unit`        | Yes             | Room number or bed (first night for Boat in, last night for Boat out), else the room type              |
| Lead guest  | `lead_guest`  | Yes             | The unit's lead guest (the same rule as Arrivals), else the reservation's booker                       |
| Agent       | `agent`       | Yes             | The reservation's company, else "Direct booking" (direct and walk-in), else the booking source (Agoda) |
| Phone       | `phone`       | Yes             | The lead guest's phone                                                                                 |
| Adults      | `adults`      | Yes             |                                                                                                        |
| Children    | `children`    | Yes             |                                                                                                        |
| Total       | `total`       | Yes             | Adults + children                                                                                      |
| Boat        | `boat`        | Yes             | The departure's boat on the date ([Boats and crew](#boats-and-crew)), or "Own boat", "Charter"         |
| Boat asst   | `crew`        | Yes             | The departure's crew on the date                                                                       |
| Tick        | `tick`        | Yes             | A tick box on the page ([Tick](#tick)); "Yes" in the spreadsheet                                       |
| Reservation | `reservation` | No              | Reservation number                                                                                     |
| Note        | `note`        | No              | The trip note                                                                                          |

- **Lead guest only.** The manifest never lists every guest and never shows identity numbers.
- Rows come grouped by departure in `leaves_at` order. Charter and Own rows come last, in a group headed "Charter and own boat".
- Each Scheduled group has a subtotal row: "14 / 20 seats" (or "14" with no limit). Over the limit uses the warning tone.
- The total row sums adults, children and total for the whole date and direction.

## Boats and crew

The boat and crew of each departure on the date, as on Sand Bay's sheet ("SA10716", "Awang / Daus").

```
boat_departure_runs    id, property_id, boat_departure_id, date, boat_id (nullable, FK boats, restrict),
                       crew (nullable, ≤ 120), timestamps
                       unique (boat_departure_id, date); CHECK boat_id or crew is set
```

- **Timetable defaults:** Departures can set a default boat and default crew in Settings › Boats › Departures. On any date without a daily override, the boat manifest automatically inherits and displays these defaults (e.g. section titles and PDF automatically read " · MV Sand Bay 1 · Awang / Daus" without needing daily entry).
- A collapsible **Boats and crew** panel above the filters (`BoatsAndCrewPanel`, the report screen's `aside` slot) lists the date's departures in the shown direction (or both). If no daily override exists, fields are prefilled with the timetable defaults and tagged "Timetable default".
- **Unified Save all form:** Staff can edit boats and crew across multiple departures and click a single **Save changes** button (saving all departures in one batch request via `reports.boat-manifest.runs`).
- **Overrides & Reverts:** Overridden departures display a subtle "Custom override" label and a **Default** button to revert them back to timetable defaults with one click (`reset_to_default`).
- `reports.boat-manifest.runs` (PUT, `boats` and `reservations.manage`) runs `SetBoatDepartureRun`: it saves batch runs or single runs, removes override records when reset to default or when both boat and crew are empty, refuses inactive boats or boats of other properties, and logs `boat_departure_run.set`.
- The section title of each departure ends with " · SA10716 · Awang / Daus" (`BoatManifestReport::runLabels()`), so the page, the PDF and the spreadsheet sections name them.

## Tick

Staff tick a row when its guests have arrived (Boat in) or left (Boat out).

```
unit_boat_trips (adds)   ticked_at (nullable), ticked_by (nullable, FK users, null on delete)
```

- `reports.boat-manifest.tick` (`PUT .../reports/boat-manifest/trips/{trip}/tick`, `boats` and `reservations.manage`) runs `SetBoatTripTick`. A trip of another property is a 404. A user without the permission sees the box disabled.
- The tick records who and when, and logs `reservation.boat_trip_ticked` on the reservation. It does **not** check the guest in or out.
- A repeated click changes nothing and logs nothing.
- `SetBoatTrip` clears the tick when the trip moves to another boat or mode, because the old tick no longer says anything. A note change keeps it.
- A row with no trip (Boat not set) has no tick box.
- A printed empty departure has an empty box to tick by hand. It is not saved.

## PDF

`HasPrintView`: its own sheet (`pdf/boat-manifest.blade.php`) as Sand Bay's manifest. **Always A4 landscape**, whatever the saved layout says (`printOrientation()`). No letterhead.

- The header is "{PROPERTY} MANIFEST" and the date.
- One block per departure, in `leaves_at` order, both directions by default. Red for Boat out, green for Boat in: the time ("0800"), the jetty (`pickup_point`), then No, Guest, Agent, PAX, BOAT, Boat Asst (first name red, next blue) and Tick, and a "4 PAX" subtotal.
- An active departure that carries nobody prints with its boat and crew and a "-" row, so the crew still has a sheet.
- Own boat, then Charter, come next, per direction, with the first departure's jetty. An amber **Boat not set** block comes last.
- A block never splits across pages (`page-break-inside: avoid`). There is no page break between departures, so a normal day fits on one page.
- Selecting rows prints only the blocks of those rows.

## Empty state

"No boat in trips on 12 Oct." or "No boat out trips on 12 Oct.", following the Direction filter. With no departures at all: "No departures yet" and "Add departures in Settings, Boats.", with an **Open Boats settings** button for users with `settings.manage`.

## Permissions

`reports.view`, as every report.
