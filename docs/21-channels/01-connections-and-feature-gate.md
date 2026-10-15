# Connections and feature gate

## Purpose

Let properties connect to Agoda, Booking.com and other OTAs through Channex, when the platform enables it.

## Feature gate

- Pennant feature `ChannelManager`, off by default (see [Feature flags](../01-platform/03-feature-flags.md)).
- Channel routes use `EnsureFeaturesAreActive::using(ChannelManager::class)`.
- When off, the dashboard shows a "Connect Agoda / Booking.com" card. It records a request on the admin board.
- When on, it appears in the Recommended checklist after go-live.

## Data model

```
channel_connections    id, property_id, provider (ChannelProvider), external_property_id,
                       status (ChannelConnectionStatus), dorm_strategy (DormStrategy, nullable),
                       connected_at, last_synced_at, last_error, timestamps
```

## Enums

- `ChannelProvider`: Channex.
- `ChannelConnectionStatus`: Pending, Active, Paused, Error.
- `OtaCredentialStatus`: Pending, Processed.

`OtaChannel` is no longer an enum. Channels are rows in `ota_channels` (see [OTA channels](#ota-channels)). `supportsNativeDorm()` moves to the dorm work and is decided there.

## Rules

- The Channex API key lives in platform config, not per property.
- All properties use the platform Channex account. Owners do not need their own channel manager.
- Owners hand over OTA extranet logins in [onboarding](../17-onboarding/02-wizard-steps.md#channel-manager-table). They are stored in `property_ota_credentials`, encrypted, with no feature gate, because nothing connects from that page.
- Only admins read the logins: **Reveal** is a POST returning JSON, needs `support.2fa` and is logged. No code uses them. The team signs in to the OTA inside Channex by hand and marks the row Processed.
- `channel_connections` may link to a credential row later. The onboarding form does not change.

## OTA channels

```
ota_channels   id, code (unique, lower), name, sort_order, is_active, is_other, timestamps
```

- Seeded: Agoda, Booking.com, Hostelworld, Expedia, Airbnb and **Other** (`is_other`, cannot be renamed or deactivated).
- Admins manage the list on the admin **Channels** page: add, rename, reorder and deactivate. A channel used by a credential is never deleted. A deactivated channel stays on existing rows and leaves the owner's select.
- Other keeps owners unblocked: the row asks for a **Channel name**. An admin can add a common one as a real channel later and move rows to it by hand.

## Driver design

- `ChannelManagerDriver` interface: `syncStructure`, `pushAri`, `fetchRevisions`, `acknowledge`.
- A Laravel `Manager` class selects the driver per provider.
- Channex pieces: `ChannexClient` (HTTP client with retries and rate limiting), `ChannexStructureMapper`, `ChannexAriPayloadBuilder`, `ChannexBookingMapper`.

## Actions

`ConnectChannel`, `PauseChannel`, `DisconnectChannel`.

## Permissions

`channel.manage`, `channel.view-errors`.

## Lessons from wastays

`ChannexAdapter` was 1,464 lines. `hotel_ota_credentials` stored OTA usernames and passwords. estatell keeps the idea as `property_ota_credentials` (hand-over only), but with encryption, a logged reveal and no use in code.
