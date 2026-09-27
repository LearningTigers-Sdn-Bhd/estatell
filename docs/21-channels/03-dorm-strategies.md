# Dorm strategies

## Purpose

Channex has no per-bed sell mode. It has `room_kind: dorm` with `capacity` (beds per room), but its behaviour for availability and on Agoda and Booking.com is not documented. estatell supports two strategies, chosen by platform admins.

## Enums

`DormStrategy`: BedAsUnit, NativeDorm.

## Strategies

|                   | BedAsUnit (default)                                                                         | NativeDorm                                    |
| ----------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------- |
| Channex room type | `room_kind: room`, `count_of_rooms = total beds`, `occ_adults = 1`, `default_occupancy = 1` | `room_kind: dorm`, `capacity = beds per room` |
| Rate plan         | `per_room`, occupancy 1, price = bed price                                                  | Price for 1 person                            |
| Availability sent | Free beds                                                                                   | Per staging results                           |
| Incoming booking  | 1 room entry = 1 unit                                                                       | Per staging results                           |
| Works on          | All OTAs                                                                                    | Verified OTAs only                            |

Interface `DormChannelStrategy`: `roomTypePayload()`, `ratePlanPayload()`, `availabilityFor()`, `unitsFromRoomEntry()`. Implementations: `BedAsUnitStrategy`, `NativeDormStrategy`.

## Where the setting lives

| Level            | Column                                     | Set by         |
| ---------------- | ------------------------------------------ | -------------- |
| Platform default | `platform_settings.default_dorm_strategy`  | Platform admin |
| Property         | `channel_connections.dorm_strategy`        | Platform admin |
| Room type        | `channel_room_type_mappings.dorm_strategy` | Platform admin |

`DormStrategyResolver`: room type mapping > connection > platform default. Applies only to `sell_unit = Bed`. Owners see the active strategy read-only.

## Guardrails

- NativeDorm is blocked if the property is connected to any OTA where `OtaChannel::supportsNativeDorm()` is false. One Channex room type feeds every connected OTA.
- NativeDorm is disabled ("pending verification") until the [staging checks](06-staging-verification.md) pass.
- `ChangeDormStrategy` (admin only): confirm with warning > pause sync for the room type > recreate Channex room type and rate plans > update mappings > 365-day full refresh > activity log with reason > `DormStrategyChanged` notification to the owner.
- Existing OTA bookings are unaffected. Revisions match by booking ID.

## Future

A "Both" strategy: two Channex room types from one bed pool (NativeDorm for Hostelworld, BedAsUnit for others), sent the same availability.
