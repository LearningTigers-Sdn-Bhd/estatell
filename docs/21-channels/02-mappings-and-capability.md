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
- **A plan that changes mode.** Channex refuses to change `sell_mode` on an existing plan (422 `sell_mode can't be changed`, seen on staging 2026-10-21). So when **Sync again** finds a plan that Channex holds in another mode than the one to send, it deletes it and creates it again under the same title (the delete comes first, because titles are unique per property). The Channex id in the mapping changes. A plan that a channel maps is not deleted (see the archive guard), and `force` is never sent, so the sync stops with that reason: unmap the plan at the OTA first. The rates of the pair are queued again for the next 500 days through the normal outbox (one `/restrictions` call, not a full sync), because the new options start at rate 0.
- **Channex is the truth for rate plans.** Each sync reads the property's rate plans once (`GET /rate_plans`, 100 a page) and finds each plan by its saved id, or by its title when the id is lost. That repairs a sync that stopped after it replaced plans but before it saved the new ids, and adopts a plan made by hand. The decision to replace comes from the mode Channex holds, not from the saved one.
- **Removed at Channex by hand.** A plan Channex no longer holds is created again. A room type that answers 404 to the update is created again.
- **Background sync.** **Sync to Channex** queues `SyncChannelStructureJob` and returns at once, because the sync makes one call for the property, each room type and each rate plan (a property with 25 plans took more than 30 s and hit PHP's time limit). The job reports its steps (property, room types, rate plans) to the cache (`ChannelSyncProgress`, one hour). The tab polls every 2 s and keeps one toast up to date ("Syncing to Channex: 12 of 26 steps"), then turns it into the result. A sync that has not reported for 15 minutes counts as lost, so the button works again. A started sync gives no flash toast; a refusal (already running, preview blocked, Channex not set up) does. The job's timeout is 600 s, and the redis `retry_after` is 660 s so a running sync is never started twice.
- **Limits.** Channex reports `max_count_of_room_types`, `max_count_of_rate_plans` and `max_count_of_occupancies` with the property (20 and 200 on staging). The preview blocks above 20 and 200. The sync stops again, after the property is saved, if Channex reports lower numbers.
- **Sync again** updates with PUT and never sends `force`, so Channex refuses an occupancy cut that an OTA mapping uses. To verify on staging: Sync again does not reset rates already sent.

## Automatic sync after staff change something

A change that the Channex structure shows sends the structure again by itself, so an admin does not have to press **Sync again**:

- A rate plan is created or restored, or its name, pricing basis, extra adult or child amount, the plan it follows, fixed nights or package unit change.
- A room type is created or restored, or its name, description, adults, children, base occupancy or sell unit change.
- The room types a rate plan sells change, or its package pricing is saved.

Each such change fires `ChannelStructureChanged`. `ScheduleChannelStructureSync` then queues `AutoSyncChannelStructureJob` with a token and a 2-minute delay, and stores the token in the cache. A later change replaces the token, so only the job of the last change runs: edits in a row make one sync, and the staff are never blocked. The job is the same sync as the admin's (progress, the replacement of a plan that changes mode, rates queued again), without an admin as the causer in the activity log.

It does nothing for a property that is not yet on Channex (an admin starts the first sync) or that has `ChannelManager` off. It waits a minute and tries again while another sync is running. A failure records a **Structure sync failed** alert on the Channels tab. The tab shows "Changes are waiting to sync" during the 2 minutes. Prices, restrictions and availability are not part of this: they follow the outbox in [ARI sync](04-ari-sync.md). Removals are not automatic here either: they go through the archive guard above.

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
POST /channels/test_connection               {channel, settings}: the OTA sees Channex as provider for this hotel ID
POST /channels/mapping_details               {channel, settings}: the OTA's rooms and rates (no property or channel id)
POST /channels                               group_id, properties, settings, rate_plans[]; created inactive
PUT  /channels/{id}                          mapping changes
POST /channels/{id}/activate                 full sync to the OTA
POST /channels/{id}/deactivate               pause
```

- `ConnectChannel` tests the connection and creates the Channex channel with the admin's settings. The body is wrapped in `channel`: `{channel, group_id, title, properties: [external property id], settings}`. It saves a `Draft` connection.
- Only visible adapter params are sent. `hidden` params (`machine_account`, the payout flags) are Channex's own. A param a rule hides (Property email while Send booking emails is off) is dropped.
- Channex allows one connection per hotel ID on the whole account. A hotel ID used by another property is refused at create, and nothing is saved here.
- The channel currency comes from the OTA hotel. Channex converts prices when it pushes, so a MYR property can map to a GBP hotel.
- `SaveChannelMapping` reads the OTA's rooms and rates again, so a line that names a room or rate the OTA does not have is refused. It sends the whole mapping as `rate_plans[]` in a `PUT /channels/{id}` that has the same shape as the create body (Channex replaces the stored set), and saves `channel_ota_mappings` only after Channex accepted it. An OTA line with no estatell plan is left out. No line at all puts a `Mapped` connection back to `Draft`. An `Active` or `Paused` connection keeps its status.
- The map page starts from the OTA: one line for each room, rate and guest count the OTA lists (highest guest count first, grouped by OTA room), and the admin picks an estatell plan for each. A line can take only a plan occupancy with the same guest count: one at the top adult count for a per-room plan, one for each adult count for a per-person plan (the options the structure sync sends, `ChannelSellMode::occupancies`). An OTA rate that lists no guest counts takes the plan's top occupancy. One plan occupancy can take several OTA lines (Booking.com's standard and non-refundable rates can both map to one plan), as Channex shows. An OTA line is mapped once: `channel_ota_mappings` is unique on the connection, OTA room, OTA rate and guest count.
- Where the adapter lists `primary_occ`, each OTA room and rate has exactly one primary line: the flagged line with the highest occupancy, else the highest occupancy. Where it lists `type` (Hostelworld), the line carries a type and no primary flag. The code sends only the `rate_params` the adapter lists, and `pricing_type` and `readonly` come from the OTA's own rate (to verify for adapters other than Booking.com and Expedia).
- Lines are pre-filled when an OTA room title and rate title equal a room type name and a rate plan name (ignoring case and punctuation) at the same guest count. A saved mapping beats suggestions.
- `ActivateChannel` refuses a connection with no mapped rate plan.
- An OTA line with no estatell plan shows a warning with the count, because Channex says unmapped OTA rates cause booking errors.

## Archive guard

A room type or rate plan that Channex knows about is not archived blindly. `ChannelArchiveGuard` checks the mapping tables.

Staff archive as usual. When the item is on Channex, the request does not archive it at once. It queues `ChannelRemovalJob` (`QueueChannelRemoval`) and tells staff: "…is on the channels. It is being removed there first and the change applies in a moment." The job removes it from Channex (`RemoveChannelRatePlan`, `RemoveChannelRoomType`; a room type only after its rate plans; never `force`), and only then archives it as the staff member (`ArchiveRatePlan`, `ArchiveRoomType`). The Actions still refuse an item that is on Channex, as a last check.

| State                                          | Archive                                                                                                                                                                                                               |
| ---------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Not synced                                     | Normal, at once                                                                                                                                                                                                       |
| Synced                                         | Queued: removed from Channex, then archived. Usually a few seconds.                                                                                                                                                   |
| An OTA channel maps it (our check)             | Not archived. The item stays active and on sale. The Channels tab gets a **Removal blocked** alert with the reason, and the staff member who asked gets the `ChannelRemovalBlocked` notification (in-app, mandatory). |
| A rate limit or outage                         | The job tries again after 2 minutes, up to 5 tries. Nothing changes meanwhile.                                                                                                                                        |
| Mapped on an active OTA (the OTA mapping item) | Refused the same way, with the OTA named.                                                                                                                                                                             |

**Channex does not refuse by itself.** Its docs say it will not delete a rate plan that a channel maps (unless `force` is sent), but on staging (2026-10-21) it deleted a plan that its Booking.com channel mapped, and the OTA mapping was lost. So the check is ours: before any delete the driver reads the property's channels (`GET /channels`) and refuses a plan that any channel lists in its `rate_plans` mappings (a 409 that names the channel), and it refuses when it cannot find the plan's property. The check follows children too: when the Channex dashboard maps one occupancy of a per-person plan, Channex makes a hidden derived child plan of ours (one occupancy, `relationships.parent_rate_plan` is ours, and no list of rate plans shows it) and maps the OTA rate to the child. For each id a channel maps, the driver reads the plan and follows its parents up to ours (at most 5 steps). The same check guards the replacement of a plan that changes sell mode. A plan mapped on a channel is therefore never deleted: the admin unmaps it at the OTA first.

The archive waits for the removal, because an item that was archived in estatell but is still bookable on an OTA would be worse than a few seconds of waiting. The same removal cannot be queued twice (10 minutes). The staff member who asked sees the result without refreshing: the request records the removal as pending in the cache (`RecordChannelRemovalProgress`, 15 minutes), the shared Inertia prop `channelRemovals` carries the staff member's own entries, the staff layout polls it every 2 seconds while one is pending, and when it ends it shows a toast ("…archived.", or "…was not archived" with why) and reloads the page so the list is right. The admin tab's **Remove** buttons stay as a fallback for an item the automatic removal could not clear.

Unlinking a room type from a rate plan follows the same steps for the pair: the pair is removed from Channex first, then the link changes. See [Rate plan guards](../08-rates/01-rate-plans.md#guards).

## Lessons from wastays

`channel_mappings` was polymorphic with weak foreign keys. `onboard_hotel` created the property, room types and rate plans, then retired mappings Channex could not support; `ChannelCapability` decides that before anything is sent.
