# Room status board

## Purpose

Show housekeeping which rooms need work on the open business date, and let staff update a room in one tap. The board keeps the layout of the wastays Housekeeping Tasks table, with fewer steps and no status that can be refused after it is picked.

## Data model

Uses `room_statuses` (see [Room blocks and statuses](../05-rooms/04-room-blocks-and-statuses.md)) plus the units that arrive, depart or stay on the date. This module adds two columns to `room_statuses`:

```
room_statuses (adds)
  remark              varchar(500), null      written by staff, plain text
  needs_maintenance   bool, default false
```

The system reason (see below) is not stored. It is worked out from the room and its units each time the board loads.

## Rows

- One row for each **active room** (archived rooms are left out) for the open business date (`CurrentBusinessDate`, or `PropertyToday` before business dates exist). There is no date picker, because only today can be worked on.
- The server sends every row field. The browser sorts, filters and groups the rows it holds.

| Field              | Source                                                                                           |
| ------------------ | ------------------------------------------------------------------------------------------------ |
| Room, group, type  | `rooms`, room group, room type                                                                   |
| Status             | `room_statuses.status`, or **Out of service** when an open block covers the date                 |
| Assigned           | `room_statuses.assigned_to`                                                                      |
| Guest              | The primary guest of the unit that departs or stays in the room, else the one that arrives today |
| Arrival, Departure | The unit that arrives today and the unit that departs today: date and expected time (not actual) |
| Flags              | `is_priority`, `do_not_disturb_on` (today), `needs_maintenance`                                  |
| Remark, reason     | `remark` written by staff, and the derived system reason                                         |

A room can have a departure and an arrival on the same day. Both show, each in its own column.

## Status

The status enum is `HousekeepingStatus`: Dirty > Clean > Inspected. Any room can become Dirty again (`canTransitionTo()`).

| State          | Badge tone      | Menu moves to    | Who can pick it                                                 |
| -------------- | --------------- | ---------------- | --------------------------------------------------------------- |
| Dirty          | Warning (amber) | Clean            | `housekeeping.update`                                           |
| Clean          | Success (green) | Dirty, Inspected | `housekeeping.update`, and `housekeeping.inspect` for Inspected |
| Inspected      | Info (blue)     | Dirty            | `housekeeping.update`                                           |
| Out of service | Danger (red)    | none             |                                                                 |

