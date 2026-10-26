# Timeline editing

## Purpose

Change stays straight from the [Timeline View](03-stay-view.md#timeline-view-screen): drag a bar to another room, drag its edges to change dates, and click an empty cell to book or block the room. Every write goes through the Actions the reservation page already uses, so the rules and refusals are the same.

## Gestures

| Gesture                                       | Units                    | Server                                                                                       |
| --------------------------------------------- | ------------------------ | -------------------------------------------------------------------------------------------- |
| Click an empty cell                           | —                        | `CellActions`: `reservations.new` with the room and night filled in, or `CreateRoomBlock`    |
| Drag a bar up or down                         | Confirmed and Checked-in | `reservations.units.move-preview`, then `MoveUnit`                                           |
| Drag a bar from the Unassigned lane to a room | Confirmed                | `MoveUnit` as a first assignment, so no reason                                               |
| Drag the right edge                           | Confirmed and Checked-in | `reservations.units.dates-preview`, then `ChangeUnitDates` (`ExtendStay` when later)         |
| Drag the left edge                            | Confirmed only           | `reservations.units.dates-preview`, then `ChangeUnitDates`                                   |
| Drag a bar sideways or diagonally             | Confirmed only           | `reservations.units.move-stay-preview`, then `reservations.units.move-stay` (`MoveUnitStay`) |

- A drag changes the room or the dates, never both, except the sideways and diagonal drag of a Confirmed unit.
- **A Confirmed bar drags free in both directions.** The whole columns the pointer moved since the drag began are the date shift: arrival and departure move by the same number of days. The row under the pointer is the room or bed.

| Confirmed drag           | What it does                                                                                                                                                            |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shift 0, another row     | A plain room move (`MoveUnit` and `TimelineMoveDialog`). **From** starts at the first movable night, so the whole stay moves. **From** can be changed to split the stay |
| Shift 0, same row        | Nothing                                                                                                                                                                 |
| Shift not 0, same row    | A sideways shift that keeps the room (`MoveUnitStay`)                                                                                                                   |
| Shift not 0, another row | Dates and room together (`MoveUnitStay`)                                                                                                                                |

- A **Checked-in** bar keeps the vertical lock (`lockToVerticalAxis`) and the night under the pointer as **From**. A Package plan bar and a bar cut by either edge of the window are also up or down only.
- With a shift, the dashed outline shows the new dates in the row under the pointer and no row is greyed out: the start-of-drag targets are for the current dates. A shift is dropped on a room or bed row only, so a drop in the Unassigned lane or on a group heading does nothing. A lane bar is Confirmed with no room, so a shifted drop on a room is the new dates and a first assignment.
- `MoveUnitStay` runs `ChangeUnitDates` and then `MoveUnit` to the target from the new arrival, in one transaction. A refusal from either rolls both back and writes nothing, not even activity rows (the refusal is raised inside the transaction and caught outside, as `UnitStayRefused` is). `MoveUnit` saying `unchanged` counts as done. Moving the arrival is a new booking of the unit, so it has no fee. Only a Confirmed unit is accepted (`invalid_status`).
- A room change needs a **Reason**. When every night is already in the target, a blank reason becomes "Stay dates moved on the Timeline". A unit with no room yet needs none (first assignment).
- The ARI outbox rows and the delayed flush job that `ChangeUnitDates` starts through `ReservationChanged` are written on the default database queue inside the transaction, so a rollback removes them too. A Redis queue would keep the job, and it would find no rows to send.
- `@dnd-kit/core` is already a dependency. The bars snap to half cells, as they are drawn (see [Half cells](03-stay-view.md#timeline-view-screen)).

## Who can drag

| Bar                                  | Drag                                                         |
| ------------------------------------ | ------------------------------------------------------------ |
| User without `reservations.manage`   | None. Click opens the peek sheet as before                   |
| Checked out, cancelled, no-show      | None                                                         |
| Day use marker, block bar            | None                                                         |
| Package plan unit                    | Up or down only (`package_plan`, `StayData::$isPackagePlan`) |
| Bar cut by either edge of the window | Up or down only                                              |
| Phone width (below `md`) or keyboard | None. The reservation page dialogs do the same work          |

## Past dates

"Today" is the **business date**, not the clock. Between midnight and the night audit, today is still the day before.

- Past columns have a light grey wash. They are not drop targets and an empty past cell takes no click.
- While dragging, a bar or edge stops at the business date. It does not follow the pointer into the past, so a drop never fails for a past date.

| Drag or drop                         | Confirmed                                       | Checked-in                                          |
| ------------------------------------ | ----------------------------------------------- | --------------------------------------------------- |
| Up or down, dropped on a past column | Cannot happen: no past nights                   | Moves from tonight. Past nights keep the old room   |
| Left edge into the past              | Stops at today                                  | No handle                                           |
| Right edge into the past             | Stops at today + 1                              | Stops at today: leaving today is an early departure |
| Sideways or diagonal into the past   | The new arrival stops at today (`clampShift()`) | Not allowed                                         |

The server checks again. If the night audit moves the business date during a drag, the refusal shows as a toast and the bar goes back. A stay that really happened in the past uses the **Past stay** or **Migrated stay** booking form, not the Timeline.

## Room move

- **Checked-in:** the column under the pointer at the drop is the first night in the new room. A past column means tonight. The Dialog shows it as **From** and staff can change it.
- **Confirmed:** the pointer column is the date shift (see [Gestures](#gestures)), so a drop with no shift starts on the first movable night (`movableNights().first`: the arrival, or the business date when the arrival is past). A row that is free only from a later night starts there. **From** in the Dialog can be changed for a split.
- Earlier nights keep their room, so the bar splits at that night.
- A Checked-in guest moves from tonight or later (`night_in_past`). Dropping on tomorrow's column keeps the guest in the old room tonight. Another room type needs the whole stay (`whole_stay_required`), so a guest in house can change room type only on the arrival night.
- A Confirmed unit can move to another room type that its rate plan sells with the same sell unit. The move is for the whole stay, so **From** is fixed to the arrival. A shifted drop can also change room type, with the same rule.
- Beds move to beds and rooms move to rooms. Each unit of a group moves alone.
- On drag start one `move-preview` request with `targets` returns every room or bed the unit can move to (`MoveTargetData`: row key, room type, `freeFrom`, from `FreeUnits::freeFrom()`). `freeFrom` is the first night from which the row is free to the departure, so a drop on a later night is checked without another request. Rows of another room type are listed only when the move can start on the arrival, and only when free from it. Other rows are greyed out and are not drop targets.
- The row under the pointer comes from `data-row-key` on the `DateGrid` row, and the night from the pointer's column. While dragging, a dashed outline shows the nights the unit takes in that row.
- A Dirty room for tonight is allowed, with a warning in the Dialog.

## Date change

- Added nights are priced by `RateQuoteService` for the added stretch only. Kept nights keep their booked prices.
- An added night keeps the neighbouring room when it is free. If not, the dates still save and the Dialog warns "2 nights without a room" (`unassignedNights`).
- The right edge cannot pass the arrival: a stay is at least one night (`invalid_stay`).
- A Checked-in unit has no left edge (`arrival_locked`).
- **Early departure fee.** Only a Checked-in unit with a shorter departure gets the early departure policy fee. A Confirmed unit that has not arrived changes dates with no fee. See [Check-in and check-out](01-check-in-and-check-out.md#how-the-forms-work).
- Sold out (`not_available`) or no price on an added night: toast, and the bar goes back. `dates-preview` quotes the added nights, so a missing price shows before the Dialog. It does not check availability: a sold out night is refused when **Save dates** is pressed, and the Dialog closes with the toast and reloads the grid.
- `dates-preview` does not return `unassignedNights` yet, so the Dialog cannot warn "2 nights without a room" before saving. The reservation page toast reports it after the save.

## Restrictions

`dates-preview` returns `restricted` before anything saves.

| User                                  | What happens                                                                                                           |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Without `rates.override-restrictions` | Error toast with the refusal message. The bar goes back. No Dialog                                                     |
| With `rates.override-restrictions`    | The Dialog shows the warning and a required **Override reason** ("e.g. Regular guest, manager approved"). It is logged |

A room move adds no nights, so it never checks restrictions. Later the preview may name the rule ("Min stay 2 nights from 14 Jun") from `RestrictionCheck::violations()`.

## Confirm Dialog

Every drop opens a small Dialog. Nothing saves until **Save**. Cancel or Esc puts the bar back. Enter saves.

| Drop                     | Content                                                                                                                                        |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Room move                | Old and new room, **From**, **Reason** (required; optional for a first assignment)                                                             |
| Extend                   | Old and new departure, price difference, override reason when restricted                                                                       |
| Shorter stay, Checked-in | Old and new departure, price difference, early departure fee with **Do not charge** and a reason. The fee block is hidden when the fee is 0    |
| Shorter stay, Confirmed  | Old and new departure, price difference                                                                                                        |
| Left edge                | Old and new dates, price difference, a warning for nights without a room                                                                       |
| Sideways or diagonal     | Old and new room and dates, nights, price was/now/difference, **Reason** (required when the room changes), **Override reason** when restricted |

- **Edge drag.** The handle is a thin strip at the bar's edge (a sibling of the bar button, `aria-hidden`, resize cursor). The column under the pointer is the new date, clamped by `clampEdgeDate()`: right edge, Confirmed at least arrival + 1 and business date + 1; right edge, Checked-in at least the business date and arrival + 1; left edge at least the business date and the first loaded date, at most departure − 1; the right edge stops at the last loaded date + 1. A drop on the same date does nothing. A dashed outline shows the new span in the bar's row while dragging and while the Dialog is open. A stay that changes room has handles only on the bar that holds the arrival (left) or the last night (right).
- **Sideways or diagonal drop.** `move-stay-preview` (POST, `reservations.manage`) is read once on the drop. It takes `arrival_date`, `departure_date` and `room_id` or `bed_id` and returns `UnitMoveStayPreviewData`: `dates` (the `dates-preview` figures and `restricted`), `targetFree` with `firstTakenNight` (`FreeUnits::firstTakenNight()` with the unit itself excepted), `roomTypeAllowed` (the target can be picked, and is the unit's own type or one its rate plan sells with the same sell unit), `reasonRequired` and `error`. An `error`, `restricted` for a user without `rates.override-restrictions` (same toast as the edge drag), a target that is not free ("Room 204 is not free on 17 Oct 2026.") or a room type that is not allowed shows as a toast, opens no Dialog and the bar goes back. Otherwise `TimelineMoveStayDialog` opens, and **Move stay** posts to `move-stay` with `arrival_date`, `departure_date`, `room_id` or `bed_id`, `reason` and `restriction_override_reason` (passed on only for a user with `rates.override-restrictions`). A refusal on **Reason** or **Override reason** shows on the field. Any other (room taken, sold out, no price) closes the Dialog with a toast and reloads the grid. A room or bed of another property is refused as `room_id` or `bed_id`. After a save the props `stays`, `blocks` and `rooms` reload.
- **Move or edge.** Both drags share one `DndContext`. The draggable's data `kind` tells them apart: `move` for the bar, `edge` for a handle. Each hook ignores the other's kind.
- On a drop `dates-preview` is read once. An `error`, or `restricted` for a user without `rates.override-restrictions`, shows as a toast and opens no Dialog. The Dialog sends `arrival_date`, `departure_date`, `do_not_charge`, `reason`, `restriction_override_reason` and `expected_fee`, and reads the preview again on `fee_changed`.
- It is a `FormOverlay` Dialog, not a Sheet: it opens from the grid with nothing else open, so it is the only overlay.
- Save is disabled while saving. A refusal shows on its field, or as a toast for `room_not_free` and `bed_not_free` ("Room 204 was just taken"), then the grid reloads.
- After a save the Action redirects back with a partial reload of `stays`, `blocks` and `rooms`.

## Unassigned lane

A booking shows **Auto** for its room until check-in, so many Confirmed units have no room. Without a lane the Timeline shows those rooms as free.

- A segment with no room or bed gets the row key `unassigned-{roomTypeId}` (the unit's room type), and consecutive nights form one segment. `StayViewQuery` does this with no extra query.
- The lane rows come from the browser, not the `rooms` prop. A lane shows only when a visible stay has an `unassigned-{roomTypeId}` segment in the window, and only for room types that pass the Room type filter. The Room group and Status filters are about physical rooms, so they never hide a lane or a stay with no room.
- **Placement.**

| Group by   | Where the lanes sit                                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Room type  | At the top of that room type's group. A room type with a lane but no room on screen still gets its group                                   |
| Room group | One **Unassigned** group at the top of the grid, with one lane for each room type, in room type order                                      |
| None       | The same **Unassigned** group at the top, then an **All rooms** group heading above the room list, so the heading does not claim the rooms |

- The label reads "Unassigned · {room type name}", with the count of units on its first row. Room type order is the order the room types first appear in the rooms. The **Unassigned** and **All rooms** groups collapse like other groups. A lane inside a room type group has no fold of its own: it folds with the group.
- A lane has as many rows as it needs. `packUnassigned()` packs the bars into the fewest sub-rows so none overlap (unit tested). A bar may begin on the day another ends, as with half cells elsewhere. Each sub-row is a normal grid row with the key `unassigned-{roomTypeId}-{n}`. A bar is drawn in its sub-row only for display: the stay keeps its `unassigned-{roomTypeId}` segment.
- A lane row is never a drop target, and its cells take no click for a new booking. The hover card shows "Not assigned" as the room.
- The footer counts an unassigned night as sold for that date, so Available drops by one for each unit. It counts only while a row of that room type is on screen.
- Dragging a bar from the lane to a room row is a first assignment through `MoveUnit`: the Dialog omits the "from" room and the reason is optional (`reasonRequired` is false for a night with no room).
- The query count stays fixed. The Pest test asserts it with unassigned units in the data.

## Empty cell

```
            Mon 26  Tue 27  Wed 28
202 Deluxe │       │   ✚   │       │
                       │ click
                       ▼
               ┌ 202 · Tue 27 Oct ─┐
               │ ＋ New booking     │   reservations.manage
               │ ⊘  Block room      │   rooms.block
               └───────────────────┘
```

- An empty cell on or after the business date takes a click for a user with `reservations.manage` or `rooms.block`. A cell that a stay or a block holds that night takes no click.
- A cell whose left half holds a departure can still be clicked: the new arrival or block takes that night.
- **`CellActions`** is a menu at the cell, titled with the room or bed and the date. It lists the actions the user may take:

| Action      | Needs                 | Shown when                  | Does                                                                                        |
| ----------- | --------------------- | --------------------------- | ------------------------------------------------------------------------------------------- |
| New booking | `reservations.manage` | Always                      | Opens the booking form with arrival = that night, departure = the next day, room or bed set |
| Block room  | `rooms.block`         | The night is today or later | Opens the Block room Dialog on the Timeline                                                 |

- With only one action to show, the click runs it at once and no menu opens.
- Block room starts on the property's calendar date (`window.today`), not the business date, because room blocks use `PropertyToday`. So before the night audit runs, yesterday's cell offers only New booking.
- `reservations.new` reads `arrival`, `room` and `bed` from the query. A bad or foreign value is ignored and the form opens blank.
- The booking link also carries `back=timeline` and `back_query` (this window and its filters), so the form's back arrow and Cancel return here. See [Back link](../12-reservations/05-booking-form.md#back-link).
- Availability is checked by the booking form when it is saved, as for any booking.

### Block room Dialog

- The Dialog holds `RoomBlockForm`, the same form and Dialog as the [Room blocks page](../05-rooms/04-room-blocks-and-statuses.md#ui). The room is fixed and shown as text. On a bed row the bed select starts on that bed and also offers **Whole room** and the other beds. On a room row of a dorm it starts on **Whole room**.
- Nights start as that night only (first = last = the clicked date). Staff can widen them with the `DateRangePicker`.
- Save posts to `front-desk.room-blocks.store`, which redirects back to the Timeline. A refusal shows as a toast: another open block on these nights, or a stay on these nights (`has_stay`). On success the Dialog closes and the new block bar shows.
- The menu closes before the Dialog opens, so there is one overlay level. Version polling pauses while the Dialog is open.
- The Timeline sends `canBlockRooms`, `blockReasons` (`RoomBlockReason::options()`) and `maxBlockNights` (`RoomBlockRequest::MAX_NIGHTS`).

## Window edge

A bar or edge stops at the edge of the loaded window. There is no auto-scroll, because other windows load with a visit. For a longer change, move the window first or use Change dates on the reservation page.

## Liveness

- Version polling pauses while a drag or a Dialog is open, and runs once when it closes.
- Two staff dropping on the same room and night: the unique indexes on `unit_nights` let one save. The other gets the "just taken" toast.

## Code layout

```
resources/js/components/pms/stay-view/
├── use-timeline-drag.ts     dnd-kit sensors, drag state, preview request (kind `move`), column shift, move-stay-preview
├── drop-target.ts           column under the pointer, business date stop, drop check, allowed rows; tested
├── timeline-move-dialog.tsx the confirm Dialog for a room move
├── use-edge-drag.ts         edge drag state (kind `edge`), outline, one dates-preview on drop
├── resize-target.ts         handle rules, clampEdgeDate(), resizedDates(), canShiftDates(), clampShift(), shiftedDates(); tested
├── edge-handle.tsx          the handle on a bar edge
├── timeline-dates-dialog.tsx the confirm Dialog for a date change
├── timeline-move-stay-dialog.tsx the confirm Dialog for dates and room together
├── unassigned-lane.ts       packUnassigned(); tested
└── cell-actions.tsx         empty cell click, the CellActions menu and the Block room Dialog

resources/js/components/pms/room-block-form.tsx  the block form, shared with the Room blocks page

app/Actions/FrontDesk/MoveUnitStay.php         dates and room in one transaction
app/Actions/FrontDesk/MoveUnitStayRefused.php  raised inside it to roll back; never leaves the Action
UnitActionPreview::moveStay()                  the read-only preview (UnitMoveStayPreviewData)
```

## Tests

- Pest (`FrontDeskMoveStayTest`): `MoveUnitStay` saves a shift with a room change, keeps the room with no reason, rolls back when the room, the reason or the dates are refused (restriction, sold out, past arrival) with no activity rows left, refuses a unit that is not Confirmed; the `move-stay` route needs `reservations.manage` and refuses another property's room; `move-stay-preview` reports `targetFree`, `roomTypeAllowed`, `restricted` and `reasonRequired`.
- Pest: `ChangeUnitDates` charges no early departure fee for a Confirmed unit; `StayViewQuery` returns unassigned segments with a fixed query count; `move-preview` returns every drag target with its `freeFrom`; the booking form prefill ignores bad values.
- Pest: `StayData::$isPackagePlan` is true for a unit on a Package plan; the Timeline page carries `canOverrideRestrictions` and `canWaiveFees`.
- Pest (`RoomBlockTest`): a block over a night with a stay is refused on create and on edit, for a room, a bed and the whole room over a bed, and a Cancelled stay does not count; the store route redirects back; the Timeline carries `canBlockRooms`, `blockReasons` and `maxBlockNights`, with `canBlockRooms` false without `rooms.block`.
- Browser unit tests (`vp test`): `isCellBookable()` with only `rooms.block`, with neither permission, and `cellActions()` (which actions a cell offers), `drop-target.ts` (half-cell snap, business date stop, in-house rules, Confirmed starts on the first movable night), `resize-target.ts` (handles, clamp rules, `clampShift()`) and `packUnassigned()`.

## Build order

1. Book from an empty cell.
2. Drag to another room.
3. Unassigned lane.
4. Drag edges to change dates.
5. Sideways and diagonal drag.

The no-fee rule before arrival lands first, on its own, because it also changes Change dates on the reservation page.
