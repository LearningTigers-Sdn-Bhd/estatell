# Mappings and capability

## Purpose

Create the property's structure in Channex, and link Channex rooms and rates to each OTA's rooms and rates.

## Two layers of mapping

```
estatell                      Channex                        OTA (for example Agoda)
room_types          ──▶  room types          ┐
plan × room type    ──▶  rate plans          ├──▶  room code · rate code · occupancy
                         (structure sync)    ┘     (Channel API, per connection)
```

## Data model

```
channel_room_type_mappings    id, channel_property_id, room_type_id, external_room_type_id, timestamps
                              unique (channel_property_id, room_type_id)
channel_rate_plan_mappings    id, channel_property_id, rate_plan_id, room_type_id, external_rate_plan_id, timestamps
                              unique (channel_property_id, rate_plan_id, room_type_id)
channel_ota_mappings          id, channel_connection_id, channel_rate_plan_mapping_id, occupancy,
                              ota_room_code, ota_rate_code, is_primary (nullable), rate_type (nullable), timestamps
                              unique (channel_connection_id, channel_rate_plan_mapping_id, occupancy)
```

- A rate plan mapping exists only for a plan and room type pair linked in `rate_plan_room_types` (see [Rate plans](../08-rates/01-rate-plans.md)).
- `is_primary` and `rate_type` follow the adapter's `rate_params`: Booking.com, Agoda and Expedia use `primary_occ`, Hostelworld uses `type`.
- Dorm strategy columns are added with the dorm work, not now.

## Sell mode mapping

| estatell                         | Channex                                                                                                                      |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| PerRoom                          | `sell_mode: per_room`                                                                                                        |
| PerGuest                         | `sell_mode: per_person`, one price per occupancy (1..N adults). Children become a flat `children_fee`.                       |
| PerBed                           | Not distributable yet: "Dorm beds are not on channels yet." See [Dorm strategies](03-dorm-strategies.md)                     |
| Package, flat price              | Price / nights, `min_stay = max_stay = fixed_nights`, closed-to-arrival on disallowed days                                   |
| Package, price varies by arrival | Not distributable. Blocked with a reason.                                                                                    |
| DayUse                           | Not distributable: "Day use plans are sold at the front desk only." See [Day use](../12-reservations/10-day-use.md#channels) |

## Service

`ChannelCapability`: returns Full, Flattened or Unsupported with a reason for each room type and rate plan pair. The admin sync preview, the admin mapping step and the owner's "What is on channels" list all show the same reasons. For a Day use plan it returns `PricingBasis::channelRefusal()`. For a Bed room type it returns "Dorm beds are not on channels yet." until the dorm work.

## Structure sync

`SyncChannelStructure` (admin, **Sync to Channex** and **Sync again**):

1. Create the Channex property in the platform group when `channel_properties` has none.
2. Create or update a Channex room type for each active room type with a Full or Flattened pair.
3. Create or update a Channex rate plan for each Full or Flattened pair.
4. Save the Channex IDs in the mapping tables. Running it again updates, never duplicates.
5. On the first sync, queue the 500-day full sync (see [ARI sync](04-ari-sync.md#full-sync)).

`ChannelStructurePreview` (read) returns the same list before anything is sent.

## OTA mapping (Channel API)

```
GET  /channels/adapter?code={channex_code}   fields (params) and mapping columns (rate_params)
POST /channels/test_connection               the OTA sees Channex as provider for this hotel ID
POST /channels/mapping_details               the OTA's rooms and rates
POST /channels                               group_id, properties, settings, rate_plans[]; created inactive
PUT  /channels/{id}                          mapping changes
POST /channels/{id}/activate                 full sync to the OTA
POST /channels/{id}/deactivate               pause
```

- `ConnectChannel` tests the connection and creates the Channex channel with the admin's settings.
- `SaveChannelMapping` sends `rate_plans[]` built from `channel_ota_mappings` and stores them.
- `ActivateChannel` refuses a connection with no mapped rate plan.
- An OTA room or rate with no match shows a warning, because Channex says unmapped OTA rates cause booking errors.

## Archive guard

A room type or rate plan that Channex knows about is not archived blindly.

| State                               | Archive                                                                                          |
| ----------------------------------- | ------------------------------------------------------------------------------------------------ |
| Not synced                          | Normal                                                                                           |
| Synced, not mapped on an active OTA | Remove it from Channex first, then archive here                                                  |
| Mapped on an active OTA             | Refused: "This rate plan is mapped on Agoda. Ask the estatell team to remove the mapping first." |

The same guard applies to unlinking a room type from a rate plan. See [Rate plan guards](../08-rates/01-rate-plans.md#guards).

## Lessons from wastays

`channel_mappings` was polymorphic with weak foreign keys. `onboard_hotel` created the property, room types and rate plans, then retired mappings Channex could not support; `ChannelCapability` decides that before anything is sent.
