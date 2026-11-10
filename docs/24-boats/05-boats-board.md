# Boat Trips board

## Purpose

The front desk's only boat page for one date: who goes on which boat, the boat and crew in charge, today's time changes, extra trips and boat jobs, and ticks as guests arrive or leave. It also prints and exports the manifest for the jetty and the crew. It works like the [room status board](../19-housekeeping/01-room-status-board.md): groups, row ticks, a bulk bar, export and columns.

There is no separate Boat manifest page. The rows, the columns, the tick and the PDF sheet are specified in [Boat manifest](03-boat-manifest.md). The board reads them through `ReportKey::BoatManifest`, as Housekeeping reads `ReportKey::HousekeepingTasks`.

Needs the `Boats` feature.

## Where it lives

- Front desk sidebar, after Housekeeping: **Boat Trips** (icon `ship`), when the feature is on and the user has `reservations.view`. The page title and the browser tab say **Boat Trips** too.
- Only the words staff see say Boat Trips. The URL, the route names and the **Boats** settings tab keep their names.
- `GET /p/{code}/boats` (`boats.index`). Query: `date` (default the business date) and `direction` (`in`, `out`, `both`; default `both`).
- Archetype: Board, with grouped tables as on Housekeeping ([Page archetypes](../design-system/04-page-archetypes.md)).

## Layout

```
Boat Trips                                    [+ Trip or job]  [Export Boat Manifest ▾]
[◀] Tue 11 Nov [▶]  [Today] [Tomorrow]     [ Boat in | Boat out | Both ]

[ ] Boat out · 08:00 Resort jetty → 08:45 · MV Sand Bay 1 · ⚓ Awang · Daus     6 / 20  ⋯
     Room  Lead guest     Agent          Phone          Pax     Note            Left
 [ ] 101   Tan Wei Ming   Direct booking 012-111 2222   2A 1C                   [✓]
 [ ] 104   Sarah Lee      Agoda          —              2A 0C   Early ferry     [ ]

[ ] Boat in · 09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 1 · ⚓ Awang · Daus  5 / 20  ⋯
 [ ] 102   Ahmad Faiz     Borneo Tours   013-333 4444   2A 0C                   [ ]
 [ ] 103   Lee Family     Direct booking 016-555 6666   2A 1C                   [ ]

[ ] Boat in · 09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 2 · ⚓ Din · Razak   9 / 12  ⋯
 [✓] 205   Ahmad Faiz     Borneo Tours   013-333 4444   4A 2C                   [ ]
 [✓] 206   Lim Family     Borneo Tours   019-777 8888   3A 0C                   [ ]

    Boat out · 14:30 → 15:00 (changed) · MV Sand Bay 2 · ⚓ Din               0 / 12  ⋯
     No guests.

    Boat in · 16:00 Kuala Besut jetty · Cancelled                             [Undo]   (muted)

[ ] Charter                                                         2 rooms · 4 pax
     Room  Lead guest     Agent          Trip                           Pax     Done
 [ ] 205   Wong Mei       Agoda          In · 10:30 · Wedding party     2A 0C   [ ]
 [ ] 104   Sarah Lee      Direct booking Out · 15:00                    2A 0C   [ ]

[ ] Own boat                                                        1 room · 3 pax
 [ ] 301   Lim Family     Borneo Tours   Out · 11:30 · Own speedboat    3A 0C   [ ]

    Boat jobs
     11:00  Diesel   MV Sand Bay 2 · ⚓ Razak   4 drums                          Done [ ]
     15:30  Linen    MV Sand Bay 1 · ⚓ Daus                                     Done [✓]

  ⚠ Boat not set: follow up
     302   Ong Siew Lan   Direct booking 012-999 0000   2A 0C   Not set · 15:00  [Set boat]

                ┌ 2 selected   [Move to ▾]  [Mark arrived]  [Export ▾]  ✕ ┐
```

