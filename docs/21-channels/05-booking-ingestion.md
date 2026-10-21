# Booking ingestion (inbound)

## Purpose

Turn OTA bookings into estatell reservations, exactly once, and acknowledge them to Channex only after they are saved.

## Data model

```
channel_booking_revisions    id, channel_property_id, channel_connection_id (nullable, no foreign key until
                             channel_connections exists), external_booking_id (Channex booking_id),
                             revision_id (unique), kind (RevisionKind), payload (jsonb, raw),
                             processed_at, acknowledged_at, reservation_id (nullable), error, created_at
channel_alerts               id, channel_property_id, event (ChannelAlertEvent), external_booking_id,
                             revision_id (nullable), payload (jsonb, raw), resolved_at, created_at
```

`payload` is the revision as the feed returns it (its attributes and `id`). `processed_at` is set when the reservation is saved. `acknowledged_at` is set after Channex took the acknowledgement. A saved revision that is not acknowledged only repeats the acknowledgement on the next pull, so nothing is booked twice.

## Enums

`RevisionKind`: New, Modified, Cancelled (values `new`, `modified`, `cancelled`, as Channex sends them). `ChannelAlertEvent`: the five alert webhooks below.

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

1. A `booking` webhook (`POST /webhooks/channex`) queues `PullChannelRevisionsJob` for the property. The scheduled `channels:pull-revisions` (every 5 minutes) queues the same job as a fallback. The job reads `GET /booking_revisions/feed?filter[property_id]=…&order[inserted_at]=asc`, and `StoreChannelRevision` keeps each revision. Duplicates are ignored by the unique `revision_id`. Only one pull per property waits or runs at a time. A rate limit puts the job back after the wait Channex asked for.
2. `IngestChannelBooking` maps the revision:
    - One OTA booking with 3 rooms = 1 reservation with 3 units.
    - The room's Channex `rate_plan_id` maps to the rate plan and room type through `channel_rate_plan_mappings`. A room with no `room_type_id` or `rate_plan_id`, or one we have no mapping for, fails the revision.
    - The booker is a guest of the property with the same email or phone (`GuestMatcher`), or a new guest. The other guests of the OTA booking are not attached to units.
    - Prices are stored on `unit_nights`: the room `amount` is the unit's stay total, saved as `override_total` spread over the nights. A stay the rate quote cannot price (no rate on a date, over capacity) is still saved this way, with no calculated price on its nights.
    - A booking in another currency than the property's fails the revision.
    - `EnsureOtaBookingSource` gives the property's OTA [booking source](../12-reservations/11-booking-sources.md) for the revision's channel, and creates it when missing. The reservation gets its `booking_source_id` and `external_reference`. Manual and ingested bookings for the same OTA share one source.
    - The reservation's `external_reference` is the Channex `unique_id` (for example `BDC-9996013801`). A later revision finds the reservation through the earlier revision of the same `external_booking_id`. A Modified or Cancelled revision whose earlier revision is not saved fails until that one is saved.
    - Modified: the rooms of the revision are matched to the live units in order. A unit with another room type or rate plan is cancelled and replaced, a unit with other dates changes dates (`ChangeUnitDates`), a room with no unit is added, and a unit with no room is cancelled. Nothing is charged for these changes.
    - Modified prices: a kept unit is then priced as the OTA says, so the reservation total matches the OTA. Each night of the unit takes the revision room's `days` amount for its date, when the revision has an amount for every night. Otherwise the room `amount` is spread over the nights, as a new booking does (the last night takes the remainder). A night that already has that price is skipped. The other nights are changed through `ChangeNightPrice` (reason "Changed on {channel}"), so a night the night audit has already charged is reversed and posted again at the new price, and the change shows in the activity log. This covers nights added by a longer stay and nights whose price the OTA changed. A night that `ChangeNightPrice` refuses (for example a property with no room revenue code) keeps its price and the revision is still saved and acknowledged. A replaced or added unit is priced like a new one (override total spread over the nights).
    - Cancelled: every live unit is cancelled with no fee (`CancelUnits`, do not charge).
