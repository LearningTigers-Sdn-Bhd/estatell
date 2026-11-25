# Land transfers

## Purpose

Resorts away from town (Sepilok, Kinabatangan, Kudat, Kundasang) pick guests up by road: from the airport, the bus terminal or a jetty, and back. Land transfers record, for each room, how its guests travel in and out by road, what it costs, and who drives. The **run sheet** lists each day's transfers for the drivers.

Built like [Boats](../24-boats/01-boat-settings.md), but on its own tables, so a property can use both (Kinabatangan: road to the jetty, then a boat). Decided with Tanini on 2026-11-23:

- Both own vans and drivers, and subcontractors. For a subcontractor, record only who drove (no payables yet).
- Three ways to charge: **Shared** (per person, on a shared van), **Private** (per vehicle) and **Included** (in the package, no charge).
- A flight number on each transfer.

## Feature flag

- Pennant feature `Transfers` (`App\Features\Transfers`, `#[Name('transfers')]`), off by default. A platform admin turns it on per property on the admin Features tab. `PlatformFeature::Transfers` ("Land transfers") lists it.
- With the feature off, the settings item, the reservation Transfers tab and the run sheet are hidden, and their routes return 404 (`transfers` middleware, as for Boats). Turning it off keeps all data.

## Data model

```
transfer_routes    id, property_id, direction (TransferDirection), name ≤ 60, place ≤ 120,
                   minutes int null, shared_charge_item_id null → charge_items (restrict),
                   private_charge_item_id null → charge_items (restrict), active (default true), timestamps
                   UNIQUE (property_id, lower(name)); CHECK minutes IS NULL OR minutes > 0

transfer_vehicles  id, property_id, name ≤ 60, plate ≤ 20 null, seats int null, active, timestamps
                   UNIQUE (property_id, lower(name)); CHECK seats IS NULL OR seats > 0

transfer_drivers   id, property_id, name ≤ 60, phone ≤ 30 null, subcontractor ≤ 60 null, active, timestamps
                   UNIQUE (property_id, lower(name))

unit_transfers     id, property_id, reservation_unit_id → reservation_units (cascade),
                   direction (TransferDirection), mode (TransferMode),
                   transfer_route_id → transfer_routes (restrict), pickup_time time,
                   flight_number ≤ 10 null, note ≤ 200 null,
                   transfer_vehicle_id null → transfer_vehicles (restrict),
                   transfer_driver_id null → transfer_drivers (restrict), timestamps
                   UNIQUE (reservation_unit_id, direction)

unit_transfer_add_ons  id, unit_transfer_id → unit_transfers (cascade),
                       unit_add_on_id → unit_add_ons (cascade), timestamps; UNIQUE (unit_add_on_id)
```

- A **route** is one way: In (to the property) or Out (from it). `place` is where the guest is picked up (In) or dropped (Out), such as "Sandakan Airport". `minutes` is the usual drive, shown on the run sheet.
- A route's two prices are charge items. The item's charging unit decides the quantity, so the shared item is normally Per guest and the private item Per stay.
- A **driver** with a `subcontractor` name is not on the staff. Leave it empty for own staff. Vehicles are only the property's own; a subcontractor's van is not recorded.
- **One transfer per room and direction**, like boat trips. A guest who travels apart is booked as a separate room.
- **No date column.** In is on the unit's arrival date, Out on its departure date, so a date change moves the transfer.
- `pickup_time` is when the driver collects the guest. It does not change the unit's arrival or departure time: a road transfer may be followed by a boat, which owns those times.
- No transfer row means "none" (own transport or not set).

## Enums

| Enum                | Cases (label)                                                           |
| ------------------- | ----------------------------------------------------------------------- |
| `TransferDirection` | In ("Transfer in"), Out ("Transfer out")                                |
| `TransferMode`      | Shared ("Shared"), Private ("Private vehicle"), Included ("In package") |

## Settings

Settings sidebar, **Reservations** group, after Boats: **Transfers** (icon `bus`, `settings.manage`). Three link tabs, each with its own URL: **Routes**, **Vehicles**, **Drivers**. Each is a table with an Available switch and an edit button, like the Boat list tab. Nothing is deleted; it is turned off.

| Tab      | Columns                                                               | Form                                                                                                        |
| -------- | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Routes   | Available, Direction, Name, Place, Drive, Shared price, Private price | Sheet: direction, name, place, minutes, two price selects (active fixed-price charge items, or "No charge") |
| Vehicles | Available, Name, Plate, Seats                                         | Dialog: name, plate, seats                                                                                  |
| Drivers  | Available, Name, Phone, Works for ("Own staff" or the subcontractor)  | Dialog: name, phone, subcontractor                                                                          |

`SaveTransferRoute`, `SaveTransferVehicle` and `SaveTransferDriver` add or change a row and log `transfer_route.created` or `.updated` (likewise `transfer_vehicle.*` and `transfer_driver.*`). The Available switch sends the full row with the new `active`; a form that sends no `active` keeps the row's state.

## Reservation Transfers tab

