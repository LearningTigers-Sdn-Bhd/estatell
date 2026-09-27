# Room blocks and room statuses

## Purpose

Take rooms out of sale (maintenance, owner use) and track their housekeeping state.

## Data model

```
room_blocks      id, property_id, room_id, bed_id (nullable), reason (RoomBlockReason),
                 starts_on, ends_on, notes, created_by, completed_at, timestamps
room_statuses    id, property_id, room_id (unique), status (RoomStatus),
                 is_priority, priority_note, do_not_disturb_on (nullable),
                 assigned_to (nullable), last_changed_by, last_changed_at, timestamps
```

## Enums

- `RoomBlockReason`: Maintenance, OwnerUse, OutOfOrder, Other.
- `RoomStatus`: Clean, Dirty, Inspected, OutOfService.

## Rules

- Blocks reduce availability for their dates.
- No `room_number` copies. Only `room_id`.
- The night audit marks occupied and departed rooms Dirty (see [Audit run](../20-night-audit/03-audit-run.md)).

## Future

A Postgres exclusion constraint can prevent overlapping blocks on the same room.
