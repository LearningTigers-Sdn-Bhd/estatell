# Boat manifest

## Purpose

One list per boat for the jetty and the boat crew: who travels on each departure of a date, with head counts. The front desk also sees the charter and own-boat trips of the day.

Needs the `Boats` feature.

## Report

A report in the [core reports](../22-reports/01-core-reports.md) registry, so it gets the shared frame: quick dates, column layout, row selection and PDF, CSV and Excel export.

| Report        | `ReportKey`    | Source                                        | Dates    | Default orientation | Category   | Icon   |
| ------------- | -------------- | --------------------------------------------- | -------- | ------------------- | ---------- | ------ |
| Boat manifest | `BoatManifest` | `BoatManifest` service over `unit_boat_trips` | One date | Portrait            | Front desk | `ship` |

- The report shows in the reports list only when the feature is on. Its page, PDF, spreadsheet and layout routes return 404 when it is off (`ReportKey::feature()`, `EnsureReportIsAvailable`).
- The rows are not paged and keep their order (departure `leaves_at`, then id; Charter and Own by unit time, last). The headers do not sort. Each Charter and Own row's time is the unit's `arrival_time` (In) or `departure_time` (Out).
- **Filter:** Direction (Boat in, Boat out, Boat in and out). Default: Boat in. **Boat in and out** (`direction=both`, `ReportFilters::$bothDirections`) lists every departure of the date in time order, as Sand Bay's sheet does (08:00 out, 09:00 in, 14:30 out…), and each section title starts with "Boat out · " or "Boat in · ".
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
| Reservation | `reservation` | No              | Reservation number                                                                                     |
| Note        | `note`        | No              | The trip note                                                                                          |

- **Lead guest only.** The manifest never lists every guest and never shows identity numbers.
- Rows come grouped by departure in `leaves_at` order. Charter and Own rows come last, in a group headed "Charter and own boat (front desk only)".
- Each Scheduled group has a subtotal row: "14 / 20 seats" (or "14" with no limit). Over the limit uses the warning tone.
- The total row sums adults, children and total for the whole date and direction.

## Boats and crew

The boat and crew of each departure on the date, as on Sand Bay's sheet ("SA10716", "Awang / Daus").

```
boat_departure_runs    id, property_id, boat_departure_id, date, boat_id (nullable, FK boats, restrict),
                       crew (nullable, ≤ 120), timestamps
                       unique (boat_departure_id, date); CHECK boat_id or crew is set
```

- A collapsible **Boats and crew** panel above the filters (`BoatsAndCrewPanel`, the report screen's `aside` slot) lists the date's departures in the shown direction (or both): active ones and any with a run. Each row has a Boat select (active boats, or "No boat") and a Crew input, with **Save**. Staff without `reservations.manage` see it read only.
- `reports.boat-manifest.runs` (PUT, `boats` and `reservations.manage`) runs `SetBoatDepartureRun`: it saves the row, removes it when both are empty, refuses a boat that is not an active boat of the property, and logs `boat_departure_run.set`.
- The section title of each departure ends with " · SA10716 · Awang / Daus" (`BoatManifestReport::runLabels()`), so the page, the PDF and the spreadsheet sections name them.

## PDF

- Uses the printing settings letterhead, Portrait.
- One section per departure. A page break goes before each departure, so each boat crew gets its own page.
- The Charter and own boat group is **left out of the PDF** by default. Selecting its rows prints them on a last page.

## Empty state

"No boat in trips on 12 Oct." or "No boat out trips on 12 Oct.", following the Direction filter. With no departures at all: "No departures yet" and "Add departures in Settings, Boats.", with an **Open Boats settings** button for users with `settings.manage`.

## Permissions

`reports.view`, as every report.