- Every badge shows an icon and the word, so colour is never the only signal. The tones come from `HousekeepingStatus::tone()`; Out of service uses `Tone::Danger` in the board only.
- The badge is the control: it opens a dropdown that lists only the legal moves (`canTransitionTo()` and the viewer's permissions). The server sends them as `moves`, so the browser holds no rules. Each option is drawn as a badge in its own tone, so the menu matches the badge that will replace the current one. A room with no move (Out of service, or a user without `housekeeping.update`) shows a plain badge.
- The menu never offers a status the room cannot take, so a refusal is rare. A refusal (a race, a room blocked a moment ago) shows as a plain sentence in a toast and the row goes back to its old state.

| Refusal code         | Sentence (English)                   |
| -------------------- | ------------------------------------ |
| `out_of_service`     | Room 203 is out of service.          |
| `invalid_transition` | Room 102 cannot move to that status. |

Sentences come from `lang/en.json` and `lang/ms.json`. A repeated click on a room that is already in the wanted status is not an error.

## System reason

`HousekeepingReason` is derived, read only and shown in grey under the remark while the room is **Dirty**:

| Reason    | When                                                     |
| --------- | -------------------------------------------------------- |
| Departed  | A unit checked out of the room on the business date      |
| Stay-over | A checked-in unit sleeps in the room and does not depart |

It disappears when the room is Clean, so it can never be stale. For an Out of service room the grey line shows the block reason and its notes instead.

## Remark

- One remark for each room, plain text, 500 characters. Click the cell, type, and it saves when the cell loses focus. An empty value removes it.
- A remark is **kept until someone removes it**. Making the room Clean does not clear it.
- `SetRoomRemark` is the only writer, and the activity log keeps the old and new value.

## Needs maintenance

- A flag for "something is broken here" that housekeeping can raise without `rooms.block`. It shows as an amber **Maintenance** tag and prints as `Maint.` in the Flags cell.
- Raise: `housekeeping.update`. Dismiss: `rooms.block` (Owner, Manager, Front desk).
- `CreateRoomBlock` clears the flag of the room it blocks (it calls `SetNeedsMaintenance`, not the model).
- The flag does not change the status or the availability.

## Maintenance blocks on the board

An open `room_blocks` row that covers the business date makes the room **Out of service**. The board only reads it.

- The red badge replaces the status badge. The status cell also shows the reason and the last night, for example "Maintenance · until 18 Oct", and the block notes show as the grey line.
- The stored room status stays under the block and comes back when the block ends.
- A **bed block** does not take the room out of service. The room row stays workable and shows a note, for example "Bed A3 out of order until 21 Oct".
- The row ⋯ menu has **Block room** for a user with `rooms.block`. It opens the Room blocks sheet and calls `CreateRoomBlock`. Ending a block stays on the Room blocks page.
- Out of service rows have no tick box, and the bulk bar skips them.

## Views, filters and order

| Part    | Behavior                                                                                                                                                                                                                              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| View    | All, Grouped by room group, Grouped by room type. A group header shows the room count and the Dirty count, and can be collapsed.                                                                                                      |
| Filters | Status (including Out of service), Assigned (including Unassigned), and Arrivals today, Departures today, Stayovers.                                                                                                                  |
| Sort    | Default **due**: departures by `departure_time`, then arrivals by `arrival_time`, then other rooms by room number. With boats, the boat order (see the checklist item "order rooms by expected times"). A column header overrides it. |
| Browser | The view and collapsed groups are remembered in the browser. They are not saved on the server and are not part of the report layout.                                                                                                  |

The columns of the table (order, visibility, orientation) are the property layout in [Print, export and layout](02-print-and-layout.md).

## Bulk bar

`BulkActionBar` (`resources/js/components/pms/bulk-action-bar.tsx`) floats at the bottom while rows are ticked. A group header ticks all its rooms.

| Bulk action       | Calls                 | Notes                                             |
| ----------------- | --------------------- | ------------------------------------------------- |
| Assign to         | `AssignHousekeeper`   | Housekeepers are staff with `housekeeping.update` |
| Mark clean        | `UpdateRoomStatus`    | Only Dirty rooms                                  |
| Mark inspected    | `UpdateRoomStatus`    | Only Clean rooms, needs `housekeeping.inspect`    |
| Priority          | `SetRoomPriority`     | Set or clear, with an optional note               |
| Add remark        | `SetRoomRemark`       | Appends on a new line, cut at 500 characters      |
| Needs maintenance | `SetNeedsMaintenance` | Set only                                          |
| Print selected    | the PDF route         | The selected room ids are the row keys            |

`BulkUpdateRooms` runs the single-room Action for each room. It is not one transaction. A refused room is skipped and does not stop the others. The result lists the rooms done and the rooms skipped with their refusal code, and the bar shows "5 done, 1 skipped (203 is out of service)".

## Actions

All in `app/Actions/Housekeeping/`. Each logs activity and returns a result for an expected refusal (see the Actions rule).

| Action                | Does                                                                              | Logs                                                      |
| --------------------- | --------------------------------------------------------------------------------- | --------------------------------------------------------- |
| `UpdateRoomStatus`    | Moves a room to a legal next status, sets `last_changed_by` and `last_changed_at` | `room_status.changed`                                     |
| `AssignHousekeeper`   | Sets or clears `assigned_to`                                                      | `room_status.assigned`                                    |
| `SetRoomPriority`     | Sets `is_priority` and `priority_note`                                            | `room_status.priority_set`                                |
| `SetDoNotDisturb`     | Sets or clears `do_not_disturb_on` (the business date)                            | `room_status.dnd_set`                                     |
| `SetRoomRemark`       | Sets or clears `remark`                                                           | `room_status.remark_set`                                  |
| `SetNeedsMaintenance` | Sets or clears `needs_maintenance`                                                | `room_status.maintenance_flagged`, `.maintenance_cleared` |
| `BulkUpdateRooms`     | Runs the Actions above for many rooms and collects the result                     | one entry for each room                                   |

`HousekeepingBoardBuilder` (`app/Services/Housekeeping/`) reads the rows. It never writes.

## Rules

- The night audit marks occupied rooms Dirty (see [Audit run](../20-night-audit/03-audit-run.md)).
- Check-out marks the room Dirty.
- A room that is Out of service refuses a status change. The bulk bar skips it.
- Every query is scoped by `property_id`, and a room id from another property is a 404.

## Permissions

| Permission                      | Guards                                                                      | Owner | Manager | Front desk | Housekeeping |
| ------------------------------- | --------------------------------------------------------------------------- | ----- | ------- | ---------- | ------------ |
| `housekeeping.view`             | The board, print and export                                                 | ✓     | ✓       | ✓          | ✓            |
| `housekeeping.update`           | Mark clean or dirty, assign, priority, DND, remark, raise Needs maintenance | ✓     | ✓       | ✓          | ✓            |
| `housekeeping.inspect`          | Mark a room Inspected                                                       | ✓     | ✓       | ✓          | –            |
| `housekeeping.manage-checklist` | The pen box items in settings                                               | ✓     | ✓       | –          | –            |

Each module adds its permissions with a syncing migration (see [Roles and permissions](../03-staff/02-roles-and-permissions.md#seeding-and-syncing)).

## UI

Front desk context > Housekeeping (`housekeeping.index`, `/p/{code}/housekeeping`).

```
Housekeeping Tasks · Mon 12 Oct                          [ Columns ] [ Export v ]
View: ( All ) ( Grouped by room group ) ( Grouped by room type )   Status v  Assigned v  Arr · Dep · Stay

 ☐  Room  Status               Assigned  Arrival       Departure     Flags   Remarks
 ▼ Floor 1                                                   6 rooms · 2 dirty
 ☑  101   [● Dirty ⌄]         Siti      12 Oct 14:00  12 Oct 11:00  ! Maint Extra towels
                                                                              ↳ Departed
 ☐  102   [● Clean ⌄]         Aminah    –             –                     Add remark…
 ☐  103   [● Inspected ⌄]     Aminah    –             –
 –  203   [● Out of service]  –         Maintenance · until 18 Oct            Aircon

 ╔═ 2 selected  [ Assign to v ] [ Mark clean ] [ Mark inspected ] [ Priority ] [ Add remark ] [ Print selected ] [ Clear ] ═╗
```

- The status badge menu and the remark cell update at once (an optimistic update) and go back if the server refuses, with the sentence in a toast. The Inertia v3 optimistic API is checked with `search-docs` before the build.
- **Columns** shows only for users with `settings.manage`. **Export** has Print sheet (PDF), Excel and CSV.
- On a narrow screen the table keeps Room and Status, and the rest moves into the row detail.

## Out of scope

- Guest housekeeping requests and complaint workflows from wastays.
- Per-item digital ticks on a checklist, and a gate on Inspected. The printed boxes are ticked with a pen.
- A corridor mobile app, and per-user column layouts.
