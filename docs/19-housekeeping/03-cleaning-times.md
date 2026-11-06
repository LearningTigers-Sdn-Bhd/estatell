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

| Action  | Who                   | Does                                                                                                                                          |
| ------- | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| **IN**  | `housekeeping.update` | `StartRoomCleaning`: the time now. Housekeeping are named themselves. A user who may correct times names the room's assignee, else themselves |
| **OUT** | `housekeeping.update` | `FinishRoomCleaning`: the time now. A Dirty room becomes Clean (decided). Clean and Inspected rooms stay                                      |

| Refusal                | When                 | Sentence                                               |
| ---------------------- | -------------------- | ------------------------------------------------------ |
| `cleaning_in_progress` | IN while one is open | Room 102 is already being cleaned. Record OUT first.   |
| `cleaning_not_started` | OUT with none open   | Room 102 has no cleaning in progress. Record IN first. |
| `out_of_service`       | IN on a blocked room | Room 203 is out of service.                            |

## Correcting

`housekeeping.edit-times` (default: Owner, Manager, Front desk; other roles can be given it) shows a pencil in the cell. The Dialog has Housekeeper, IN and OUT, and **Remove this cleaning**.

- `SaveRoomCleaning` adds or edits. Times are `H:i` in the property time zone, on the cleaning's date. OUT before IN is refused.
- Giving an open cleaning its OUT time makes a Dirty room Clean, as OUT does. Correcting a finished cleaning leaves the status alone.
- `DeleteRoomCleaning` removes a mistake, such as IN on the wrong room. The status does not change.
- The activity log keeps the old and new values: `room_cleaning.started`, `.finished`, `.added`, `.edited`, `.removed`.

## Where it shows

| Page               | Who sees it         | Shows                                                                                     |
| ------------------ | ------------------- | ----------------------------------------------------------------------------------------- |
| Housekeeping board | `housekeeping.view` | **Cleaning** column with IN, OUT and the pencil. Housekeeping work here                   |
| House status       | `reports.view`      | **Housekeeper IN / OUT** column with the same cell. The PDF tile prints "Ana 10:05-10:40" |
| Housekeeping log   | `reports.view`      | The report below                                                                          |

The Housekeeping role has no `reports.view`, so it records on the board. A cleaning left open on an earlier date still shows, so someone can record its OUT.

## Housekeeping log

`ReportKey::HousekeepingLog` (`HousekeepingLogReport`), in Reports › Front desk: one row per cleaning, up to 31 days. Columns: Date, Room, Room type, Housekeeper, IN, OUT, Minutes. The total sums the minutes. It has the shared PDF, CSV and Excel export.
