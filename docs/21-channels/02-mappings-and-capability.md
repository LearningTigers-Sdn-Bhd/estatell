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
channel_rate_plan_mappings    id, channel_property_id, rate_plan_id, room_type_id, external_rate_plan_id,
                              sell_mode (ChannelSellMode, how the plan was last sent), timestamps
                              unique (channel_property_id, rate_plan_id, room_type_id)
channel_ota_mappings          id, channel_connection_id, channel_rate_plan_mapping_id, occupancy,
                              ota_room_code, ota_rate_code, is_primary (nullable), rate_type (nullable), timestamps
                              unique (channel_connection_id, channel_rate_plan_mapping_id, occupancy)
```

- A rate plan mapping exists only for a plan and room type pair linked in `rate_plan_room_types` (see [Rate plans](../08-rates/01-rate-plans.md)).
- `sell_mode` is what the structure sync sent. The [ARI sync](04-ari-sync.md) builds its values from it, not from the plan as it is today, so a plan edited in estatell keeps its old shape until **Sync again** runs.
- `is_primary` and `rate_type` follow the adapter's `rate_params`: Booking.com, Agoda and Expedia use `primary_occ`, Hostelworld uses `type`.
- Dorm strategy columns are added with the dorm work, not now.

## Sell mode mapping

| estatell                               | Channex                                                                                                                                                                                                                                                                                                           |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PerRoom, price the same for any adults | `sell_mode: per_room`, one option at the maximum adults. Used when the room type holds no more adults than its base occupancy, or the plan has no extra adult amount and no price for an adult count.                                                                                                             |
| PerRoom, price depends on the adults   | `sell_mode: per_person`, one price per occupancy (1..N adults): the room price up to the base occupancy, then the extra adult amount (or the price set for that adult count). Channex `per_room` holds one price for any number of guests, so it cannot hold this. The plan is still priced per room in estatell. |
| PerGuest                               | `sell_mode: per_person`, one price per occupancy (1..N adults). Children become a flat `children_fee`.                                                                                                                                                                                                            |
| PerBed                                 | Not distributable yet: "Dorm beds are not on channels yet." See [Dorm strategies](03-dorm-strategies.md)                                                                                                                                                                                                          |
| Package, flat price                    | Price / nights, `min_stay = max_stay = fixed_nights`, closed-to-arrival on disallowed days. A per-room package with an extra adult amount is sent per person like a per-room plan.                                                                                                                                |
| Package, price varies by arrival       | Not distributable. Blocked with a reason.                                                                                                                                                                                                                                                                         |
| DayUse                                 | Not distributable: "Day use plans are sold at the front desk only." See [Day use](../12-reservations/10-day-use.md#channels)                                                                                                                                                                                      |

## Payloads

Checked against the Channex docs on 2026-10-20.

| Channex   | Sent                                                                                                                                                                                                                                |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Property  | `title`, `currency`, `timezone`, `property_type`, `country`, `state`, `city`, `address`, `zip_code`, `email`, `phone`, `group_id` (create only). Serviced apartment maps to `apart_hotel` (to verify against billing).              |
| Room type | `title`, `count_of_rooms` (active rooms), `occ_adults` (max adults), `occ_children` (max children), `occ_infants` 0, `default_occupancy` (base occupancy, at most max adults), `room_kind: room`, `content.description`.            |
| Rate plan | `title`, `currency`, `sell_mode`, `rate_mode: manual`, `options`, `children_fee` (the plan's extra child amount for a per-room plan or package, else `0.00`) and `infant_fee` `0.00`, the weekday arrays (Monday first, to verify). |

- **Independent plans.** Every plan is sent as an independent `manual` plan, derived plans included. estatell works out each price and sends it with the rates.
- **Title.** A Channex rate plan title is unique per property, so a pair is sent as `{rate plan} · {room type}`.
- **Options.** Per room: one option at the maximum adults. Per person (a per-guest plan, or a per-room plan whose price depends on the adults): one option for each adult count 1 to the maximum, the top one primary. Each starts at rate 0; prices come with [ARI sync](04-ari-sync.md).
- **Children.** `children_fee` is the plan's flat extra child amount for a per-room plan or a per-room package (every child pays it in estatell). A plan that follows another sends the amount of the plan it follows, before its own discount (to verify on staging). Per-guest plans keep `0.00`: their child prices depend on age groups and are not sent yet.
- **A plan that changes mode.** Channex refuses to change `sell_mode` on an existing plan (422 `sell_mode can't be changed`, seen on staging 2026-10-21). So when **Sync again** finds a plan that Channex holds in another mode than the one to send, it deletes it and creates it again under the same title (the delete comes first, because titles are unique per property). The Channex id in the mapping changes. Channex refuses the delete while an OTA uses the plan, and `force` is never sent, so the sync stops with that reason: unmap the plan at the OTA first. The rates of the pair are queued again for the next 500 days through the normal outbox (one `/restrictions` call, not a full sync), because the new options start at rate 0.
- **Channex is the truth for rate plans.** Each sync reads the property's rate plans once (`GET /rate_plans`, 100 a page) and finds each plan by its saved id, or by its title when the id is lost. That repairs a sync that stopped after it replaced plans but before it saved the new ids, and adopts a plan made by hand. The decision to replace comes from the mode Channex holds, not from the saved one.
- **Removed at Channex by hand.** A plan Channex no longer holds is created again. A room type that answers 404 to the update is created again.
- **Background sync.** **Sync to Channex** queues `SyncChannelStructureJob` and returns at once, because the sync makes one call for the property, each room type and each rate plan (a property with 25 plans took more than 30 s and hit PHP's time limit). The job reports its steps (property, room types, rate plans) to the cache (`ChannelSyncProgress`, one hour). The tab polls every 2 s and keeps one toast up to date ("Syncing to Channex: 12 of 26 steps"), then turns it into the result. A sync that has not reported for 15 minutes counts as lost, so the button works again. A started sync gives no flash toast; a refusal (already running, preview blocked, Channex not set up) does. The job's timeout is 600 s, and the redis `retry_after` is 660 s so a running sync is never started twice.
- **Limits.** Channex reports `max_count_of_room_types`, `max_count_of_rate_plans` and `max_count_of_occupancies` with the property (20 and 200 on staging). The preview blocks above 20 and 200. The sync stops again, after the property is saved, if Channex reports lower numbers.
- **Sync again** updates with PUT and never sends `force`, so Channex refuses an occupancy cut that an OTA mapping uses. To verify on staging: Sync again does not reset rates already sent.

## Service

`ChannelCapability`: returns Full, Flattened or Unsupported (`ChannelSupport`) with a reason for each room type and rate plan pair. The admin sync preview, the admin mapping step and the owner's "What is on channels" list all show the same reasons. For a Day use plan it returns `PricingBasis::channelRefusal()`. For a Bed room type it returns "Dorm beds are not on channels yet." until the dorm work. A room type with no active rooms, and a package with no price or a price that varies by arrival, are not sent either.

## Structure sync

`SyncChannelStructure` (admin, **Sync to Channex** and **Sync again**):

1. Create the Channex property in the platform group when `channel_properties` has none.
2. Create or update a Channex room type for each active room type with a Full or Flattened pair.
3. Create or update a Channex rate plan for each Full or Flattened pair.
4. Save the Channex IDs in the mapping tables. Running it again updates, never duplicates. A second click while a sync runs is refused.
5. On the first sync, queue the 500-day full sync (see [ARI sync](04-ari-sync.md#full-sync)). The queue call is a marked no-op until the ARI commit.

`ChannelStructurePreview` (read) returns the same list before anything is sent, and a blocking reason when nothing can be sent or the property is over the limits.

The ids Channex returns are saved after every call that works. If the sync stops part-way (a 422, a 429, an outage), the ids so far are kept, the property shows **Error** with the reason, and the next run updates instead of duplicating. A failure before the Channex property exists saves nothing.

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

A room type or rate plan that Channex knows about is not archived blindly. `ChannelArchiveGuard` checks the mapping tables, in the Form Request and again in the Action.

An admin removes the item from Channex first, on the Channels tab under **On Channex** (`RemoveChannelRatePlan`, `RemoveChannelRoomType`, behind `support.2fa` and logged). A room type is removed only after its rate plans. Channex refuses a removal while an OTA uses the item, and then nothing changes. Removal never sends `force`.

| State                               | Archive                                                                                                         |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Not synced                          | Normal                                                                                                          |
| Synced, not mapped on an active OTA | ":name is on the channel manager. Ask the estatell team to remove it there first." Remove it, then archive here |
| Mapped on an active OTA (item 5)    | Refused: "This rate plan is mapped on Agoda. Ask the estatell team to remove the mapping first."                |

The same guard applies to unlinking a room type from a rate plan. See [Rate plan guards](../08-rates/01-rate-plans.md#guards).

## Lessons from wastays

`channel_mappings` was polymorphic with weak foreign keys. `onboard_hotel` created the property, room types and rate plans, then retired mappings Channex could not support; `ChannelCapability` decides that before anything is sent.
