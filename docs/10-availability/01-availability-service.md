# Availability service

## Purpose

Answer "how many units can we still sell for this room type on this date?" from the source data, without stored copies.

## Service

`AvailabilityService`:

- Room types with `sell_unit = Room`: count active rooms.
- Room types with `sell_unit = Bed`: count active beds.
- Subtract overlapping `unit_nights` of active reservation units.
- Subtract overlapping open `room_blocks` (`completed_at` null). A whole-room block removes the room, or all its beds for a Bed room type. A bed block removes that bed.
- Apply `rates.stop_sell` and restrictions for sellability.

Date overlap uses `spatie/period`.

## Rules

- No stored availability table and no `available_room_numbers` jsonb.
- Overbooking is allowed only for channel bookings, and is flagged on the night audit readiness panel.
- The channel manager reads availability from this service when sending (see [ARI sync](../21-channels/04-ari-sync.md)).

## Future

Cache per room type and date range if queries get slow. Postgres exclusion constraints can prevent double assignment of the same room or bed on the same night.

## Lessons from wastays

`room_inventories.available_room_numbers` stored jsonb lists that could drift from bookings.
