# Rooms and room groups

## Purpose

Physical rooms, labelled the way the property labels them, and grouped the way the property thinks about them (for example "Floor 2" or "Left wing").

## Data model

```
room_groups    id, property_id, name, position, timestamps
               unique (property_id, lower(name))
rooms          id, property_id, room_type_id, room_group_id (nullable),
               number, position, archived_at, timestamps
               unique (property_id, lower(number)) where archived_at is null
               check: number is trimmed and not empty
```

## Rules

- `rooms.number` is a free-text label ("201", "201A", "Villa 3"). It is the only place the label is stored.
- Every other table references `rooms.id`. Renaming a room updates everywhere.
- A room group is the only grouping. It can be a floor, a wing or a block. There is no `floor` column and no nested groups.
- Groups filter the room grid, the housekeeping board and room assignment.
- Group names are unique per property, ignoring case.
- Room numbers are unique per property among active rooms, ignoring case. An archived room frees its number.
- A group with active rooms cannot be deleted. Deleting an empty group leaves its archived rooms without a group.
- Archive rooms instead of deleting them, because history references them. Restore is refused when an active room has the same number.
- A new room needs an active room type. An existing room may keep an archived room type.
- The `sell_unit` of a room type is locked once the room type has any room.

## Changing a room's type

A room changes type in three places: the room edit on Settings > Rooms (`UpdateRoom`), the Rooms tab of the [room type page](01-room-types.md#rooms-tab) (`AssignRoomTypeRooms`), and the onboarding [Rooms table](../17-onboarding/02-wizard-steps.md#rooms-table) (`SaveRoomsRequest`, then `UpdateRoom`). All apply the same guards:

- The new room type is active.
- A room with beds cannot move into a type that sells rooms.
- A room cannot change type while it, or one of its beds, has assigned unit nights, or an unreleased day use block (`unit_day_uses`), on or after the business date (`CurrentBusinessDate`). Move those stays first. Without this guard a booking for one type would sit in a room of another type.

One read-only Service holds the assigned-nights check, and both paths call it.

## Number generator

`GenerateRooms` creates many rooms in one step. `RoomNumberGenerator` (a Service) turns the input into numbers. It never writes.

| Mode   | Input                           | Result                                        |
| ------ | ------------------------------- | --------------------------------------------- |
| Range  | from `101`, to `110`            | 101 to 110, in the group you choose (or none) |
| Floors | floors `1`-`3`, rooms `01`-`08` | 101 to 108, 201 to 208, 301 to 308            |
| List   | `202-216, 218-234, Villa 3`     | 202 to 216, 218 to 234 and Villa 3            |

- The room part keeps its zero padding: `01` gives two digits.
- Optional prefix and suffix: prefix "Villa " gives "Villa 1", "Villa 2".
- Floors mode finds or creates one group per floor, named "Floor 1", "Floor 2".
- One room type per run. Run it again for other room types.
- At most 200 rooms per run.
- All or nothing: when any number is already used by an active room, nothing is created and the taken numbers are listed.
- The dialog shows a preview from the server. The preview lists the numbers and marks the ones already taken.

**List mode** (used by the [onboarding Rooms table](../17-onboarding/02-wizard-steps.md#rooms-table)):

- Parts are split by commas and trimmed. Empty parts are skipped.
- A part with a dash between two numbers is a range: `101-110`, `G1-G4`, `01-08`. The text before the first number is the prefix. The second end may repeat the prefix (`G1-G4`) or leave it out (`G1-4`). A different prefix is an error.
- A range keeps the zero padding of its from number (`01-08` gives `01` to `08`). A range that runs backwards is an error.
- Any other part is one label (`Villa 3`, `101A`).
- At most 200 numbers per list. A label repeated in the list is an error, ignoring case.
- A number is at most 30 characters (the `RoomRequest` limit), and a range end at most 6 digits (the range mode limit).
- The result keeps the typed order. `RoomNumberGenerator::parseList` returns the numbers or the first error with the part that caused it. It never writes.
- The reverse, `RoomNumberRanges`, joins a group's saved numbers into the shortest list of ranges (`102-134, 140`), so a saved group reads back as typed. Neighbours join when they share a prefix and padding and count up by one. A range repeats its prefix on both ends (`G1-G4`).

## Room order

- The onboarding save sets `position` to the typed order of each group's list, for new and saved rooms, so rooms read in the order of the floor sheet, not in the order they were created.
- Groups follow group position; within a property, room positions are rewritten in one query per save by `ReorderRooms`. It takes the active rooms in order (group by group, each in its typed order), puts them first and keeps every other room after them in its current order. It refuses an id that is not an active room of the property, or that is listed twice.
- `GenerateRooms` gives new rooms positions in the order of its plan, after the last position.
- Settings runs (range and floors) still add rooms after the last position, as before.
- Room order drives the onboarding room grid, the housekeeping board and `AutoAssignRooms` (group position, then room position).

## Actions

- `CreateRoomGroup`, `UpdateRoomGroup`, `ReorderRoomGroups`, `DeleteRoomGroup`
- `CreateRoom`, `UpdateRoom`, `ArchiveRoom`, `RestoreRoom`, `ReorderRooms`
- `GenerateRooms`
- `AssignRoomTypeRooms` (the Rooms tab of a room type: groups, and rooms moved in from other types)
- `ChangeRoomsType` (bulk Change type on Settings > Rooms)

## Permissions

`rooms.view` opens the page. `rooms.manage` creates, edits, generates, archives and restores rooms and manages groups.

## UI

Settings > Rooms (`settings.rooms.index`) lists rooms in one table. Buttons: New room, Generate rooms, New group. Rooms are listed by position (see [Room order](#room-order)).

- Each group starts with a full-width group row: name, room count, up and down buttons to reorder, Rename and Delete. On a phone, Rename and Delete sit in a `⋯` menu.
- Clicking a group name collapses or opens its rooms. Every group starts open except Archived. Nothing is remembered across reloads.
- A group without rooms shows "No rooms in this group.", so it can still be renamed, deleted or moved.
- Ungrouped comes after the groups, without controls, and only when it has rooms. A property without groups gets no group rows.
- Archived comes last as a closed group row, with Restore on each room. The status filter shows or hides it; choosing Archived opens it.
- Each active room row has a tick box for `rooms.manage`. Ticked rows show the `BulkActionBar` with **Change type** and **Archive**.
- **Change type** is a menu of active room types. `ChangeRoomsType` applies the guards of [Changing a room's type](#changing-a-rooms-type) to each room. It saves the rooms that pass and lists the refused ones by number in a toast ("2 rooms changed. 301 has beds. 305 has stays assigned from 20 Oct.").
- The group filter shows one group, or Ungrouped. The room type filter hides other rooms, and hides groups left with none. Generate rooms returns to the page it was opened from: Settings > Rooms, or the Rooms tab of a room type, where the room type is fixed.

## Lessons from wastays

wastays copied the `room_number` string into `room_locks`, `room_blocks`, `room_statuses` and `booking_rooms` next to `room_id`. The copies went stale on rename.
