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

1. **Test hotel IDs.** `test_connection` and `mapping_details` call the real OTA. Booking.com has shared test hotels on staging (listed in the Channex channel form, each leased for about 30 minutes): `5868189` uses occupancy pricing and `6519420` uses per-room pricing. Channex allows one connection per hotel ID, so delete a test connection before the next run. Agoda still needs a test hotel ID from Channex support.
2. **Tax and amount settings.** Send one test booking per option (Agoda tax, Hostelworld and Expedia booking amount) and record which amount arrives. Pick the defaults that keep the folio right under SST.
3. **Import future bookings.** Run `load_future_reservations` on a Booking.com test connection and inspect the revisions.
4. **End to end.** Connect Open Channel (self-connect, no OTA needed) and push test bookings to cover sync, ARI and ingestion.
5. **Staging limits.** How many staging properties the account may create.

## Dorm checks (WIP)

Run with the [dorm strategy](03-dorm-strategies.md) item, not before.

### BedAsUnit on staging (2026-11-23)

The structure sync of the Kopi Lane demo property (ES1107) on Channex staging, read back from `/room_types` and `/rate_plans`:

| Room type                            | `count_of_rooms` | `occ_adults` / `occ_children` / `default_occupancy` | `room_kind` | Rate plan                                      |
| ------------------------------------ | ---------------- | --------------------------------------------------- | ----------- | ---------------------------------------------- |
| 8-Bed Mixed Dorm (2 rooms of 8 beds) | 16               | 1 / 0 / 1                                           | room        | `per_room`, one option at occupancy 1, primary |
| 6-Bed Female Dorm                    | 6                | 1 / 0 / 1                                           | room        | same                                           |
| 4-Bed Dorm                           | 4                | 1 / 0 / 1                                           | room        | same                                           |
| Private Double (not a dorm)          | 4                | 2 / 0 / 2                                           | room        | `per_room`, occupancy 2                        |

**ARI push (2026-11-23).** A full run (`FlushChannelAri`, 500 days) sent everything with no error. Read back from `/availability` and `/restrictions` for 23–27 Nov, Channex matched estatell's free beds on every date (8-Bed Mixed Dorm 13, 10, 11, 11, 16; 6-Bed 4, 6, 5, 5, 6; 4-Bed 4, 4, 4, 2, 2) and the rate is the bed price (RM 38, 45, 50).

**Dorm test booking (2026-11-23).** With the Booking CRS app (`booking_crs`) installed on the staging property, `POST /bookings` made booking `OFL-DORMTEST-093028`: three room entries on the 8-Bed Mixed Dorm, one adult each, 10–12 Dec, RM 38 a night. `PullChannelRevisions` stored and acknowledged the revision and made one reservation of three units, each one bed (A1, A2, A3), one adult, no children, RM 38 a night on the Dorm Bed plan. Free beds dropped from 16 to 13 on both nights, and the next flush sent 13 to Channex.

**Booking.com.** Both shared test hotels (`6519420`, `5868189`) were leased to other Channex accounts, so the connection was refused. Their mapping details show a **Single Room** with rates at occupancy `[1]`, which is where a dorm (one guest per unit) maps. A booking on the test hotel needs a person at `secure.booking.com` with Booking.com's test card. It also needs rate plans in GBP, the test hotels' currency, while Kopi Lane sells in MYR.

**Booking.com ARI (2026-11-25).** Test hotel `6519420` was free. A BookingCom channel on the Kopi Lane staging property (`49be5435-8a90-41dc-9f86-8f65856b85d2`, currency GBP from the test hotel) maps the 8-Bed Mixed Dorm's bed rate plan to the hotel's **Single Room**, standard rate, occupancy 1, primary. Activation ran `full_sync` and a `sync` event to Booking.com: result success, 2 logs, 763 ms (`GET /channel_events?filter[channel_id]=`). Channex held 13, 13, 16, 16 free beds for 10–13 Dec. So Booking.com accepts the BedAsUnit structure: one sellable unit per bed at occupancy 1. Prices go as MYR figures to a GBP test hotel; Channex does not convert, which only matters on the test hotel. `5868189` was still leased to another account.

**One room entry with several guests (2026-11-25).** A CRS booking with one room of 3 adults on the 8-Bed Mixed Dorm (`OFL-DORMMULTI-084116`, 14–16 Dec, RM 114 a night) came in as one bed with 1 adult at RM 228: taken beds went from 13 to 14, not 16. Fixed by splitting a dorm room per guest (`ChannelRevisionMapper::rooms()`). Re-sent as a modification, the booking got two more beds (16 taken). A new 2-guest booking (`OFL-DORMNEW-084731`) came in as two beds at RM 76 each, matching the OTA total. Cancelling both through Booking CRS cancelled every unit. The modification left the first bed at its old price because `ChangeNightPrice` refused it (`nationality_required`: the test guest has no nationality and Kopi Lane charges tourism tax); see [Booking ingestion › Open](05-booking-ingestion.md#open).

Still to check: how an OTA sends a multi-bed booking. The CRS booking above sent one room entry per bed by choice, so it does not show what an OTA sends.

### Confirmed in Channex docs

Checked again 2026-11-23 against the Channex documentation (Room Types, Rate Plans, Availability, Hostelworld mapping guide). BedAsUnit follows it:

| Channex docs                                                                                                                         | BedAsUnit sends                                 |
| ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------- |
| A room type is "an accommodation inventory unit — a room, villa, or dormitory bed"; "a bed at a hostel dormitory can be a Room Type" | One room type per dorm whose units are its beds |
| `count_of_rooms`: "Number of rooms (units) of this type available to sell"                                                           | The active beds                                 |
| `occ_adults`: "Number of adult bed spaces"; `occ_children`: child-only bed spaces, 0 when children use adult beds                    | 1 and 0                                         |
| `default_occupancy` is deprecated and defaults to `occ_adults`                                                                       | 1                                               |
| A per-room rate plan passes one occupancy option, for the maximum occupancy                                                          | `per_room`, one option at occupancy 1           |
| Availability is per room type and date: "the number of rooms left to sell"                                                           | The free beds                                   |
| Hostelworld: "If it is a dorm room please send price for 1 person"                                                                   | The bed price, at occupancy 1                   |

`room_kind: dorm` with `capacity` ("Number of beds in a single physical room") is Channex's own dorm kind: "A `dorm` sells beds within a shared physical room; a `room` sells the whole unit". The docs do not say how it is counted for availability or shown on each OTA, so NativeDorm stays pending.

- `room_kind`: `room` or `dorm` (Room Types Collection).
- `capacity`: count of beds in one physical room, dorm only.
- `count_of_rooms`: units to sell of this type.
- Rate plan `sell_mode`: `per_room` or `per_person` only. No `per_bed`.
- Hostelworld: send dorm price for 1 person.
- Agoda: mapping is always multi occupancy. Dorms not mentioned.
- Booking.com: occupancy-based pricing supported. Dorms not mentioned.

### Not confirmed

1. For `room_kind: dorm` (NativeDorm only): is availability counted in beds or rooms, and does `count_of_rooms` mean rooms or total beds? BedAsUnit does not depend on it.
2. Do Agoda and Booking.com accept `room_kind: dorm`?
3. Does an OTA send a multi-bed booking as one room entry with N guests, or N room entries? N entries of one adult ingest correctly (CRS test above); one entry with N adults is not tested.

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
