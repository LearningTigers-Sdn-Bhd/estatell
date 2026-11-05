# Arrival import

## Purpose

Bring a hotel's bookings from its old PMS into estatell from a file: the eZee **Arrival List** CSV export as it comes, or estatell's own template (CSV or XLSX). Staff upload, check a preview, then import. Built for Sand Bay (November 2026), whose only source is the eZee arrival list. A second upload of a newer export adds only the new bookings.

## Page

```
Reservations › Import arrivals                    [Upload CSV / XLSX]  [Template ▾ CSV · XLSX]
┌ Business date ┐ ┌ Go-live ──────┐ ┌ Deposits accepted until ┐
│ 03 Nov 2026   │ │ 01 Nov 2026   │ │ 30 Jan 2027             │
└───────────────┘ └───────────────┘ └─────────────────────────┘
Reason [Imported from old system arrival list            ]
┌ - - - - Drag a file here · [Choose a file] - - - - ┐  (before a file is read)
# │ Res no │ Guest │ Room │ Room type │ Arrival │ Departure │ Pax │ Total │ Deposit │ Company │ Source │ Boat in │ Boat out │ Becomes
                                                                                  [Cancel]  [Import 80 rooms]
```

| Part       | Rule                                                                                                                                                                                                                                                                                    |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| URL        | `GET /p/{code}/reservations/import` (`reservations.import`). Full page (uploads)                                                                                                                                                                                                        |
| Entry      | The reservations list **New ▾** menu: **Import arrivals**, shown with `reservations.manage` and `folio.manual`                                                                                                                                                                          |
| Permission | `reservations.manage` and `folio.manual` on every import route (past stays and Old system deposits need `folio.manual`)                                                                                                                                                                 |
| File       | **Upload CSV / XLSX**, **Choose a file** in the empty state, or drag and drop a file anywhere on the page (the empty state shows a dashed drop zone; with a preview shown, a drop replaces the file). Only `.csv` and `.xlsx` are read; another file shows an error and nothing is sent |
| Preview    | `POST .../reservations/import/preview` with the file reads it and returns the rows, with what each becomes and its problems. Nothing is saved                                                                                                                                           |
| Import     | `POST .../reservations/import` (`reservations.import.store`) with the same file and the reason. The server reads the file again, and refuses everything when any row has a problem. All rows or none                                                                                    |
| Template   | `GET .../reservations/import-template.{csv\|xlsx}`: the header row of the estatell columns                                                                                                                                                                                              |
| Limits     | Up to 300 rows, 2 MB                                                                                                                                                                                                                                                                    |
| After      | Back to the reservations list with "12 bookings imported (80 rooms)."                                                                                                                                                                                                                   |

## Files

Headers match ignoring case and spaces. A file without the required template columns (`res_no`, `guest`, `arrival`, `departure`, `adults`, `total`) is refused before any row is read, naming the missing ones. A file with a `Res. No` column is an eZee export; otherwise it must have the estatell columns.

