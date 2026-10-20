# Connections and feature gate

## Purpose

Let properties sell on Agoda, Booking.com and other OTAs through Channex. The estatell team sets up every channel. Owners see the status read-only.

## Scope

| Now                                               | Later                                                           |
| ------------------------------------------------- | --------------------------------------------------------------- |
| Room and Guest room types                         | Dorm beds (WIP, see [Dorm strategies](03-dorm-strategies.md))   |
| Booking.com, Agoda, Hostelworld, Expedia          | Airbnb (OAuth, listing mapping, many properties per connection) |
| Admins do the whole setup through the Channel API | Owner self-serve behind `channel.manage`                        |
| Owners: read-only Channels page and help requests | –                                                               |
| Bookings without card details                     | Cards through Channex's PCI service                             |

The Channel IFrame is not used. Admins do not sign in to Channex by hand for the OTAs above.

## Feature gate

- Pennant feature `ChannelManager`, off by default (see [Feature flags](../01-platform/03-feature-flags.md)).
- When off, every channel route returns 404, like `Boats`. There is no upsell page and no dashboard card.
- When on, the admin property page shows a **Channels** tab ([Admin Channels tab](07-admin-channels-tab.md)) and the owner gets **Settings → Channels** ([Owner Channels page](08-owner-channels-page.md)).
- Turning the flag on sends nothing to Channex. The first send is **Sync to Channex**.

## Flow

```
 0 Onboarding     Owner adds OTA logins in OTA lists (reference for the team)
 1 Flag on        Admin › property › Features › Channel manager
 2 Sync           Preview, then Sync to Channex: property, room types, rate plans
 3 Full sync      500 days in 2 calls, then changes only
 4 Extranet       Admin signs in to the OTA with the revealed login (or remote desktop)
                  and chooses Channex as the connectivity provider.
                  Blocked? Ask owner for help.
 5 Connect        Pick OTA, fill the adapter fields, Test connection
 6 Map            Fetch OTA rooms and rates, map room · rate · occupancy
 7 Activate       Channex does a full sync to the OTA
 8 Bookings       Webhook, store revision, save reservation, then acknowledge
```

## Who does what

| Role           | Does                                                                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Platform admin | Turns on the flag, syncs, does the OTA extranet step, connects, maps, activates, pauses, disconnects, runs a full refresh, fixes alerts, asks owner for help |
| Owner          | Adds OTA logins, answers help requests (login, sign-in code, approval, removing an OTA room or rate), sees status                                            |

## Data model

```
channel_properties      id, property_id (unique), provider (ChannelProvider), external_property_id,
                        status (ChannelPropertyStatus), paused_until (nullable),
                        structure_synced_at, changes_sent_at, full_synced_at, last_error, timestamps

channel_connections     id, channel_property_id, ota_channel_id, external_channel_id (nullable),
                        settings (jsonb, the adapter params sent to Channex),
                        status (ChannelConnectionStatus), activated_at, last_error, timestamps
                        unique (channel_property_id, ota_channel_id)

channel_help_requests   id, channel_connection_id, reason (ChannelHelpReason), message,
                        requested_by, resolved_at (nullable), resolved_by (nullable), timestamps
```

- One `channel_properties` row per property: the Channex property and its sync state.
- One `channel_connections` row per OTA on that property.
- `settings` jsonb is the provider payload we sent, so it is allowed. It never holds OTA passwords. To confirm when built: whether any adapter param must be columns instead.
- `channel_connections` does not link to `property_ota_credentials`. OTA lists stays a reference.

## Enums

