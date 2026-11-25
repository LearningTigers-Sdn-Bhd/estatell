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
| `PUT /p/{code}/transfers/{transfer}/assign`                                   | `transfers.assign`                           | `reservations.manage` |

A route, vehicle, driver, unit or transfer of another property is a 404.

## Not built yet

- Transfers on the booking form and the company portal (set them on the reservation page after booking).
- Shared vans grouped into runs, seat counts per van, and a printed or exported run sheet.
- Subcontractor payables.
