# Group bookings

## Purpose

Book many rooms (or beds) under one reservation.

## Shape

A group is a reservation with `group_name` set and N units. Units can have different dates, room types, rate plans and occupants.

## Billing

| `billing_mode` | Room charges      | Extras            |
| -------------- | ----------------- | ----------------- |
| MasterPays     | Master folio      | Each unit's folio |
| EachPays       | Each unit's folio | Each unit's folio |

MasterPays creates routing rules automatically (see [Routing](../13-folio/03-routing.md)).

## Supported cases

- Staggered arrivals and departures.
- Cancel one room without touching others.
- Check in room by room.
- Mixed private rooms and dorm beds.

## Lessons from wastays

`group_bookings` wrapped N `bookings` rows and duplicated reservation number, receipt number, revision and status fields. `IngestGroupBookingService` (437 lines) existed only for multi-room OTA bookings.
