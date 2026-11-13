# Room blocks and room statuses

## Purpose

Take rooms and beds out of sale (maintenance, owner use) and track the housekeeping state of each room.

## Data model

```
room_blocks      id, property_id, room_id, bed_id (nullable), reason (RoomBlockReason),
                 starts_on, ends_on, notes (max 500), created_by, completed_at, timestamps
                 check: ends_on >= starts_on and ends_on - starts_on < 365
                 check: notes are not blank when reason = other
room_statuses    id, property_id, room_id (unique), status (HousekeepingStatus, default clean),
                 is_priority, priority_note, do_not_disturb_on (nullable),
                 assigned_to (nullable), last_changed_by (nullable), last_changed_at,
                 remark (varchar 500, nullable), needs_maintenance (bool, default false), timestamps
```

## Enums

- `RoomBlockReason`: Maintenance, OwnerUse, OutOfOrder, Other.
- `HousekeepingStatus`: Clean, Dirty, Inspected. Housekeeping moves Dirty > Clean > Inspected, and any room can become Dirty (`canTransitionTo()`).

The status enum is `HousekeepingStatus`, not `RoomStatus`, because the `RoomStatus` model holds the `room_statuses` row.

## Out of service

"Out of service" is not a stored status. A room or bed is out of service while it has an open block that covers the date. The stay view and the housekeeping board show it from the block. There is one source of truth, so a status and a block can never disagree.

## Block rules

- `starts_on` and `ends_on` are the first and last blocked **night**. Both are included. A one-night block has `starts_on = ends_on`.
- A block is **open** while `completed_at` is null. Only open blocks count.
- Blocks reduce availability for their nights. See [Availability service](../10-availability/01-availability-service.md).
- The first night cannot be in the past. "Today" is the property's local date (`PropertyToday`, from the property time zone). It switches to `CurrentBusinessDate` when business dates exist.
- A block covers at most 365 nights.
- A block needs an active room. A bed block needs an active bed of that room. Only rooms of a Bed room type have beds to pick. Without a bed, the whole room is blocked, including all its beds.
- Overlaps are refused: a whole-room block clashes with any open block in the room. A bed block clashes with a whole-room block and with a block on the same bed. Blocks on two different beds never clash. Back-to-back blocks (one ends on the 14th, the next starts on the 15th) are fine. `RoomBlockConflicts::first()` (a Service) finds the earliest clashing block under a lock on the room row.
- A block cannot cover a night that holds a stay. A whole-room block clashes with a night assigned to the room or to any of its beds. A bed block clashes with a night assigned to that bed or to the whole room. Only units that hold inventory count (not Cancelled or No-show). A day use holds no night, so it does not clash. `RoomAssignedNights::firstBetween()` finds the earliest such night inside the same lock, on create and on edit. Move the stay first, then block the room.
- Notes are required when the reason is Other.
- No `room_number` copies. Only `room_id` and `bed_id`.

## Changing a block

| Block state                                   | Edit                                          | End now | Cancel |
| --------------------------------------------- | --------------------------------------------- | ------- | ------ |
| Upcoming (`starts_on` after today)            | Reason, nights, notes                         | –       | ✓      |
| In progress (covers today)                    | Reason, last night, notes (first night stays) | ✓       | –      |
| Past (ended early, or `ends_on` before today) | –                                             | –       | –      |

- The room and bed of a block never change. Cancel or end it and create a new one.
- **End now** sets `completed_at` and keeps the row and its dates for history. For a whole-room block, staff choose whether the room is **Dirty** or **Clean**, because only they know how the work left the room. That value becomes the room status (`last_changed_by`, `last_changed_at` set). Ending a bed block leaves the room status as it is.
- **Cancel** deletes a block that has not started. The activity log keeps its details.
- Archiving a room ends its current and upcoming open blocks, and so does archiving a bed. Availability then never subtracts a block for a unit that no longer counts. Past blocks are left as they are.

## Room status rules

- Every room has exactly one `room_statuses` row. `CreateRoom` and `GenerateRooms` create it as Clean. The migration backfilled a Clean row for every existing room, including archived rooms.
- The night audit marks occupied and departed rooms Dirty (see [Audit run](../20-night-audit/03-audit-run.md)).
- Status changes, assignees, priority, do-not-disturb, the remark and the `needs_maintenance` flag are managed on the [Room status board](../19-housekeeping/01-room-status-board.md). `remark` and `needs_maintenance` are added by module 19.
- `CreateRoomBlock` clears `needs_maintenance` of the room it blocks, by calling `SetNeedsMaintenance`.

