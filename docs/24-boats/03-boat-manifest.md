# Boat manifest

## Purpose

One list per boat for the jetty and the boat crew: who travels on each sailing of a date, with head counts. The front desk also sees the charter and own-boat trips and the boat jobs of the day.

The manifest is **not a page**. It is the rows, the tick and the printed sheet behind the [Boat Trips board](05-boats-board.md), the only boat page. Staff see, tick, print and export the manifest from the board's **Export Boat Manifest ▾** menu ([Export](05-boats-board.md#export-boat-manifest)).

Needs the `Boats` feature.

## Report key

`ReportKey::BoatManifest` stays in the [core reports](../22-reports/01-core-reports.md) registry, with no page and no menu entry, as `ReportKey::HousekeepingTasks` does for Housekeeping. The shared services need the key: the column layout (`ReportLayoutResolver`), the PDF (`ReportPdf`) and the spreadsheet (`ReportSpreadsheet`).

| Report        | `ReportKey`    | Source                                        | Dates    | Default orientation | Category   | Icon   |
| ------------- | -------------- | --------------------------------------------- | -------- | ------------------- | ---------- | ------ |
| Boat manifest | `BoatManifest` | `BoatManifest` service over `unit_boat_trips` | One date | Landscape           | Front desk | `ship` |

- There is no `reports.boat-manifest` page and no sidebar entry. `listedInReportsMenu()` stays false. Its old URL is a 404.
- The board's export routes and the shared layout routes return 404 when the Boats feature is off (`boats` middleware, `ReportKey::feature()`, `EnsureReportIsAvailable`).
- The rows are not paged and keep their order (sailing time, then sailing id; then Charter, then Own, each by unit time; then jobs by time, last). Each Charter and Own row's time is the unit's `arrival_time` (In) or `departure_time` (Out).
- **Direction:** Boat in, Boat out, Both, from the board's toggle group. **Both** (`direction=both` or no direction, `ReportFilters::$bothDirections`) lists every sailing of the date in time order, as Sand Bay's sheet does (08:00 out, 09:00 in, 14:30 out…), and each section title starts with "Boat out · " or "Boat in · ".

## Rows

One row per trip: Confirmed, CheckedIn and CheckedOut units whose trip is on the chosen date and direction. Cancelled and No-show units are left out. The row key is the trip id.