3. Only a saved revision is acknowledged. An acknowledged booking is in estatell.
4. A failed revision keeps `error`, is not acknowledged, and shows on the admin Channels tab and the night audit readiness panel. Channex then also sends `non_acked_booking`.

## Rules

- Use the booking revisions feed and acknowledgement. Never the `GET /bookings` endpoints.
- The webhook is verified with Channex's HMAC-SHA256 signing: `x-hmac-signature-256` over `<x-hmac-timestamp>.<raw body>` with `CHANNEX_WEBHOOK_SECRET`. One or two signatures are accepted (key rotation), a timestamp more than 5 minutes off is refused, and with no secret set every webhook is refused. Events for an unknown property or a property with `ChannelManager` off, and event kinds we do not use, are answered 200 and dropped. The route has no session or CSRF (`routes/webhooks.php`).
- OTA bookings are always accepted. Over-capacity nights are flagged as overbooked.
- No card details are stored. OTA-collected payments use the `OtaCollected` payment method.
- `payload` jsonb is allowed: it is a raw provider payload.
- `IngestChannelBooking` creates reservations through `CreateReservation` (see [Group bookings](../12-reservations/02-group-bookings.md#creating-reservations-and-units)) with `fromChannel: true`, which skips the past-arrival, restriction and availability checks. `AddUnit` also accepts a stay the rate quote refuses when `override_total` is set (decided 2026-10-21).
- `created_by` and the activity log causer are the property's owner (`PropertyOwners::firstFor`). There is no system user. A property with no owner fails the revision.
- A refusal that estatell cannot skip fails the revision with its code, for example a Cancel with no cancellation policy (`policy_not_found`), a date change that needs a business date (`no_business_date`), or a closed reservation.
- Dorm bed bookings are not expected until the dorm work. A revision for an unmapped room fails and alerts.

## Alert webhooks

| Channex event           | Admin alert                                    |
| ----------------------- | ---------------------------------------------- |
| `non_acked_booking`     | Not acknowledged booking (with the booking ID) |
| `booking_unmapped_room` | Unmapped room, with **Fix mapping**            |
| `booking_unmapped_rate` | Unmapped rate, with **Fix mapping**            |
| `sync_error`            | Sync error on the connection                   |
| `rate_error`            | Rate error, with the dates                     |

Alerts are stored in `channel_alerts` by `RecordChannelAlert` and show on the admin Channels tab only. Owners do not see them. The same event for the same revision is kept once while it is open. An alert tied to a revision (`booking_revision_id` in the payload) closes when that revision is acknowledged. An admin can close any alert with **Close**. A failed revision shows as **Booking not saved** with **Retry** (`RetryChannelRevision`).

**Fix mapping** on the alert comes with the mapping screen in [Connect and map OTAs](02-mappings-and-capability.md#ota-mapping-channel-api). Until then, fix the mapping by syncing the structure again, then press **Retry**.

## Notifications

`ChannelBookingReceived` (New) and `ChannelBookingCancelled` (Cancelled) go to the property's reservation staff after the save, once per revision (see [Notification types](../04-notifications/01-notification-types.md)). A Modified revision sends nothing.

## Import future bookings

Booking.com and Expedia support `load_future_reservations`. **Import future bookings** on an active connection pulls bookings made before the connection through the same flow. To verify on staging.

## To verify on staging

- The signature headers and the secret on a real webhook (`CHANNEX_WEBHOOK_SECRET`).
- Whether a multi-bed booking arrives as one room entry or N (see [Dorm checks](06-staging-verification.md#dorm-checks-wip)).
- The room `amount`: tax inclusive or not per OTA (see [Open checks](06-staging-verification.md#open-checks)).
- The feed page size, and what an OTA-collected booking carries in `payment_collect`.

## Open

- OTA-collected payments (`payment_collect` = `ota`) are not posted to the folio yet. The `OtaCollected` payment method is there; the posting rule needs the staging amounts above.
- A night that cannot be repriced on a Modified booking (a refusal from `ChangeNightPrice`) keeps its old price without any alert, so the total can differ from the OTA. A refusal needs a property with no room revenue code, or a folio that cannot take the correction.

## Out of scope

OTA financial snapshots, settlements and commission reconciliation.
