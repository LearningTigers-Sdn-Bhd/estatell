# Channex staging verification

## Purpose

Record what the staging account (`staging.channex.io`) has confirmed, and the checks still open.

## Account checks (2026-10-20, read-only)

| Check                              | Result                                                                                          |
| ---------------------------------- | ----------------------------------------------------------------------------------------------- |
| `GET /channels/adapter?code=Agoda` | Works. The Channel API is on for the account.                                                   |
| `GET /channels/list`               | 60 adapters, including `Agoda`, `BookingCom`, `Hostelworld`, `Expedia`, `AirBNB`, `OpenChannel` |
| `GET /groups`                      | One group, "User Group". Its ID is `CHANNEX_GROUP_ID`.                                          |
| `GET /properties`                  | One, "Demo Property" (made by Channex)                                                          |
| `GET /channels`                    | None                                                                                            |

The key is sent in the `user-api-key` header. Laravel's `.env` does not set shell variables, so export it before using curl.

## Adapter fields

Hidden params are filled by Channex and never shown.

| Field                        | Booking.com                       | Agoda                                          | Hostelworld                    | Expedia                           | Airbnb                     |
| ---------------------------- | --------------------------------- | ---------------------------------------------- | ------------------------------ | --------------------------------- | -------------------------- |
| Hotel ID                     | ✓                                 | ✓                                              | ✓                              | ✓                                 | – (OAuth token)            |
| Send emails + Property email | ✓                                 | ✓                                              | ✓                              | ✓                                 | ✓                          |
| Tax for bookings             | –                                 | Inclusive · Incl. with commissions · Exclusive | –                              | –                                 | –                          |
| Min stay type                | –                                 | –                                              | Arrival · Through              | Arrival · Through                 | Arrival · Through          |
| Booking amount               | –                                 | –                                              | With · Without commission      | Collect · Total · Total excl. tax | Payout · Total paid        |
| Mapping columns              | room · rate · occupancy · primary | room · rate · occupancy · primary              | room · rate · type · occupancy | room · rate · occupancy · primary | listing · Airbnb rate plan |
| `mapping_mode`               | `room_rate_multioccupancy`        | `room_rate_multioccupancy`                     | `room_rate_multioccupancy`     | `room_rate_multioccupancy`        | `listing`                  |
| `property_mapping`           | single                            | single                                         | single                         | single                            | multiple                   |
| Import future bookings       | ✓                                 | –                                              | –                              | ✓                                 | ✓                          |

## Open checks

1. **Test hotel IDs.** `test_connection` and `mapping_details` call the real OTA. Ask Channex support for Booking.com and Agoda test hotel IDs.
2. **Tax and amount settings.** Send one test booking per option (Agoda tax, Hostelworld and Expedia booking amount) and record which amount arrives. Pick the defaults that keep the folio right under SST.
3. **Import future bookings.** Run `load_future_reservations` on a Booking.com test connection and inspect the revisions.
4. **End to end.** Connect Open Channel (self-connect, no OTA needed) and push test bookings to cover sync, ARI and ingestion.
5. **Staging limits.** How many staging properties the account may create.

## Dorm checks (WIP)

Run with the [dorm strategy](03-dorm-strategies.md) item, not before.

### Confirmed in Channex docs

- `room_kind`: `room` or `dorm` (Room Types Collection).
- `capacity`: count of beds in one physical room, dorm only.
- `count_of_rooms`: units to sell of this type.
- Rate plan `sell_mode`: `per_room` or `per_person` only. No `per_bed`.
- Hostelworld: send dorm price for 1 person.
- Agoda: mapping is always multi occupancy. Dorms not mentioned.
- Booking.com: occupancy-based pricing supported. Dorms not mentioned.

### Not confirmed

1. Is dorm availability counted in beds or rooms?
2. Does `count_of_rooms` for a dorm mean rooms or total beds?
3. Do Agoda and Booking.com accept `room_kind: dorm`?
4. Does a multi-bed booking arrive as one room entry with N guests, or N room entries?

### Checks

1. Create a dorm room type: `room_kind: dorm`, `capacity: 6`, `count_of_rooms: 2`, `occ_adults: 6`. Record validation errors. Retry with `occ_adults: 1`.
2. `POST /availability` with 12, then 2, then `GET /availability`. Is 12 rejected or capped?
3. Create rate plans on the dorm type in `per_room` (occupancy 1) and `per_person` (1-6). Which are accepted?
4. Map channels: Hostelworld, and Booking.com or Agoda sandboxes if available. Is the dorm type offered?
5. Send a test booking for 3 beds. Inspect the revision: number of room entries and occupancy per entry.
6. Repeat 2 and 5 with a BedAsUnit setup as control.

### Outcome

Record results here, then mark which `ota_channels` support native dorms and finish `NativeDormStrategy`.

## Sources

- https://docs.channex.io/api-v.1-documentation/channel-api
- https://docs.channex.io/channel-api-examples/agoda
- https://docs.channex.io/channel-api-examples/booking.com
- https://docs.channex.io/api-v.1-documentation/rate-limits
- https://docs.channex.io/api-v.1-documentation/pms-certification-tests
- https://docs.channex.io/api-v.1-documentation/room-types-collection
- https://docs.channex.io/api-v.1-documentation/rate-plans-collection
- https://docs.channex.io/channel-mapping-guides/hostelworld
- https://docs.channex.io/channel-mapping-guides/agoda
- https://docs.channex.io/channel-mapping-guides/booking.com