A tab after Facilities on the reservation page, shown when the feature is on. One row per room and direction:

```
Room            Direction      Type             Route                    Pick-up  Flight  Vehicle · Driver
Double · 104    Transfer in    Shared           Sandakan Airport → Hotel 10:30    AK 6231 Van 1 · Ali          ✎
Double · 104    Transfer out   Not set                                                                         ✎
```

- **✎** opens a Dialog: Type, Route (available routes of that direction), Pick-up time, Flight number and Note. Each Type option shows the price it adds on the picked route. Type **No transfer** removes the transfer (`ClearUnitTransfer`).
- A cancelled or no-show room shows only the transfers it kept.
- The flight number is stored in capitals and takes letters, digits and spaces ("AK 6231").
- Edit shows only with `reservations.manage` while the unit is open: In while Confirmed; Out while Confirmed or CheckedIn (`TransferAccess`).

### SetUnitTransfer

`(User $actor, ReservationUnit $unit, UnitTransferData $data): UnitTransferResult`. Locks the unit, then in one transaction writes the row, syncs the charge and logs `reservation.transfer_set` ("Transfer in: Shared, Sandakan Airport → Hotel at 10:30.").

| Check, in order                                                   | Refusal            |
| ----------------------------------------------------------------- | ------------------ |
| In: the unit is Confirmed. Out: Confirmed or CheckedIn            | `unit_not_open`    |
| The route is available, of the property and of the same direction | `route_not_found`  |
| The charge can be added (`AddUnitAddOn` accepts it)               | `charge_not_found` |

`ClearUnitTransfer` removes the row and its unposted charges, with the same unit check, and logs `reservation.transfer_cleared`.

### Charge

`SyncTransferCharge` works like the boat fare:

1. Removes the transfer's unposted charge rows. A posted row is kept, and the toast warns: "The old transfer charge is already posted. Reverse it on the folio if needed."
2. Adds the route's shared item (Shared) or private item (Private) as an undated add-on through `AddUnitAddOn`, and links the rows in `unit_transfer_add_ons`. Included adds nothing.
3. Adds nothing when the route has no item for that type ("charge by hand"), or when the unit's rate plan includes the item (`RatePlanIncludes`), so a package that includes the transfer is not charged twice.

Changing the vehicle or driver does not touch the charge.

## Run sheet

**Front desk › Transfers** (`transfers.index`, `reservations.view`, icon `bus`): one date (`?date=`, default the business date) with every transfer of open units on it, sorted by pick-up time.

```
Transfers   [‹] 24 Nov 2026 [›]
Pick-up  Direction     Route                      Guest (booking)          Pax  Flight   Type     Vehicle      Driver
10:30    Transfer in   Sandakan Airport → Hotel   Tan Wei Ling (TAN-0042)  2    AK 6231  Shared   [Van 1 ▾]    [Ali ▾]
12:00    Transfer out  Hotel → Sandakan Airport   Jones (TAN-0039)         4             Private  [Not set ▾]  [Ah Seng (Kinabatangan Tours) ▾]
```

