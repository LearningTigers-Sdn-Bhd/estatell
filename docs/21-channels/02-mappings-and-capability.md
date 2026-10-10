# Mappings and capability

## Data model

```
channel_room_type_mappings    id, channel_connection_id, room_type_id, external_room_type_id,
                              dorm_strategy (DormStrategy, nullable)
                              unique (channel_connection_id, room_type_id)
channel_rate_plan_mappings    id, channel_connection_id, rate_plan_id, room_type_id, external_rate_plan_id
                              unique (channel_connection_id, rate_plan_id, room_type_id)
```

A rate plan mapping exists only for a plan and room type pair linked in `rate_plan_room_types` (see [Rate plans](../08-rates/01-rate-plans.md)).

## Sell mode mapping

| estatell                         | Channex                                                                                                                      |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| PerRoom                          | `sell_mode: per_room`                                                                                                        |
| PerGuest                         | `sell_mode: per_person`, one price per occupancy (1..N adults). Children become a flat `children_fee`.                       |
| PerBed                           | See [Dorm strategies](03-dorm-strategies.md)                                                                                 |
| Package, flat price              | Price / nights, `min_stay = max_stay = fixed_nights`, closed-to-arrival on disallowed days                                   |
| Package, price varies by arrival | Not distributable. Blocked with a reason.                                                                                    |
| DayUse                           | Not distributable: "Day use plans are sold at the front desk only." See [Day use](../12-reservations/10-day-use.md#channels) |

## Service

`ChannelCapability`: returns Full, Flattened or Unsupported with a reason for each room type and rate plan pair. The mapping screen shows it.

## Actions

`SyncChannelStructure`, `MapRoomType`, `MapRatePlan`.

## Lessons from wastays

`channel_mappings` was polymorphic with weak foreign keys.
