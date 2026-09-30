# Availability service

## Purpose

Answer "how many units can we still sell for this room type on this date?" from the source data, without stored copies.

## Service

`AvailabilityService`:

- Room types with `sell_unit = Room`: count active rooms.
- Room types with `sell_unit = Bed`: count active beds.
- Subtract overlapping `unit_nights` of active reservation units (not built yet, see below).
- Subtract overlapping open `room_blocks` (`completed_at` null). A whole-room block removes the room, or all its beds for a Bed room type. A bed block removes that bed.
- Stop-sell and restrictions come from `rates`, through `RestrictionCheck`.

Date overlap uses `spatie/period`.

## Built first slice

`AvailabilityService` in `app/Services/Availability/` reads only. `nights($roomType, $arrival, $departure)` returns one `NightAvailability` for each night from arrival up to, not including, departure: `total`, `blocked`, `booked` and `available`. `availableForStay()` returns the fewest units free on any night, or 0 for a stay with no nights.

- **Units:** a Room room type counts its active rooms. A Bed room type counts the active beds of its active rooms. Archived rooms and beds never count.
- **Blocks:** an open block (`completed_at` null) takes its nights off, both ends included. A whole-room block removes the room, or every active bed of it. A bed block removes that bed. A unit blocked twice on one night counts once. A bed block on a room type that sells rooms is ignored, and so is a block on an archived bed.
- **Overlap:** `spatie/period` (day precision, both ends included) finds the nights a block shares with the stay.
- **`available`** never goes below zero. `booked` above the free units is an overbooking, which the night audit readiness panel will flag.
- **Booked units are not counted yet.** `bookedUnits()` returns none until reservations exist. Module 12 replaces it with the `unit_nights` query. Nothing calls the service before then.
- **Not here:** stop-sell and the other restrictions belong to a plan and a room type, so `RestrictionCheck` reports them. Availability counts units per room type.
- **Queries:** a fixed number, however many rooms and blocks there are.

## Rules

- No stored availability table and no `available_room_numbers` jsonb.
- Overbooking is allowed only for channel bookings, and is flagged on the night audit readiness panel.
- The channel manager reads availability from this service when sending (see [ARI sync](../21-channels/04-ari-sync.md)).

## Future

Cache per room type and date range if queries get slow. Postgres exclusion constraints can prevent double assignment of the same room or bed on the same night.

## Lessons from wastays

`room_inventories.available_room_numbers` stored jsonb lists that could drift from bookings.
