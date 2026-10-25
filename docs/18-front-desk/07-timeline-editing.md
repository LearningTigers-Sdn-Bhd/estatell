# Timeline editing

## Purpose

Change stays straight from the [Timeline View](03-stay-view.md#timeline-view-screen): drag a bar to another room, drag its edges to change dates, and click an empty cell to book. Every write goes through the Actions the reservation page already uses, so the rules and refusals are the same.

## Gestures

| Gesture                                       | Units                    | Server                                                                               |
| --------------------------------------------- | ------------------------ | ------------------------------------------------------------------------------------ |
| Click an empty cell                           | —                        | Opens `reservations.new` with the arrival, departure and room or bed filled in       |
| Drag a bar up or down                         | Confirmed and Checked-in | `reservations.units.move-preview`, then `MoveUnit`                                   |
| Drag a bar from the Unassigned lane to a room | Confirmed                | `MoveUnit` as a first assignment, so no reason                                       |
| Drag the right edge                           | Confirmed and Checked-in | `reservations.units.dates-preview`, then `ChangeUnitDates` (`ExtendStay` when later) |
| Drag the left edge                            | Confirmed only           | `reservations.units.dates-preview`, then `ChangeUnitDates`                           |
| Drag a bar sideways or diagonally             | Confirmed only           | `reservations.units.dates-preview`, then `MoveUnitStay` (new)                        |

- A drag changes the room or the dates, never both, except the sideways and diagonal drag of a Confirmed unit.
- `MoveUnitStay` runs `ChangeUnitDates` and then `MoveUnit` in one transaction. A refusal from either rolls both back and writes nothing. A sideways drag keeps the room when it is free on the new nights. Moving both dates is a new booking of the unit, so it has no fee.
- `@dnd-kit/core` is already a dependency. The bars snap to half cells, as they are drawn (see [Half cells](03-stay-view.md#timeline-view-screen)).

## Who can drag

| Bar                                  | Drag                                                |
| ------------------------------------ | --------------------------------------------------- |
| User without `reservations.manage`   | None. Click opens the peek sheet as before          |
| Checked out, cancelled, no-show      | None                                                |
| Day use marker, block bar            | None                                                |
| Package plan unit                    | Up or down only (`package_plan`)                    |
| Bar cut by the window's left edge    | Up or down only                                     |
| Phone width (below `md`) or keyboard | None. The reservation page dialogs do the same work |

## Past dates

"Today" is the **business date**, not the clock. Between midnight and the night audit, today is still the day before.

- Past columns have a light grey wash. They are not drop targets and an empty past cell takes no click.
- While dragging, a bar or edge stops at the business date. It does not follow the pointer into the past, so a drop never fails for a past date.

| Drag or drop                         | Confirmed                     | Checked-in                                          |
| ------------------------------------ | ----------------------------- | --------------------------------------------------- |
| Up or down, dropped on a past column | Cannot happen: no past nights | Moves from tonight. Past nights keep the old room   |
| Left edge into the past              | Stops at today                | No handle                                           |
| Right edge into the past             | Stops at today + 1            | Stops at today: leaving today is an early departure |
| Sideways or diagonal into the past   | Stops at today                | Not allowed                                         |

The server checks again. If the night audit moves the business date during a drag, the refusal shows as a toast and the bar goes back. A stay that really happened in the past uses the **Past stay** or **Migrated stay** booking form, not the Timeline.

## Room move

- The column under the pointer at the drop is the first night in the new room. A past column means tonight. The Dialog shows it as **From** and staff can change it.
- Earlier nights keep their room, so the bar splits at that night.
- A Checked-in guest moves from tonight or later (`night_in_past`) and only within the room type (`whole_stay_required`). Dropping on tomorrow's column keeps the guest in the old room tonight.
- A Confirmed unit can move to another room type that its rate plan sells with the same sell unit. The move is for the whole stay, so **From** is fixed to the arrival.
- Beds move to beds and rooms move to rooms. Each unit of a group moves alone.
- On drag start one `move-preview` request returns the free rooms or beds for every room type the unit can move to. Rows that are not free, out of service, blocked or of a room type the plan does not sell are greyed out and are not drop targets. `move-preview` gets this option as an additive change.
- A Dirty room for tonight is allowed, with a warning in the Dialog.

## Date change

- Added nights are priced by `RateQuoteService` for the added stretch only. Kept nights keep their booked prices.
- An added night keeps the neighbouring room when it is free. If not, the dates still save and the Dialog warns "2 nights without a room" (`unassignedNights`).
- The right edge cannot pass the arrival: a stay is at least one night (`invalid_stay`).
- A Checked-in unit has no left edge (`arrival_locked`).
- **Early departure fee.** Only a Checked-in unit with a shorter departure gets the early departure policy fee. A Confirmed unit that has not arrived changes dates with no fee. See [Check-in and check-out](01-check-in-and-check-out.md#how-the-forms-work).
- Sold out (`not_available`) or no price on an added night: toast, and the bar goes back.

## Restrictions

`dates-preview` returns `restricted` before anything saves.

| User                                  | What happens                                                                                                           |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Without `rates.override-restrictions` | Error toast with the refusal message. The bar goes back. No Dialog                                                     |
| With `rates.override-restrictions`    | The Dialog shows the warning and a required **Override reason** ("e.g. Regular guest, manager approved"). It is logged |

A room move adds no nights, so it never checks restrictions. Later the preview may name the rule ("Min stay 2 nights from 14 Jun") from `RestrictionCheck::violations()`.

## Confirm Dialog

Every drop opens a small Dialog. Nothing saves until **Save**. Cancel or Esc puts the bar back. Enter saves.

| Drop                            | Content                                                                                                                                     |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Room move                       | Old and new room, **From**, **Reason** (required; optional for a first assignment)                                                          |
| Extend                          | Old and new departure, price difference, override reason when restricted                                                                    |
| Shorter stay, Checked-in        | Old and new departure, price difference, early departure fee with **Do not charge** and a reason. The fee block is hidden when the fee is 0 |
| Shorter stay, Confirmed         | Old and new departure, price difference                                                                                                     |
| Left edge, sideways or diagonal | Old and new room and dates, price difference, a warning for nights without a room                                                           |

- It is a `FormOverlay` Dialog, not a Sheet: it opens from the grid with nothing else open, so it is the only overlay.
- Save is disabled while saving. A refusal shows on its field, or as a toast for `room_not_free` and `bed_not_free` ("Room 204 was just taken"), then the grid reloads.
- After a save the Action redirects back with a partial reload of `stays`, `blocks` and `rooms`.

## Unassigned lane

A booking shows **Auto** for its room until check-in, so many Confirmed units have no room. Without a lane the Timeline shows those rooms as free.

- One collapsible **Unassigned** row per room type, at the top of its group, shown only when a unit in the window has a night with no room or bed.
- A segment with no room or bed gets the row key `unassigned-{roomTypeId}`. Today `StayViewQuery` leaves these nights out.
- The lane has as many rows as it needs: the browser packs the bars into rows so none overlap (`packUnassigned()`, unit tested).
- The footer counts an unassigned night as sold for that date.
- Dragging a bar from the lane to a room row is a first assignment through `MoveUnit`, so no reason.
- The query count stays fixed. The Pest test asserts it.

## Empty cell

- With `reservations.manage`, clicking an empty cell on or after the business date opens the booking form with arrival = that night, departure = the next day and that room or bed picked.
- The cell in a room with a block or out of service on that night takes no click.
- A cell whose left half holds a departure can still be clicked: the new arrival takes the right half.
- `reservations.new` reads `arrival`, `room` and `bed` from the query. A bad or foreign value is ignored and the form opens blank.
- Until **Block room** is built, the click goes straight to the booking form. When Block room ships, the click opens `CellActions` with both.
- Availability is checked by the booking form when it is saved, as for any booking.

## Window edge

A bar or edge stops at the edge of the loaded window. There is no auto-scroll, because other windows load with a visit. For a longer change, move the window first or use Change dates on the reservation page.

## Liveness

- Version polling pauses while a drag or a Dialog is open, and runs once when it closes.
- Two staff dropping on the same room and night: the unique indexes on `unit_nights` let one save. The other gets the "just taken" toast.

## Code layout

```
resources/js/components/pms/stay-view/
├── use-timeline-drag.ts     dnd-kit sensors, drag state, preview request
├── drop-target.ts           row and column under the pointer, business date stop, allowed rows; tested
├── timeline-drop-dialog.tsx the confirm Dialog
├── unassigned-lane.ts       packUnassigned(); tested
└── cell-actions.tsx         empty cell click

app/Actions/FrontDesk/MoveUnitStay.php   dates and room in one transaction
```

## Tests

- Pest: `MoveUnitStay` rolls back when the room or the dates are refused; `ChangeUnitDates` charges no early departure fee for a Confirmed unit; `StayViewQuery` returns unassigned segments with a fixed query count; `move-preview` returns free rooms for every allowed room type; the booking form prefill ignores bad values.
- Browser unit tests (`vp test`): `drop-target.ts` (half-cell snap, business date stop, in-house rules) and `packUnassigned()`.

## Build order

1. Book from an empty cell.
2. Drag to another room.
3. Unassigned lane.
4. Drag edges to change dates.
5. Sideways and diagonal drag.

The no-fee rule before arrival lands first, on its own, because it also changes Change dates on the reservation page.
