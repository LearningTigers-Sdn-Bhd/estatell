# Dorm strategies

> **BedAsUnit built** (2026-11-23). NativeDorm is refused ("Native dorms on channels are pending verification.") until the [staging checks](06-staging-verification.md#dorm-checks-wip) pass. The property and room type override columns below come with NativeDorm; until then every dorm uses the platform default, and BedAsUnit whenever that default is not yet available.

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
| Incoming booking  | 1 bed per guest of a room entry (a room of 3 guests is 3 units)                             | Per staging results                           |
| Works on          | All OTAs                                                                                    | Verified OTAs only                            |

Interface `DormChannelStrategy` (`app/Services/Channels/Dorm/`): `refusal()`, `roomKind()`, `adults()`, `children()`. Implementations: `BedAsUnitStrategy`, `NativeDormStrategy`, chosen by `DormStrategy::strategy()` and `DormStrategyResolver`.

## How BedAsUnit is wired

| Part                                                                      | What changes for a dorm (`sell_unit = Bed`)                                                                                                                                                                                                      |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ChannelCapability::forPair()`                                            | Its per-bed plans go in full; any other plan is refused ("On channels a dorm sells its per-bed plans only."). A per-bed plan on a room type that sells rooms is refused. A dorm with no active bed is refused ("This dorm has no active beds."). |
| `ChannelCapability::activeRoomCount()`                                    | The active beds of its active rooms (`active_beds_count` from `ChannelStructurePreview`, one query)                                                                                                                                              |
| `ChannelCapability::channelAdults()` / `channelChildren()` / `roomKind()` | 1, 0 and `room` from the strategy; every other place that sent `max_adults` (structure payload, rate plan options, ARI prices, mapping rows) asks these                                                                                          |
| `ChannelCapability::sellMode()`                                           | Always per room                                                                                                                                                                                                                                  |
| ARI                                                                       | The price for occupancy 1 is the bed price (the per-bed pricing strategy); availability is already the free beds (`AvailabilityService` counts beds for Bed room types)                                                                          |
| `ChannelRevisionMapper::units()`                                          | A room of an OTA booking for a dorm is one bed for one guest: 1 adult, no children, the OTA price as the override total                                                                                                                          |

## Where the setting lives

| Level            | Column                                     | Set by         |
| ---------------- | ------------------------------------------ | -------------- |
| Platform default | `platform_settings.default_dorm_strategy`  | Platform admin |
| Property         | `channel_properties.dorm_strategy`         | Platform admin |
| Room type        | `channel_room_type_mappings.dorm_strategy` | Platform admin |

`DormStrategyResolver`: room type mapping > Channex property > platform default. Applies only to `sell_unit = Bed`. Owners see the active strategy read-only.

## Guardrails

- NativeDorm is blocked if the property is connected to any OTA whose `ota_channels` row does not support native dorms (a column added with this item, set from the staging results). One Channex room type feeds every connected OTA.
- NativeDorm is disabled ("pending verification") until the [staging checks](06-staging-verification.md) pass.
- `ChangeDormStrategy` (admin only): confirm with warning > pause sync for the room type > recreate Channex room type and rate plans > update mappings > 500-day full refresh > activity log with reason > `DormStrategyChanged` notification to the owner.
- Existing OTA bookings are unaffected. Revisions match by booking ID.

## Future

A "Both" strategy: two Channex room types from one bed pool (NativeDorm for Hostelworld, BedAsUnit for others), sent the same availability.
