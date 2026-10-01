# Room and bed assignment

## Purpose

Put units into physical rooms or beds, per night.

## Actions

- `AssignRoom` / `AssignBed`: sets `room_id` or `bed_id` on the unit's nights. The whole-stay version ships with the [booking form](../12-reservations/05-booking-form.md#room--bed-column) in module 12. This module adds the Room & Rate editing around it.
- `AutoAssignRooms`: when `property_settings.auto_assign_rooms` is on, assigns free clean rooms or beds by room group and position.
- `MoveUnit`: changes the room or bed from a given night onward.

## Rules

- Assignment is per night on `unit_nights`.
- A room or bed cannot be assigned twice on the same night. A Postgres exclusion or unique constraint enforces this.
- An in-house unit without a room or bed is a hard blocker for the night audit.
- `MoveUnit` to another room type is allowed only when that room type is linked to the unit's rate plan (`rate_plan_room_types`).
