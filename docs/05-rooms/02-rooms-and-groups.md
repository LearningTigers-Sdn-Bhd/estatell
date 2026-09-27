# Rooms and room groups

## Purpose

Physical rooms, labelled the way the property labels them, and grouped the way the property thinks about them (for example "Left wing").

## Data model

```
room_groups    id, property_id, name, position, timestamps
rooms          id, property_id, room_type_id, room_group_id (nullable),
               number, floor (nullable), position, archived_at, timestamps
               unique (property_id, number)
               check: number is trimmed and not empty
```

## Rules

- `rooms.number` is a free-text label ("201", "201A", "Villa 3"). It is the only place the label is stored.
- Every other table references `rooms.id`. Renaming a room updates everywhere.
- Groups filter the room grid, the housekeeping board and room assignment.
- `floor` is a plain column. Use groups for wings or blocks. No nested groups.
- Archive rooms instead of deleting them, because history references them.

## Actions

- `CreateRoomGroup`, `UpdateRoomGroup`
- `GenerateRooms`: creates rooms from a pattern, for example "101-110" or "Floors 1-3, rooms 01-08". Used in onboarding and settings.
- `ArchiveRoom`

## Lessons from wastays

wastays copied the `room_number` string into `room_locks`, `room_blocks`, `room_statuses` and `booking_rooms` next to `room_id`. The copies went stale on rename.
