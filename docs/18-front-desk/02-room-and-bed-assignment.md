# Room and bed assignment

## Purpose

Put units into physical rooms or beds, per night.

## Actions

- `AssignRoom` / `AssignBed`: sets `room_id` or `bed_id` on the unit's nights. The whole-stay version ships with the [booking form](../12-reservations/05-booking-form.md#room--bed-column) in module 12.
- `MoveUnit`: changes the room or bed from a given night onward. It takes the first night, the room or bed and a reason. The reason is required for a move and optional for a first assignment, when the first night has no room or bed yet.
- `AutoAssignRooms`: gives a unit with no room or bed a free one for the whole stay. With `property_settings.auto_assign_rooms` on (the default), it runs when a booking is saved, not only at check-in (decided 2026-10-27, as in wastays). `AutoAssignBookedUnit` is the entry point for bookings: it reads the setting and runs `AutoAssignRooms` only for a Confirmed overnight unit with a night that has no room or bed. `AddUnit` returns the added unit (`ReservationResult::$unit`) for it.
    - `CreateBooking` runs it after the staff picks, for each overnight unit still without a room or bed. A staff pick always wins. A past or migrated stay (`$allowPastArrival`) is skipped, and so is the wastays replay (`$autoAssignRooms: false`), which sets its own rooms.
    - `IngestChannelBooking` runs it for each unit of a new OTA booking and for each unit a Modified revision adds or replaces. After a Modified date change, a Confirmed unit with a night left without a room (`unassignedNights` > 0) runs it for the whole stay, so the unit moves to one free room; with none free the unit stays as it is. A CheckedIn unit is left alone.
    - The reservation page's **Add unit** runs it after `AddUnit`.
    - `CheckInUnit` still runs it when tonight has no room, as the last chance.
    - A unit still without a room shows **Auto** in the room box, with the tooltip "No free room when booked. Check-in assigns one."
    - Nothing free is not a refusal: the booking saves with no room, shows in the Unassigned lane, and check-in tries again. Assignment never refuses or rolls back a booking.
    - A day use unit is skipped: its room is picked in the form.

## Rules

- Assignment is per night on `unit_nights`.
- A room or bed cannot be assigned twice on the same night. `AssignRoom`, `AssignBed` and `MoveUnit` lock the unit and the room type and check the nights. Two partial unique indexes on `unit_nights` enforce it in the database: `(room_id, date)` and `(bed_id, date)`, both where the column is not null.
- A cancelled or no-show unit releases its room or bed by clearing `room_id` and `bed_id` (`ReleaseUnitRooms`), so the indexes need no extra flag. A checked-out unit keeps its past nights.
- An in-house unit without a room or bed is a hard blocker for the night audit.
- `MoveUnit` runs on a Confirmed or Checked-in unit. The first night must be inside the stay and not before the business date. A reason is required unless the first night has no room or bed yet (a first assignment). Earlier nights keep their room.
- `MoveUnit` to another room type is allowed only when that room type is linked to the unit's rate plan (`rate_plan_room_types`) and has the same sell unit. Because a unit has one room type, it must start on the arrival night, and it changes `room_type_id`. The nights keep their booked prices. A price change is a separate step (price override or discount).
- `AutoAssignRooms` tries the free rooms or beds in this order: room group position, room position, then bed position. A Dirty room comes after a Clean or Inspected one. Each try goes through `AssignRoom` or `AssignBed`, so a pick that was taken meanwhile is skipped. With nothing free it returns `no_free_unit`. A booking save ignores that; check-in is refused as `no_room_assigned`.
- Refusal codes: `unit_not_open`, `reason_required`, `night_invalid`, `night_in_past`, `room_not_found`, `bed_not_found`, `room_not_free`, `bed_not_free`, `room_type_not_allowed`, `whole_stay_required`, `unchanged`, `no_free_unit`.

## UI

**Move room** (**Move bed** for a bed unit) is a Dialog in the unit **⋯** menu on the reservation page. It uses `FormOverlay` and has a first night, a room type (only when the plan sells more than one), the free room or bed and a reason. When the first night has no room or bed yet, the title and the button read **Assign room** (**Assign bed**) and the reason is optional. The list comes from `reservations.units.move-preview`, which reads the free rooms or beds from the first night to the departure and leaves out the unit itself. The menu shows the action for a Confirmed or Checked-in unit before its departure date, to users with `reservations.manage`. Assigning a room to a unit that has none uses the same Dialog.
