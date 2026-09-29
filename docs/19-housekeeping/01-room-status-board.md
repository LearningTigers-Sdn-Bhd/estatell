# Room status board

## Purpose

Show housekeeping which rooms need work today.

## Data

Uses `room_statuses` (see [Room blocks and statuses](../05-rooms/04-room-blocks-and-statuses.md)) plus today's arrivals, departures and stayovers.

## Features

- Board grouped by room group (a group is a floor, wing or block).
- Status changes: Dirty > Clean > Inspected (`HousekeepingStatus`). Any room can become Dirty.
- Out of service is shown from an open room block, not stored as a status.
- Priority flag and note, do-not-disturb for today, assignee.
- Filters: arrivals today, departures today, stayovers.

## Actions

`UpdateRoomStatus`, `AssignHousekeeper`, `SetRoomPriority`.

## Rules

- The night audit marks occupied and departed rooms Dirty.
- Check-out marks the room Dirty.

## Permissions

`housekeeping.view`, `housekeeping.update`.

## Out of scope

Guest housekeeping requests and complaint workflows from wastays.
