# Room and bed assignment

## Purpose

Put units into physical rooms or beds, per night.

## Actions

- `AssignRoom` / `AssignBed`: sets `room_id` or `bed_id` on the unit's nights. The whole-stay version ships with the [booking form](../12-reservations/05-booking-form.md#room--bed-column) in module 12. This module adds the Room & Rate editing around it.
- `AutoAssignRooms`: when `property_settings.auto_assign_rooms` is on, assigns free clean rooms or beds by room group and position.
- `MoveUnit`: changes the room or bed from a given night onward.

## Rules

- Assignment is per night on `unit_nights`.
- A room or bed cannot be assigned twice on the same night. The whole-stay `AssignRoom` / `AssignBed` from module 12 lock the room type and check the nights. This module adds the Postgres constraint that enforces it, together with the way a cancelled or no-show unit releases its room or bed (for example a nullable `holds_inventory` flag on `unit_nights` that the partial unique indexes use).
- An in-house unit without a room or bed is a hard blocker for the night audit.
- `MoveUnit` to another room type is allowed only when that room type is linked to the unit's rate plan (`rate_plan_room_types`).

## UI

**Move room** is a Dialog (room or bed, first night to move, reason) in the unit **⋯** menu on the reservation page. It uses `FormOverlay`.
