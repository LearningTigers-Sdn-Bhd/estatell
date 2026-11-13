# Cleaning times

## Purpose

Record who cleaned each room, and when they went in and came out. Housekeeping enter it. Front office, or anyone with the permission, can correct it.

## Data model

```
room_cleanings   id, property_id, room_id, date (business date), housekeeper_id (FK users),
                 started_at, finished_at (nullable), created_by, updated_by (nullable), timestamps
                 CHECK finished_at >= started_at
                 UNIQUE (room_id) WHERE finished_at IS NULL
```

A room can be cleaned several times a day, and has at most one cleaning without an OUT time.

## Recording

| Action    | Who                   | Does                                                                                                                                            |
| --------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **Start** | `housekeeping.update` | `StartRoomCleaning`: the time now (the IN time). The housekeeper becomes the room's assignee (`room_status.assigned` is logged when it changes) |
| **Done**  | `housekeeping.update` | `FinishRoomCleaning`: the time now (the OUT time). A Dirty room becomes Clean (decided). Clean and Inspected rooms stay                         |

Who Start names (`RoomCleanings::housekeeperFor`, checked in `StartRoomCleaningRequest`):

| Viewer                             | Names                                                                                                                   |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Housekeeping (no `edit-times`)     | Themselves. A `housekeeper_id` they send is ignored                                                                     |
| Front office (`edit-times`)        | The `housekeeper_id` they picked, else the room's assignee                                                              |
| Front office, room has no assignee | Must pick. Start opens a menu "Who is cleaning 105?" (`askHousekeeper` on the cell). Without a pick: a validation error |

| Refusal                | When                    | Sentence                                               |
| ---------------------- | ----------------------- | ------------------------------------------------------ |
| `cleaning_in_progress` | Start while one is open | Room 102 is already being cleaned. Tap Done first.     |
| `cleaning_not_started` | Done with none open     | Room 102 has no cleaning in progress. Tap Start first. |
| `out_of_service`       | Start on a blocked room | Room 203 is out of service.                            |

## Correcting

`housekeeping.edit-times` (default: Owner, Manager, Front desk; other roles can be given it) shows **Correct cleaning times** in the board's row ⋯ menu, and a pencil in the House status cell. The Dialog has Housekeeper, **Started** and **Finished** (the IN and OUT times), and a red **Remove** button at the start of the footer. Finished before Started is refused.

- `SaveRoomCleaning` adds or edits. Times are `H:i` in the property time zone, on the cleaning's date. OUT before IN is refused.
- Giving an open cleaning its OUT time makes a Dirty room Clean, as OUT does. Correcting a finished cleaning leaves the status alone.
- `DeleteRoomCleaning` removes a mistake, such as IN on the wrong room. The status does not change.
- The activity log keeps the old and new values: `room_cleaning.started`, `.finished`, `.added`, `.edited`, `.removed`.

## Where it shows

| Page               | Who sees it         | Shows                                                                            |
| ------------------ | ------------------- | -------------------------------------------------------------------------------- |
| Housekeeping board | `housekeeping.view` | **Housekeeper** column, see below. Housekeeping work here                        |
| House status       | `reports.view`      | **Housekeeper** column with the same cell. The PDF tile prints "Ana 10:05-10:40" |
| Housekeeping log   | `reports.view`      | The report below, the Log tab of Reports › Housekeeping                          |

The board's **Housekeeper** column joins the assignee (the plan) and the cleaning (what happened):

```
Not started   [Siti ▾]  [Start]
Cleaning      Siti · since 10:05  [Done]       assignee picker hidden
Done          Siti · 10:05–10:40 · 35 min      picker and Start come back only when the room is Dirty again
```

House status shows the same cell without the picker, and offers Start again after a finished cleaning.

The Housekeeping role has no `reports.view`, so it records on the board. A cleaning left open on an earlier date still shows, so someone can record its OUT.

## Housekeeping log

`ReportKey::HousekeepingLog` (`HousekeepingLogReport`), the Log tab of [Reports › Housekeeping](../22-reports/03-house-status-and-items.md#housekeeping-page) (`?tab=log`): one row per cleaning, up to 31 days. Columns: Date, Room, Room type, Housekeeper, Started, Finished, Minutes. The total sums the minutes. It has the shared PDF, CSV and Excel export.