- `ChannelProvider`: Channex.
- `ChannelPropertyStatus`: NotSynced, Synced, Paused, Error.
- `ChannelConnectionStatus`: Draft, Mapped, Active, Paused, Error.
- `ChannelHelpReason`: LoginWrong, SignInCode, ExtranetApproval, RemoveRoomOrRate, Other.
- `ChannelOwnerStatus`: Waiting, Connecting, Live, Paused. Worked out from the connection, never stored (see [Owner Channels page](08-owner-channels-page.md#statuses)).
- `OtaCredentialStatus`: Pending, Processed.

## OTA channels

```
ota_channels   id, code (unique, lower), name, channex_code (nullable), sort_order, is_active, is_other, timestamps
```

- Seeded: Agoda (`Agoda`), Booking.com (`BookingCom`), Hostelworld (`Hostelworld`), Expedia (`Expedia`), Airbnb (`AirBNB`) and **Other** (no code; `is_other`, cannot be renamed or deactivated).
- `channex_code` is the Channex adapter code from `GET /channels/list`. A channel without one shows "No Channex adapter. Connect in Channex by hand." Airbnb shows "Connect in Channex by hand (coming later)" until it is built.
- Admins manage the list on the admin **Channels** page: add, rename, reorder, deactivate and set the Channex code. A channel used by a credential or a connection is never deleted. A deactivated channel stays on existing rows and leaves the owner's select.
- Other keeps owners unblocked: the row asks for a **Channel name**. An admin can add a common one as a real channel later and move rows to it by hand.

## Rules

- One platform Channex account and one Channex group ("User Group" on staging). Config: `CHANNEX_BASE_URL`, `CHANNEX_API_KEY`, `CHANNEX_GROUP_ID`. Never per property.
- Owners hand over OTA logins in [onboarding](../17-onboarding/02-wizard-steps.md#channel-manager-table). They are stored in `property_ota_credentials`, encrypted, with no feature gate.
- OTA lists is a **reference**. The Connect form pre-fills the hotel ID from its Property ID and shows the row in a rail. Nothing writes back to it.
- Only admins read the logins: **Reveal** is a POST returning JSON, needs `support.2fa` and is logged. No code uses them. Admins use them to sign in to the OTA extranet.
- Owners never see the word Channex as a task. Their copy says "the estatell team connects your channels".

## Driver design

- `ChannelManagerDriver` interface: `syncStructure`, `removeRatePlan`, `removeRoomType`, `pushRestrictions`, `pushAvailability`, `fetchRevisions`, `acknowledge`, plus the Channel API calls `adapter`, `testConnection`, `mappingDetails`, `createChannel`, `updateChannel`, `activate`, `deactivate`, `loadFutureReservations`.
- `ChannelDriverManager` (a Laravel `Manager`) selects the driver per provider. `ChannexDriver` builds `adapter`, `syncStructure`, `removeRatePlan` and `removeRoomType`. Its other methods throw "Not built yet" until the commit that needs them.
- Config lives in `config/services.php` under `channex`. The default base URL is staging; production is `https://app.channex.io/api/v1`.
- The `channels` middleware alias (`EnsureChannelManagerIsActive`) returns 404 when the flag is off.
- Channex pieces: `ChannexClient` (HTTP client, limiter, backoff; see [ARI sync](04-ari-sync.md#limits-and-failures)), `ChannexStructureMapper`, `ChannexAriPayloadBuilder`, `ChannexBookingMapper`, `ChannexChannelMapper`.

## Actions

`SyncChannelStructure`, `RemoveChannelRatePlan`, `RemoveChannelRoomType`, `RefreshChannelAri`, `PauseChannelProperty`, `ConnectChannel`, `SaveChannelMapping`, `ActivateChannel`, `PauseChannel`, `DisconnectChannel`, `ImportFutureBookings`, `RequestOwnerHelp`, `ResolveOwnerHelp`.

## Permissions

| Permission       | Who                                         | Gives               |
| ---------------- | ------------------------------------------- | ------------------- |
| `channel.view`   | Owner and Manager roles by default          | Settings → Channels |
| `channel.manage` | No role yet (reserved for owner self-serve) | –                   |

Admin screens use `can:admin`, and every write needs `support.2fa`.

## Lessons from wastays

`ChannexAdapter` was 1,443 lines. `hotel_ota_credentials` stored OTA usernames and passwords. estatell keeps the idea as `property_ota_credentials` (hand-over only), but with encryption, a logged reveal and no use in code. wastays connected OTAs by hand in Channex; estatell uses the Channel API from its own admin.
