# Check-in and check-out

## Purpose

Move a unit through its stay.

## Actions

- `CheckInUnit`: requires an assigned room or bed for tonight, enforces the registration card scope, posts undated add-ons, sets `checked_in_at` and `UnitStatus::CheckedIn`.
- `CheckOutUnit`: requires the unit folio balance to be 0 or transferred to the master folio, sets `checked_out_at`, `UnitStatus::CheckedOut`, marks the room Dirty.
- `ProcessEarlyDeparture`: applies the early departure [room revenue policy](../06-catalogues/06-room-revenue.md#policies) when a stay is shortened.
- `ExtendStay`, `ChangeUnitDates`.
- `ExtendDepartureTime`: late check-out on the departure date. Sets a later `departure_time` and posts the late check-out policy charge. Refused unless the unit is CheckedIn, on its departure date, and the new time is later and before midnight.
- `MarkNoShow`, `CancelUnit`: post the no-show or cancellation policy charge, unless it is waived.
- `ReinstateUnit`: moves a Cancelled or NoShow unit back to Confirmed and re-prices it from `reservation_unit_children`.
- Each of these Actions calls `SyncReservationStatus` (module 12) after it changes a unit, so the reservation status follows its units. `ResolveMissedArrivals` in the night audit calls `MarkNoShow` for each unit its policy marks.

## Rules

- Each transition is one Action, checked by `UnitStatus::canTransitionTo()`.
- Group actions ("check in all") loop over units.
- Credit exposure is checked at check-in for company-billed reservations.

## Permissions

`front-desk.check-in`, `front-desk.check-out`, `front-desk.check-in-without-card`, `front-desk.waive-fees`.

## Build order

One checklist item per group, in this order. The Actions come first and the reservation page forms follow.

1. `CheckInUnit` and `CheckOutUnit`, with the three permissions.
2. The room revenue policies, then `CancelUnit`, `MarkNoShow` and `ReinstateUnit`.
3. `ExtendStay`, `ChangeUnitDates`, `ProcessEarlyDeparture` and `ExtendDepartureTime`.
4. The unit **⋯** menu on the [reservation page](../12-reservations/06-reservation-page.md#units-tab), with the forms below.

## Forms on the reservation page

Each form uses `FormOverlay` and the container from [Form containers](../design-system/05-form-containers.md).

| Action                                         | Container   | Fields and notes                                                                          |
| ---------------------------------------------- | ----------- | ----------------------------------------------------------------------------------------- |
| Check in                                       | Dialog      | Shows the room, the registration card status and a card override (permission)             |
| Check out                                      | Dialog      | Shows the folio balance. Settle, or transfer to the master folio                          |
| Cancel unit, Mark no-show                      | Dialog      | Shows the policy fee ("No charge" inside the free window), Waive fee, a reason            |
| Reinstate                                      | AlertDialog | Re-prices the unit. Shows the new total                                                   |
| Change dates (also Extend and Early departure) | Dialog      | Dates and a live price difference. A shorter stay shows the early departure policy result |
| Late check-out                                 | Dialog      | New time, the policy fee, Waive fee, a reason                                             |

Waive fee shows only for staff with `front-desk.waive-fees`. The fee is read-only text from the server.
