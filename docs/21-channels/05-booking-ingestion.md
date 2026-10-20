# Booking ingestion (inbound)

## Purpose

Turn OTA bookings into estatell reservations, exactly once, and acknowledge them to Channex only after they are saved.

## Data model

```
channel_booking_revisions    id, channel_property_id, channel_connection_id (nullable), external_booking_id,
                             revision_id (unique), kind (RevisionKind), payload (jsonb, raw),
                             processed_at, acknowledged_at, reservation_id (nullable), error, created_at
```

## Enums

`RevisionKind`: New, Modified, Cancelled.

## Flow

```
Channex webhook (booking event) ──▶ store revision (unique revision_id)
scheduled pull of the booking revisions feed (fallback)
        ▼
IngestChannelBooking ── CreateReservation(fromChannel: true), booking source, unit_nights
        ├─ saved  → acknowledge to Channex → acknowledged_at → notify reservation staff
        └─ failed → keep error, no acknowledgement → admin Alerts
                    → Fix mapping or Retry → saved → acknowledge
```

1. The webhook (`POST /webhooks/channex`) or the feed pull stores the revision. Duplicates are ignored by the unique `revision_id`.
2. `IngestChannelBooking` maps the revision:
    - One OTA booking with 3 rooms = 1 reservation with 3 units.
    - Guests go through `GuestMatcher`.
    - Prices are stored on `unit_nights`.
    - `EnsureOtaBookingSource` gives the property's OTA [booking source](../12-reservations/11-booking-sources.md) for the revision's channel, and creates it when missing. The reservation gets its `booking_source_id` and `external_reference`. Manual and ingested bookings for the same OTA share one source.
    - Modified and Cancelled revisions update or cancel the matching units.
3. Only a saved revision is acknowledged. An acknowledged booking is in estatell.
4. A failed revision keeps `error`, is not acknowledged, and shows on the admin Channels tab and the night audit readiness panel. Channex then also sends `non_acked_booking`.

## Rules

- Use the booking revisions feed and acknowledgement. Never the `GET /bookings` endpoints.
- OTA bookings are always accepted. Over-capacity nights are flagged as overbooked.
- No card details are stored. OTA-collected payments use the `OtaCollected` payment method.
- `payload` jsonb is allowed: it is a raw provider payload.
- `IngestChannelBooking` creates reservations through `CreateReservation` (see [Group bookings](../12-reservations/02-group-bookings.md#creating-reservations-and-units)). Ingestion passes `fromChannel: true`, which skips the past-arrival, restriction and availability checks. To decide when this item is built: how `created_by` is filled without a staff user, how OTA prices map onto `unit_nights` (for example `override_total`), and whether quote refusals are skipped so the booking is always accepted.
- Dorm bed bookings are not expected until the dorm work. A revision for an unmapped room fails and alerts.

## Alert webhooks

| Channex event           | Admin alert                                    |
| ----------------------- | ---------------------------------------------- |
| `non_acked_booking`     | Not acknowledged booking (with the booking ID) |
| `booking_unmapped_room` | Unmapped room, with **Fix mapping**            |
| `booking_unmapped_rate` | Unmapped rate, with **Fix mapping**            |
| `sync_error`            | Sync error on the connection                   |
| `rate_error`            | Rate error, with the dates                     |

Alerts show on the admin Channels tab only. Owners do not see them.

## Notifications

`ChannelBookingReceived` and `ChannelBookingCancelled` go to the property's reservation staff after the save (see [Notification types](../04-notifications/01-notification-types.md)).

## Import future bookings

Booking.com and Expedia support `load_future_reservations`. **Import future bookings** on an active connection pulls bookings made before the connection through the same flow. To verify on staging.

## Out of scope

OTA financial snapshots, settlements and commission reconciliation.