- Rows are units arriving (In) or departing (Out) that day with status Confirmed, CheckedIn or CheckedOut. Cancelled and no-show units are left out.
- Pax is adults plus children.
- With `reservations.manage`, the Vehicle and Driver selects save at once (`AssignTransfer`, `PUT /transfers/{transfer}/assign`, logs `reservation.transfer_assigned`). They list available vehicles and drivers. Without it, they are text.
- **Export Transfer Manifest ▾** (PDF, CSV, Excel) shows when the date has transfers. **Columns** opens the shared Columns Sheet with `settings.manage`. See [Transfer manifest](#transfer-manifest).

## Transfer manifest

The run sheet for the drivers, as a file. It is **not a page**: staff export it from the run sheet, like the [Boat manifest](../24-boats/03-boat-manifest.md) from the Boat Trips board.

`ReportKey::TransferManifest` (`transfer-manifest`) sits in the [core reports](../22-reports/01-core-reports.md) registry with no page and no Reports menu entry, so the shared column layout (`ReportLayoutResolver`), PDF (`ReportPdf`, the shared `pdf/report.blade.php` with the letterhead) and spreadsheet (`ReportSpreadsheet`) serve it. One date, Landscape by default, needs the `Transfers` feature.

- **Rows** are the run sheet's transfers (`TransferRunSheet::query()`), in the same order: pick-up time, then id. The browser sends only the date (and row keys); the server reads the rows again.
- **Sections:** one per vehicle and driver, in the order of their first pick-up, titled "Van 1 · SA 1234 B · Ali" ("Vehicle not set" or "Driver not set" for a missing half). Transfers with neither set come last in **Vehicle and driver not set** (warning tone). Each subtotal reads "2 transfers · 7 pax". The PDF prints each section as its own table; the spreadsheet is flat.
- The total row sums adults, children and pax.
- **Guests:** Lead guest and Phone are the unit's primary guest, else its first guest, else the booker (the Boat manifest rule). They are empty for a user without `guests.view`, as on the run sheet.

| Column          | Key           | Default visible | Content                                                                |
| --------------- | ------------- | --------------- | ---------------------------------------------------------------------- |
| Pick-up         | `pickup`      | Yes             | "10:30"                                                                |
| Direction       | `direction`   | Yes             | Transfer in, Transfer out                                              |
| Route           | `route`       | Yes             | The route name                                                         |
| Place           | `place`       | Yes             | Where the guest is picked up (In) or dropped (Out)                     |
| Room            | `unit`        | Yes             | Room number or bed (first night for In, last night for Out), else type |
| Lead guest      | `lead_guest`  | Yes             | See Guests above                                                       |
| Phone           | `phone`       | Yes             | The lead guest's phone                                                 |
| Adults          | `adults`      | No              |                                                                        |
| Children        | `children`    | No              |                                                                        |
| Pax             | `total`       | Yes             | Adults + children                                                      |
| Flight          | `flight`      | Yes             | The flight number                                                      |
| Type            | `mode`        | Yes             | Shared, Private vehicle, In package                                    |
| Vehicle         | `vehicle`     | Yes             | "Van 1 · SA 1234 B"                                                    |
| Driver          | `driver`      | Yes             | "Ah Seng (Kinabatangan Tours)" for a subcontractor                     |
| Reservation no. | `reservation` | Yes             | Reservation number                                                     |
| Note            | `note`        | Yes             | The transfer note                                                      |

`TransferExportController` (`transfers.pdf`, `transfers.spreadsheet`) calls the shared services behind `transfers` and `reservations.view`, so front desk staff need no `reports.view`. The spreadsheet needs `format` (`csv` or `xlsx`). A transfer id of another property in `rowKeys` is dropped. The shared `reports.transfer-manifest` export routes also work, behind `reports.view`, and return 404 with the feature off (`EnsureReportIsAvailable`).

## Vans board

Built 2026-11-25 (Tanini 5.3 and 5.5). A **run** is the transfers that share a vehicle, a pick-up time and a route: one van trip. There is no runs table; a run follows from each transfer's vehicle and pick-up time.

- **Put on a van.** On the run sheet (List view), staff with `reservations.manage` tick transfers and press **Put on a van**. The Dialog sets one **Vehicle**, one **Driver** and, optionally, one **Pick-up time** for all of them, and warns "11 pax on 10 seats" before saving. `AssignTransferRun` runs `AssignTransfer` on each, so the same availability checks apply; a new time keeps the charge and logs `reservation.transfer_time_changed` ("Transfer in pick-up time 09:40 → 10:00."). One refusal rolls every change back. An id of another property is refused as `transfer_not_found`.
- **Vans view.** A List / Vans toggle on the run sheet. Vans shows one card per available vehicle (and any turned-off vehicle with a run): its runs by time with the end time (pick-up plus the longest route time), the route, pax against seats, the driver and the guests. A run over the seats shows its pax in `warning`. Two runs of one van that overlap in time each show "Overlaps another run of this van"; a run with no route time overlaps only a run at the same minute. A **No van yet** card lists the transfers without a vehicle. A van with no run reads "Free all day".
- The grouping is display only, in the browser (`resources/js/lib/transfer-runs.ts`, with unit tests). The Transfer manifest already prints one section per vehicle and driver.

## Routes

| Route                                                                         | Name                                         | Permission            |
| ----------------------------------------------------------------------------- | -------------------------------------------- | --------------------- |
| `GET /p/{code}/settings/transfers`                                            | `settings.transfers`                         | `settings.manage`     |
| `GET, POST /p/{code}/settings/transfers/routes`, `PUT …/{route}`              | `settings.transfers.routes.*`                | `settings.manage`     |
| `GET, POST …/settings/transfers/vehicles`, `PUT …/{vehicle}`                  | `settings.transfers.vehicles.*`              | `settings.manage`     |
| `GET, POST …/settings/transfers/drivers`, `PUT …/{driver}`                    | `settings.transfers.drivers.*`               | `settings.manage`     |
| `GET /p/{code}/reservations/{reservation}/transfers`                          | `reservations.transfers`                     | `reservations.view`   |
| `PUT, DELETE …/reservations/{reservation}/units/{unit}/transfers/{direction}` | `reservations.units.transfers.set`, `.clear` | `reservations.manage` |
| `GET /p/{code}/transfers`                                                     | `transfers.index`                            | `reservations.view`   |
| `PUT /p/{code}/transfers/run`                                                 | `transfers.run`                              | `reservations.manage` |
| `PUT /p/{code}/transfers/{transfer}/assign`                                   | `transfers.assign`                           | `reservations.manage` |
| `POST /p/{code}/transfers/pdf`, `POST …/transfers/spreadsheet`                | `transfers.pdf`, `transfers.spreadsheet`     | `reservations.view`   |

A route, vehicle, driver, unit or transfer of another property is a 404.

## Not built yet

- Transfers on the booking form and the company portal (set them on the reservation page after booking).
- Subcontractor payables.
