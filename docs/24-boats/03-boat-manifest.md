# Boat manifest

## Purpose

One list per boat for the jetty and the boat crew: who travels on each departure of a date, with head counts. The front desk also sees the charter and own-boat trips of the day.

Needs the `Boats` feature.

## Report

A report in the [core reports](../22-reports/01-core-reports.md) registry, so it gets the shared frame: quick dates, column layout, row selection and PDF, CSV and Excel export.

| Report        | `ReportKey`    | Source                                        | Dates    | Default orientation | Category   | Icon   |
| ------------- | -------------- | --------------------------------------------- | -------- | ------------------- | ---------- | ------ |
| Boat manifest | `BoatManifest` | `BoatManifest` service over `unit_boat_trips` | One date | Portrait            | Front desk | `ship` |

- The report shows in the reports list only when the feature is on. Its route returns 404 when it is off.
- **Filter:** Direction (Boat in, Boat out). Default: Boat in.
- **Quick dates:** Today, Tomorrow.

## Rows

One row per trip: Confirmed, CheckedIn and CheckedOut units whose trip is on the chosen date and direction. Cancelled and No-show units are left out. The row key is the trip id.

| Column      | Key           | Default visible | Content                                                            |
| ----------- | ------------- | --------------- | ------------------------------------------------------------------ |
| Departure   | `departure`   | Yes             | "09:00 Kuala Besut jetty → 09:45", or "Charter 10:30", "Own 11:30" |
| Room        | `unit`        | Yes             | Room number or bed, else the room type                             |
| Lead guest  | `lead_guest`  | Yes             | The unit's primary guest, else the reservation's booker            |
| Phone       | `phone`       | Yes             | The lead guest's phone                                             |
| Adults      | `adults`      | Yes             |                                                                    |
| Children    | `children`    | Yes             |                                                                    |
| Total       | `total`       | Yes             | Adults + children                                                  |
| Reservation | `reservation` | No              | Reservation number                                                 |
| Note        | `note`        | No              | The trip note                                                      |

- **Lead guest only.** The manifest never lists every guest and never shows identity numbers.
- Rows come grouped by departure in `leaves_at` order. Charter and Own rows come last, in a group headed "Charter and own boat (front desk only)".
- Each Scheduled group has a subtotal row: "14 / 20 seats" (or "14" with no limit). Over the limit uses the warning tone.
- The total row sums adults, children and total for the whole date and direction.

## PDF

- Uses the printing settings letterhead, Portrait.
- One section per departure. A page break goes before each departure, so each boat crew gets its own page.
- The Charter and own boat group is **left out of the PDF** by default. Selecting its rows prints them on a last page.

## Empty state

"No boat in trips on 12 Oct." With no departures at all: "No departures yet. Add them in Settings, Boats." with a link for users with `settings.manage`.

## Permissions

`reports.view`, as every report.
