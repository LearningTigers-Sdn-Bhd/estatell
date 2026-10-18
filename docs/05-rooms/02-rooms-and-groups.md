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

A room changes type in two places: the room edit on Settings > Rooms (`UpdateRoom`), and the Rooms tab of the [room type page](01-room-types.md#rooms-tab) (`AssignRoomTypeRooms`). Both apply the same guards:

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

- The room part keeps its zero padding: `01` gives two digits.
- Optional prefix and suffix: prefix "Villa " gives "Villa 1", "Villa 2".
- Floors mode finds or creates one group per floor, named "Floor 1", "Floor 2".
- One room type per run. Run it again for other room types.
- At most 200 rooms per run.
- All or nothing: when any number is already used by an active room, nothing is created and the taken numbers are listed.
- The dialog shows a preview from the server. The preview lists the numbers and marks the ones already taken.

## Actions

- `CreateRoomGroup`, `UpdateRoomGroup`, `ReorderRoomGroups`, `DeleteRoomGroup`
- `CreateRoom`, `UpdateRoom`, `ArchiveRoom`, `RestoreRoom`
- `GenerateRooms`
- `AssignRoomTypeRooms` (the Rooms tab of a room type: groups, and rooms moved in from other types)

## Permissions

`rooms.view` opens the page. `rooms.manage` creates, edits, generates, archives and restores rooms and manages groups.

## UI

Settings > Rooms (`settings.rooms.index`) lists rooms in one table. Buttons: New room, Generate rooms, New group. Rooms are listed in creation order.

- Each group starts with a full-width group row: name, room count, up and down buttons to reorder, Rename and Delete. On a phone, Rename and Delete sit in a `⋯` menu.
- Clicking a group name collapses or opens its rooms. Every group starts open except Archived. Nothing is remembered across reloads.
- A group without rooms shows "No rooms in this group.", so it can still be renamed, deleted or moved.
- Ungrouped comes after the groups, without controls, and only when it has rooms. A property without groups gets no group rows.
- Archived comes last as a closed group row, with Restore on each room. The status filter shows or hides it; choosing Archived opens it.
- The group filter shows one group, or Ungrouped. The room type filter hides other rooms, and hides groups left with none. Generate rooms returns to the page it was opened from: Settings > Rooms, or the Rooms tab of a room type, where the room type is fixed.

## Lessons from wastays

wastays copied the `room_number` string into `room_locks`, `room_blocks`, `room_statuses` and `booking_rooms` next to `room_id`. The copies went stale on rename.
