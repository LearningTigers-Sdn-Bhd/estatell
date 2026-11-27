# Dorm beds

## Purpose

In a shared dorm only the bunk a guest leaves needs stripping and making up; the other guests' bunks do not. Each dorm bed has its own housekeeping status, and the dorm's room status is the summary of its beds. Tanini requirement 6.2. Private rooms and chalets do not change: one status per room.

**Status: built** (2026-11-23). Decided with the owner after looking at hostel PMSs: Clock PMS+ tracks dorms by bed and private rooms as a whole; Mews, Cloudbeds and Sirvoy stay room level.

## Data model

```
bed_statuses   id, property_id, bed_id (unique, cascade), status (clean | dirty | inspected, CHECK),
               last_changed_by (null), last_changed_at, timestamps
```

- Created with every bed (`CreateBedStatus`, called by `CreateBed` and `GenerateBeds`), starting with its room's status. The migration gave every existing bed its room's status.
- The statuses and moves are `HousekeepingStatus`: Dirty > Clean > Inspected, any back to Dirty (`canTransitionTo()`, `movesFor()`).

## Rules

| Event                                                                                               | Beds                                           | Dorm room status                        |
| --------------------------------------------------------------------------------------------------- | ---------------------------------------------- | --------------------------------------- |
| A guest checks out of a bed (`CheckOutUnit`)                                                        | That bed goes Dirty                            | Dirty                                   |
| Night audit (`UpdateRoomStatuses`)                                                                  | The beds a checked-in guest sleeps in go Dirty | Dirty                                   |
| One bed changes (`UpdateBedStatus`, the board)                                                      | That bed                                       | Worked out again (`SyncDormRoomStatus`) |
| The whole dorm changes (`UpdateRoomStatus`: the room's badge, bulk Mark clean, a finished cleaning) | Every active bed takes it                      | As set                                  |

- **Summary** (`SyncDormRoomStatus`): Dirty while any active bed is Dirty, Inspected when every active bed is, else Clean. Everything that reads room status (the board, House status, auto-assign, the Concierge page) sees the dorm as its beds are, with no change.
- A bed is refused (out of service) while a block of that bed or a whole-room block covers the business date. Inspected needs `housekeeping.inspect`, as for a room.
- Cleaning times, the assignee, priority, Do not disturb, remarks and Needs maintenance stay per room.

## Board

- A dorm row shows a **"3 of 8 beds dirty"** button under its status badge ("8 beds ready" or "8 beds inspected" when none is dirty). It opens the beds: label, who is in it today (Due out, In house, Checked out, Arriving, or Empty) and its status, which opens the moves the viewer may make.
- **The dorm row reads in counts** (2026-11-26), never as one bed's guest and dates, which read as one stay for the room ("8 adults, 25 Nov – 25 Nov"). `HousekeepingRoomData.dorm` (`HousekeepingDormData`, from `HousekeepingBoardBuilder::counts()`; one unit is one bed):

    | Column             | Dorm row                                                                                                   |
    | ------------------ | ---------------------------------------------------------------------------------------------------------- |
    | Guest              | "6 of 8 beds tonight"                                                                                      |
    | Reservation Status | One badge per stay with its count: "1 Due out", "2 Arriving", "5 In house", "1 Checked out" (none at zero) |
    | Arrival            | "2 from 15:00" (still to arrive, earliest time), "All arrived", or —                                       |
    | Departure          | "1 by 11:00" (still to leave, latest time), "2 left", or —                                                 |

    Pax stays the sum of tonight's guests. The printed sheet and the spreadsheet print the same words. Sorting still uses the first arriving or leaving bed's date and time. Decided with the owner on 2026-11-26, after comparing OPERA, which keeps the housekeeping status apart from the front office status, and hostel systems that track by bed.

- **Movement strip** (desktop only): under the beds button, the beds with a movement today, "A6 Due out · Dirty", "B2 Arriving", "A3 Checked out · Dirty" (the bunk to turn over). Stay-over beds are left out even when dirty, because the button already counts them. Phones keep the button only.
- `HousekeepingRoomData.beds` (`HousekeepingBedData`): the dorm's active beds in order, read with the rooms (`beds.status`) and the day's units (one read, grouped by room and by bed). The query count does not grow with beds (tested).
- **Booking notes** (added 2026-11-26): a bed shows its guest's [housekeeping remark](../12-reservations/16-remarks.md) under the name (`HousekeepingBedData.guestRemark`), and the button adds ", 1 booking note" so it is not missed. The printed sheet's Notes list them as "Bed A2: Lower bunk please".
- `PUT /p/{code}/housekeeping/rooms/{room}/beds/{bed}/status` (`housekeeping.rooms.beds.status`), `housekeeping.update`. The bed must belong to the room (scoped binding).

## Code

| Part                                   | Role                                 |
| -------------------------------------- | ------------------------------------ |
| `BedStatus`, `Bed::status()`           | The bed's status                     |
| `CreateBedStatus`                      | A new bed's status                   |
| `UpdateBedStatus`                      | One bed, then the summary            |
| `SyncDormRoomStatus`                   | The dorm's room status from its beds |
| `UpdateRoomStatus`                     | The whole dorm sets its beds         |
| `HousekeepingBedData`, `dorm-beds.tsx` | The board                            |

Tests: `tests/Feature/Housekeeping/DormBedsTest.php`, and check-out in `tests/Feature/FrontDesk/FrontDeskStayTest.php`.
