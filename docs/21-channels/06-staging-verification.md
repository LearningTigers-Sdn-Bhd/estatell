# Channex staging verification

## Purpose

Confirm undocumented Channex dorm behaviour before enabling NativeDorm.

## Confirmed in Channex docs

- `room_kind`: `room` or `dorm` (Room Types Collection).
- `capacity`: count of beds in one physical room, dorm only.
- `count_of_rooms`: units to sell of this type.
- Rate plan `sell_mode`: `per_room` or `per_person` only. No `per_bed`.
- Hostelworld: send dorm price for 1 person.
- Agoda: mapping is always multi occupancy. Dorms not mentioned.
- Booking.com: occupancy-based pricing supported. Dorms not mentioned.

## Not confirmed

1. Is dorm availability counted in beds or rooms?
2. Does `count_of_rooms` for a dorm mean rooms or total beds?
3. Do Agoda and Booking.com accept `room_kind: dorm`?
4. Does a multi-bed booking arrive as one room entry with N guests, or N room entries?

## Checks (staging.channex.io)

1. Create a dorm room type: `room_kind: dorm`, `capacity: 6`, `count_of_rooms: 2`, `occ_adults: 6`. Record validation errors. Retry with `occ_adults: 1`.
2. `POST /availability` with 12, then 2, then `GET /availability`. Is 12 rejected or capped?
3. Create rate plans on the dorm type in `per_room` (occupancy 1) and `per_person` (1-6). Which are accepted?
4. Map channels: Hostelworld, and Booking.com or Agoda sandboxes if available. Is the dorm type offered?
5. Send a test booking for 3 beds. Inspect the revision: number of room entries and occupancy per entry.
6. Repeat 2 and 5 with a BedAsUnit setup as control.

## Outcome

Record results here, then set `OtaChannel::supportsNativeDorm()` and finish `NativeDormStrategy`.

## Sources

- https://docs.channex.io/api-v.1-documentation/room-types-collection
- https://docs.channex.io/api-v.1-documentation/rate-plans-collection
- https://docs.channex.io/channel-mapping-guides/hostelworld
- https://docs.channex.io/channel-mapping-guides/agoda
- https://docs.channex.io/channel-mapping-guides/booking.com
