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
- `OtaChannel`: Hostelworld, BookingCom, Agoda, and others, with `supportsNativeDorm()`.

## Rules

- The Channex API key lives in platform config, not per property.
- estatell never stores OTA extranet passwords. OTAs are connected inside Channex.

## Driver design

- `ChannelManagerDriver` interface: `syncStructure`, `pushAri`, `fetchRevisions`, `acknowledge`.
- A Laravel `Manager` class selects the driver per provider.
- Channex pieces: `ChannexClient` (HTTP client with retries and rate limiting), `ChannexStructureMapper`, `ChannexAriPayloadBuilder`, `ChannexBookingMapper`.

## Actions

`ConnectChannel`, `PauseChannel`, `DisconnectChannel`.

## Permissions

`channel.manage`, `channel.view-errors`.

## Lessons from wastays

`ChannexAdapter` was 1,464 lines. `hotel_ota_credentials` stored OTA usernames and passwords.