| estatell column | eZee column                          | Value                                                                                                             |
| --------------- | ------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `res_no`        | `Res. No`                            | Required. `RES4840-3` belongs to group `RES4840`                                                                  |
| `guest`         | `Guest`                              | Required. A title (Mr., Ms., Mrs., Mdm., Dr.) is removed                                                          |
| `room`          | `Room` (`A10 - Deluxe Room(King)`)   | Room number, optional. eZee gives number and type in one cell                                                     |
| `room_type`     | (from `Room`)                        | Required when there is no room. Matched to a room type by its words, ignoring case, brackets and "room"           |
| `arrival`       | `Arrival` (`03/11/2026 03:00:00 PM`) | Required. `Y-m-d` or `d/m/Y`, with an optional time                                                               |
| `departure`     | `Departure`                          | Required. After the arrival                                                                                       |
| `adults`        | `Pax` (`2 / 0`)                      | Required, 1 or more                                                                                               |
| `children`      | (from `Pax`)                         | 0 or more. Booked aged 6                                                                                          |
| `total`         | `Rate(RM)` × `Nights`                | Required. The stay total of the room. eZee's rate is per night                                                    |
| `deposit`       | `Deposit(RM)`                        | Optional. Paid in the old system                                                                                  |
| `company`       | `Company`                            | Optional. A company of the property, by name ignoring case                                                        |
| `source`        | `Business Source`                    | Optional. A booking source by name ignoring case. Empty: the company's source when there is a company, else Phone |
| `status`        | `Res.Type`                           | See [Status](#status)                                                                                             |
| `boat_in`       | (remarks: `BOAT IN: 1030`)           | Optional. `OWN BOAT`, or a time matched to a Boat in departure. Boat properties only                              |
| `boat_out`      | (remarks: `BOAT OUT: 1430`)          | Optional. As Boat in                                                                                              |
| `notes`         | `Reservation Remarks`                | Optional. Added to the booking notes                                                                              |

A boat value that is not a time (`TBA`) sets no trip. eZee remarks columns are searched for the boat times (`BOAT IN`, `BOAT IN TIME`, then `:` and a time or `OWN BOAT`).

## Status

| File status                                | Becomes                                                                             |
| ------------------------------------------ | ----------------------------------------------------------------------------------- |
| `Confirm Booking`, `confirmed`, empty      | A booking                                                                           |
| `Hold Confirm Booking`, `hold`             | A booking, with "Hold in old system" in its notes. estatell has no tentative status |
| `in_house`, `Checked In`                   | A past stay, still in house                                                         |
| `checked_out`, `Checked Out`               | A past stay, already checked out                                                    |
| `Released`, `Cancelled`, `Void`, `No Show` | Skipped                                                                             |

A booking that arrives before the business date becomes a past stay: still in house when it departs after the business date, already checked out otherwise. A past stay needs a room, an arrival on or after the go-live date, and a departure; it charges every night with its real date ([Past stay](12-past-and-migrated-stays.md#past-stay)).

## Grouping

Rows with the same group number (`RES4840-1` … `RES4840-15`) and the same outcome (booking or past stay) become one group booking, one unit per row, with the booking form's default billing mode (Master folio pays). Each row's guest is on its unit; the first is the booker. A guest of the property with the same name is reused. A booking with a company is checked against its credit limit; the page reason passes the check for a user with `company.credit-override`. The group name is the company, or the booker for a group without one.

## Price, plan and deposit

- Each unit is booked on the room type's **enter price** plan (the first Per room or Per guest plan open to everyone, as the booking form's "No plan · enter price"), with the file's total as the override total and "Old system price ({res no})" as the reason. A per-pax total stays exact.
- Restrictions are overridden with the page reason (the user needs `rates.override-restrictions` when a stay breaks one). Availability is still checked.
- A deposit becomes an **Old system** payment (Advance) on the booking, reference `{res no}`, dated the business date. Only inside the [migration window](../17-onboarding/07-migration.md#migration-window).
- A unit with a room keeps it. A unit without a room stays unassigned (no auto-assign).

## Problems

A row with a problem blocks the import. The preview shows each problem on its row.

| Problem                              | When                                                                 |
| ------------------------------------ | -------------------------------------------------------------------- |
| Missing value                        | `res_no`, `guest`, `arrival`, `departure`, `adults` or `total` empty |
| Unknown room / room type             | No match                                                             |
| Room type and room disagree          | The room belongs to another type                                     |
| No enter-price plan                  | The room type has no Per room or Per guest plan open to everyone     |
| Unknown company / source             | No match                                                             |
| Boat time has no departure           | No active departure of that direction at that time                   |
| Past stay without a room             | Arrival before the business date and no room                         |
| Before go-live                       | Arrival before the go-live date                                      |
| Deposit outside the migration window | A deposit while the window is closed                                 |
| Duplicate res no                     | The same `res_no` twice in the file                                  |

A row whose group number is already the external reference of a reservation is shown as **Already imported** and skipped, so a newer export adds only new bookings. Refusals at import (sold out, restriction without permission, over occupancy) roll everything back and name the row.

## Code

| Class                                          | Role                                                                                                  |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `ArrivalImportReader` (Services/Reservations)  | Reads the file into raw rows (eZee or estatell columns)                                               |
| `ArrivalImportPlanner` (Services/Reservations) | Matches rows to rooms, sources, companies, departures; decides what each row becomes and its problems |
| `ImportArrivals` (Actions/Reservations)        | One transaction: `CreateBooking` or `CreatePastStay` per group, then `RecordPayment` for deposits     |
| `ArrivalImportController`                      | `create`, `preview`, `store`, `template`                                                              |

A past stay is entered without the registration card rule for a user with `front-desk.check-in-without-card`. Each import logs `reservation.arrivals_imported` on the property with the file name, the counts and the reason; each booking keeps its own creation log.