- Groups come in this order, as on the PDF: one group per [sailing](04-sailings.md) in time order across both directions, then **Charter**, then **Own boat**, then Boat jobs, then Boat not set.
- **Charter** and **Own boat** are two groups. Their header shows the room and pax count, with no seat limit. A **Trip** column shows the direction, the time and the note ("In · 10:30 · Wedding party"), because one group can hold both directions. Their tick column is **Done**, because the rows mix directions. It is the same trip tick. Charter and Own boat always show, even with no rooms: "No charter trips." or "No own boat trips." under the header. Only a date with no group at all shows the page empty state instead.
- The group header shows the direction, time, jetty, boat, ⚓ the person in charge, the assistants and the seat count. Over the limit uses the warning tone.
- The **Arrived** (Boat in) or **Left** (Boat out) column is the [trip tick](03-boat-manifest.md#tick). It saves at once, like the housekeeping status.
- **Set boat** on a Boat not set row opens the reservation page's boat Change Dialog for that unit and direction.
- At phone width each group becomes a card list: room, guest and pax on the first line, agent and phone on the second, the tick on the right.
- Past dates are read only except the ticks. Their ⋯ menus and **+ Trip or job** are hidden.

## Row menu

The **⋯** at the end of each guest row (sailing, Charter and Own boat groups):

| Item             | Shows when                                      | Does                                                                                                                                                                                                               |
| ---------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Change boat      | `reservations.manage`, and the date is not past | Opens the reservation page's boat **Change** Dialog for that unit and direction ([Reservation page](02-boat-trips.md#reservation-page)): Not set, the departures with seats, Charter, Own boat, then Time and Note |
| Open reservation | Always                                          | Goes to the reservation page                                                                                                                                                                                       |

- **Change boat** is how staff set Charter or Own boat from this page. It reuses the Dialog and `SetBoatTrip`, so there is no new logic. After saving, the row moves to its new group.
- **+ Trip or job** stays for boats the resort runs. It does not offer Charter or Own boat, because those belong to one room.
- **Move to** in the bulk bar stays for moving several rooms between scheduled sailings.
- Boat not set rows keep their **Set boat** button, which opens the same Dialog.

## Group menu

The **⋯** on a sailing group header, for users with `reservations.manage`:

| Item                 | Shows when                                    | Does                                                                                                         |
| -------------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Change boat and crew | Always                                        | The Dialog below (`SaveSailing`)                                                                             |
| Change time          | Always                                        | A small Dialog with Leaves and Arrives (`SaveSailing`)                                                       |
| Add boat             | A departure sailing                           | Adds a sailing with the same time (`AddSailingBoat`), then opens Change boat and crew for it                 |
| Remove boat          | An added boat with no guests                  | `RemoveSailing`                                                                                              |
| Default              | The departure has stored sailings on the date | Back to the timetable (`ResetDepartureDate`)                                                                 |
| Cancel trip          | A departure sailing                           | An AlertDialog, then `CancelDepartureDate`. With guests: "Move the 6 guests first." and the item is disabled |
| Delete               | An extra trip with no guests                  | `RemoveSailing`                                                                                              |

Boat jobs have **Edit** and **Delete** instead, while not done.

### Change boat and crew

```
Change boat and crew · Boat in 09:00 · 11 Nov     ×
 Boat         [MV Sand Bay 2           ▾]
 In charge    [Din                     ▾]
 Assistants   [Razak ×]                 ▾
                                [Cancel] [Save]
```

A Dialog (`FormOverlay`, `sm`). The selects work as in the departure Sheet ([Add or edit departure](01-boat-settings.md#add-or-edit-departure)), with each crew member's phone under the name.

### Crew pickers

**In charge** and **Assistants** show each crew member's phone (`boat_crew_members.phone`) as a muted second line under the name. A crew member with no phone shows the name only. This applies to Change boat and crew, Trip or job and the departure Sheet in Settings. No schema change.

### Trip or job

**+ Trip or job** opens a Dialog (`FormOverlay`, `md`) for the shown date:

```
Add trip or job · 11 Nov                          ×
 Type         (•) Extra trip   ( ) Boat job
 Direction    [Boat in              ▾]          (extra trip)
 Job          [Diesel               ▾]          (boat job)
 Leaves       [11:00]   Arrives [11:45]         (no Arrives on a job)
 Pick-up point [Kuala Besut jetty          ]    (extra trip, optional)
 Boat         [MV Sand Bay 2        ▾]
 In charge    [Razak                ▾]
 Assistants   [                      ▾]
 Note         [4 drums                     ]
                                [Cancel] [Add]
```

It calls `AddExtraSailing` or `SaveBoatJob`.

## Bulk bar

`BulkActionBar`, as on Housekeeping. A group header ticks all its rows. Boat jobs and Boat not set rows have no tick box.

| Action       | Does                                                                                                                                                                      |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Move to      | A select of the date's other sailings in the same direction ("09:00 · MV Sand Bay 2 · 9 / 12"). `MoveBoatTrips`. Shows only when every ticked row has the same direction. |
| Mark arrived | Ticks the selected trips (`SetBoatTripTick`). Reads **Mark left** when every row is Boat out.                                                                             |
| Export       | PDF, CSV or Excel of the ticked rows                                                                                                                                      |

- `POST /p/{code}/boats/trips/move` (`boats.trips.move`) takes `trip_ids` (at most 200) and `boat_sailing_id`, or `boat_departure_id` and `date` for a departure with no stored sailing. A trip of another property is a 404.
- `POST /p/{code}/boats/trips/tick` (`boats.trips.tick`) takes `trip_ids`. It runs `SetBoatTripTick` for each, skipping trips already ticked.
- The selection clears after the action works.

## Export Boat Manifest

**Export Boat Manifest ▾** in the page header replaces the old **Print** button. It is a menu for the shown date and direction:

```
Export Boat Manifest ▾
  PDF
  Excel
  CSV
  ─────────────
  Columns…            (settings.manage)
```

- **PDF** opens the [manifest sheet](03-boat-manifest.md#pdf) in a new tab. **Excel** and **CSV** download the [manifest rows](03-boat-manifest.md#rows) with the saved columns.
- With rows ticked, the bulk bar's **Export** has the same three formats for the ticked rows only. It reuses the same menu component.
- **Columns…** opens the shared `ReportLayoutSheet` for `boat-manifest`: order and visibility of the columns. The layout is per property. The PDF is a fixed sheet, so the columns change Excel and CSV only. The Sheet says so in one line.
- The browser sends the date, the direction and the ticked trip ids, never row data. The server reads the rows again with the saved layout, so an id from another property is dropped.
- The export routes need `reservations.view`, not `reports.view`, as on Housekeeping. Front desk staff can export without access to Reports.

## Routes

All need the `boats` middleware. Viewing and export need `reservations.view`; everything else needs `reservations.manage`. The Columns Sheet uses the shared `reports.layout.*` routes, which need `settings.manage`.

| Route                                               | Name                      | Action                                              |
| --------------------------------------------------- | ------------------------- | --------------------------------------------------- |
| `GET /p/{code}/boats`                               | `boats.index`             | `DateSailings` and `BoatManifest`                   |
| `PUT /p/{code}/boats/sailings`                      | `boats.sailings.save`     | `SaveSailing` (stores the main sailing when needed) |
| `POST /p/{code}/boats/sailings`                     | `boats.sailings.store`    | `AddSailingBoat` or `AddExtraSailing`               |
| `DELETE /p/{code}/boats/sailings/{sailing}`         | `boats.sailings.destroy`  | `RemoveSailing`                                     |
| `PUT /p/{code}/boats/departures/{departure}/reset`  | `boats.departures.reset`  | `ResetDepartureDate` (`date` in the body)           |
| `PUT /p/{code}/boats/departures/{departure}/cancel` | `boats.departures.cancel` | `CancelDepartureDate` (`date`, `cancelled`)         |
| `POST /p/{code}/boats/jobs`                         | `boats.jobs.store`        | `SaveBoatJob`                                       |
| `PUT /p/{code}/boats/jobs/{sailing}`                | `boats.jobs.update`       | `SaveBoatJob`                                       |
| `DELETE /p/{code}/boats/jobs/{sailing}`             | `boats.jobs.destroy`      | `DeleteBoatJob`                                     |
| `PUT /p/{code}/boats/jobs/{sailing}/done`           | `boats.jobs.done`         | `SetBoatJobDone`                                    |
| `POST /p/{code}/boats/trips/move`                   | `boats.trips.move`        | `MoveBoatTrips`                                     |
| `POST /p/{code}/boats/trips/tick`                   | `boats.trips.tick`        | `SetBoatTripTick` per trip                          |
| `PUT /p/{code}/boats/trips/{trip}/tick`             | `boats.trips.set-tick`    | `SetBoatTripTick` (`ticked` in the body)            |
| `POST /p/{code}/boats/pdf`                          | `boats.pdf`               | `ReportPdf` for `ReportKey::BoatManifest`           |
| `POST /p/{code}/boats/spreadsheet`                  | `boats.spreadsheet`       | `ReportSpreadsheet` for `ReportKey::BoatManifest`   |

A `{sailing}` or `{departure}` of another property is a 404. A `{sailing}` of the wrong kind for the route is a 404.

## Empty states

- Departures on the timetable but no guests: each sailing group shows "No guests.". Staff can still set the boat and crew and export the sheet for the crew. Charter and Own boat show with "No charter trips." and "No own boat trips.". Boat not set is hidden.
- No sailings, jobs or trips on the date: "No boats on 11 Nov." with **+ Trip or job**.
- No departures at all: "No departures yet" and "Add departures in Settings, Boats.", with **Open Boats settings** for users with `settings.manage`.

## Permissions

| Permission            | Can                                                            |
| --------------------- | -------------------------------------------------------------- |
| `reservations.view`   | See the board, export PDF, Excel and CSV                       |
| `reservations.manage` | Ticks, moves, boats, crew, times, cancel, extra trips and jobs |
| `settings.manage`     | Columns…                                                       |

No new permission.