## Actions

- `CreateRoomBlock`, `UpdateRoomBlock`, `EndRoomBlock`, `CancelRoomBlock`. Each logs activity (`room_block.created`, `.updated`, `.ended`, `.cancelled`).
- `CreateRoomBlocks` blocks several whole rooms for the same nights, reason and notes. It calls `CreateRoomBlock` once per room and is not one transaction: a refused room is skipped and the others are blocked. It returns a `RoomBlockBulkResult` (how many were done, and each skipped room with its refusal). Each block logs and fires `InventoryChanged` as a single block does, and the channel outbox merges the nights into one flush.
- `CreateRoomBlock` returns the block or a `RoomBlockRefused`, and `UpdateRoomBlock` returns null or a `RoomBlockRefused`. It holds a `RoomBlockRefusal`: `unavailable` (archived room or bed), `ended`, `blocked` (another open block) or `has_stay`. The Actions write nothing, and the controller turns the refusal into an error toast.
- The toast names the clash, so staff know what to do next:
    - `blocked`: "Room 2017 is already blocked from 20 Oct 2026 to 19 Nov 2026. To change those nights, edit that block." Staff who want a longer block edit the existing one instead of adding a second.
    - `has_stay`: "Room 2017 has a stay on 16 Oct 2026 (booking {reference}). Move the stay first." It gives the first clashing night and its booking reference.
- `ArchiveRoom` and `ArchiveBed` end open current and upcoming blocks.
- Housekeeping Actions (`UpdateRoomStatus`, `AssignHousekeeper`, `SetRoomPriority`, `SetDoNotDisturb`, `SetRoomRemark`, `SetNeedsMaintenance`, `BulkUpdateRooms`) come with module 19. See [Room status board](../19-housekeeping/01-room-status-board.md#actions).

## Permissions

`rooms.block` opens the Room blocks page and creates, edits, ends and cancels blocks. By default Owner, Manager and Front desk have it.

## UI

Front desk > Room blocks (`front-desk.room-blocks.index`, `/p/{code}/front-desk/room-blocks`).

```
Room blocks                                             [+ Block room]
Current and upcoming (2)   Past                (tab links)
[Search by room, bed, room type or notes]   All room groups ⌄   All reasons ⌄
──────────────────────────────────────────────────────────────────────
Room          Reason        Nights                      Notes
203 Deluxe    Maintenance   12 Oct – 18 Oct · 7 nights  Aircon   In progress  Edit  End now
12 · Bed A3   Out of order  20 Oct – 21 Oct · 2 nights  —        Upcoming     Edit  Cancel
```

- One load: every current and upcoming block, the latest 50 past blocks, and the active rooms with their active beds. The search and filters work in the browser.
- The tabs are `TabLinks`: Current and upcoming is the page URL, Past is `?tab=past`. A refresh keeps the open tab, and the open tab is the last breadcrumb.
- The search matches the room number, bed, room type and notes.
- The table header always shows. With no blocks, one row holds the empty state: **Block room** on Current and upcoming, or a note on Past. When the search or filters hide every block, the row says so and offers **Clear filters**.
- **Block room** and **Edit** open a Dialog: rooms, bed, reason (full width), nights (`DateRangePicker`, two months side by side, one on a phone) and notes.
- **Rooms** is a `MultiSelectWithAll` with an **All rooms (N)** item. The form sends `room_ids`. The bed field shows only when one dorm room is picked (default "Whole room"); with two or more rooms each is blocked as a whole room, and the request refuses a `bed_id`. The button reads **Block N rooms**.
- One room keeps the single toasts (":unit blocked." or the refusal). Several rooms give "N rooms blocked." and, when some were skipped, "N skipped:" with up to three reasons, like the housekeeping bulk actions. The toast is an error when nothing was blocked and info when some were skipped.
- **Edit** keeps its one room and bed. The Timeline's **Block room** sends its one fixed room.
- **End now** opens a confirm dialog. For a whole-room block it asks "Room is Dirty / Clean" (default Dirty).
- **Cancel** opens a confirm dialog.
- The form is `RoomBlockForm` (`components/pms/room-block-form.tsx`). The Timeline's **Block room** cell action opens the same form in the same Dialog, with the room fixed. See [Empty cell](../18-front-desk/07-timeline-editing.md#empty-cell).
- Create, edit, end and cancel redirect back, so a block made from the Timeline returns to the Timeline.
- The housekeeping board's row menu **Block room…** links to this page for a user with `rooms.block`.

## Later

- A Postgres exclusion constraint can prevent overlapping blocks at the database level.