**Boat not set.** An overnight unit that arrives (Boat in, Confirmed) or leaves (Boat out, Confirmed or Checked in) on the date with no trip in that direction gets a row too, in a last section **Boat not set: follow up** (warning tone, printed by default), so the desk can call the guest or the agent. Its Departure reads "Not set · 15:00" (the unit's expected time). Its row key is negative (`BoatManifestRow::notSetKey()`: `-(unit id × 2)` for Boat in, `-(unit id × 2 + 1)` for Boat out), so it never meets a trip id.

| Column         | Key           | Default visible | Content                                                                                                 |
| -------------- | ------------- | --------------- | ------------------------------------------------------------------------------------------------------- |
| Departure      | `departure`   | Yes             | "09:00 Kuala Besut jetty → 09:45", or "Charter 10:30", "Own 11:30"                                      |
| Room           | `unit`        | Yes             | Room number or bed (first night for Boat in, last night for Boat out), else the room type               |
| Lead guest     | `lead_guest`  | Yes             | The unit's lead guest (the same rule as Arrivals), else the reservation's booker                        |
| Booking source | `agent`       | Yes             | The reservation's company, else "Direct booking" (direct and walk-in), else the booking source (Agoda). |
| Phone          | `phone`       | Yes             | The lead guest's phone                                                                                  |
| Adults         | `adults`      | Yes             |                                                                                                         |
| Children       | `children`    | Yes             |                                                                                                         |
| Total          | `total`       | Yes             | Adults + children                                                                                       |
| Boat           | `boat`        | Yes             | The sailing's boat ([Boats and crew](#boats-and-crew)), or "Own boat", "Charter"                        |
| Crew           | `crew`        | Yes             | The sailing's crew, the person in charge first: "Din (in charge), Razak"                                |
| Tick           | `tick`        | Yes             | The Arrived or Left box on the board ([Tick](#tick)); "Yes" in the spreadsheet                          |
| Reservation    | `reservation` | No              | Reservation number                                                                                      |
| Note           | `note`        | No              | The trip note                                                                                           |

- **Lead guest only.** The manifest never lists every guest and never shows identity numbers.
- Rows come grouped by sailing in time order. Then two groups: **Charter**, then **Own boat**, each with its rows of both directions.
- Each sailing has a subtotal row: "14 / 20 seats" (or "14" with no limit), with the sailing's seats ([Seats](04-sailings.md#seats)). Over the limit uses the warning tone.
- The total row sums adults, children and total for the whole date and direction.

## Boats and crew

Each section is one [sailing](04-sailings.md): one boat on one date. A departure that runs two boats on a date has two sections, each with its own boat, crew and rows.

- The section title reads "Boat in · 09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 2 · Din (in charge), Razak". A changed time adds "(changed)". With no boat or crew set, the title ends after the times.
- A cancelled sailing on the date is left out. A sailing with guests cannot be cancelled ([Cancel](04-sailings.md#cancel)).
- Staff set boats, crew and times on the [Boat Trips board](05-boats-board.md).
- The section labels come from `SailingLabels` (`app/Services/Boats/`), so the board, the PDF and the spreadsheet name them the same way.

## Boat jobs

Sailings of kind Job ([Jobs](04-sailings.md#jobs)) come in a last group **Boat jobs**, after Own boat and before Boat not set. On the PDF they have their own columns: Time, Job, Boat, Crew, Note and Done. The spreadsheet adds one row per job after the guest rows: "Boat job · 16:00" in Departure, the job in Lead guest, then Boat, Crew, Note and Done ("Yes" in Tick). They have no guests, so they add nothing to the totals. Boat in and Boat out exports, and exports of ticked rows, leave them out.

## Tick

Staff tick a row when its guests have arrived (Boat in) or left (Boat out).

```
unit_boat_trips (adds)   ticked_at (nullable), ticked_by (nullable, FK users, null on delete)
```

- `boats.trips.set-tick` (`PUT /p/{code}/boats/trips/{trip}/tick`, `boats` and `reservations.manage`) runs `SetBoatTripTick`. It replaces `reports.boat-manifest.tick`. A trip of another property is a 404. A user without the permission sees the state as text only. On the board it is a **Mark arrived** or **Mark left** button with a confirm, and **Undo** once ticked ([Boat Trips board](05-boats-board.md#layout)).
- The tick records who and when, and logs `reservation.boat_trip_ticked` on the reservation. It does **not** check the guest in or out.
- A repeated click changes nothing and logs nothing.
- `SetBoatTrip` and `MoveBoatTrips` clear the tick when the trip moves to another departure, sailing or mode, because the old tick no longer says anything. A note change keeps it.
- A row with no trip (Boat not set) has no tick.
- A printed empty departure has an empty box to tick by hand. It is not saved.

## PDF

`HasPrintView`: its own sheet (`pdf/boat-manifest.blade.php`) as Sand Bay's manifest. **Always A4 landscape**, whatever the saved layout says (`printOrientation()`). No letterhead.

- The header is "{PROPERTY} MANIFEST" and the date.
- One block per sailing, in time order, both directions by default. Red for Boat out, green for Boat in: the time ("0800"), the jetty (`pickup_point`), then No, Guest, Booking source, PAX, BOAT, Crew (the person in charge red, assistants blue) and Tick, and a "4 PAX" subtotal.
- A sailing that carries nobody prints with its boat and crew and a "-" row, so the crew still has a sheet.
- Charter, then Own boat, come next, per direction (Charter first since 2026-11-10, to match the board), with the first departure's jetty. When a mode has no trips that date, it still prints as one empty grey block (band "IN OR OUT", jetty "-"), like an empty departure, so the sheet matches the board. Boat in and Boat out exports leave them out. Then a grey **Boat jobs** block (Time, Job, Boat, Crew, Note, Done). An amber **Boat not set** block comes last.
- A block never splits across pages (`page-break-inside: avoid`). There is no page break between departures, so a normal day fits on one page.
- Ticking rows on the board prints only the blocks of those rows.
- The saved column layout does not change the sheet. It changes Excel and CSV only.

## Permissions

Export needs `reservations.view` through the board's routes ([Boat Trips board](05-boats-board.md#routes)). Columns need `settings.manage`.
