# Bed bookings

## Purpose

Sell dorm beds one by one.

## Shape

N beds = N units in one reservation, one occupant per unit.

## Rules

- The booking form shows "Beds: N" for Bed room types. `NewUnitData.beds` carries N, and `CreateReservation` calls `AddUnit` once per bed (see [Group bookings](02-group-bookings.md#createreservation)). Availability counts each bed.
- One guest per bed unit is enforced by `AttachGuest` in the [booking form](05-booking-form.md) item.
- Built with the [Group bookings](02-group-bookings.md) item. There is no separate bed item.
- Each bed is stored on `unit_nights.bed_id`.
- Each backpacker can check in, move bed, check out or be marked no-show independently.
- With EachPays, each unit gets its own folio.

## Build status

Built with `CreateReservation` and `AddUnit` (see [Group bookings](02-group-bookings.md)). Assigning a bed (`bed_id`) and `AttachGuest` come with the booking form.

## Out of scope

Selling a whole dorm as one unit.
