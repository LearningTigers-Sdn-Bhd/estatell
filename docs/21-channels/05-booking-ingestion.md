# Booking ingestion (inbound)

## Purpose

Turn OTA bookings into estatell reservations, exactly once.

## Data model

```
channel_booking_revisions    id, channel_connection_id, external_booking_id, revision_id (unique),
                             kind (RevisionKind), payload (jsonb, raw), processed_at,
                             reservation_id (nullable), error, created_at
```

## Enums

`RevisionKind`: New, Modified, Cancelled.

## Flow

1. Channex webhook arrives, or a scheduled pull runs as fallback. The revision is stored. Duplicates are ignored by the unique `revision_id`.
2. `IngestChannelBooking` maps the revision:
    - One OTA booking with 3 rooms = 1 reservation with 3 units.
    - Dorm beds = one unit per bed (per the dorm strategy).
    - Guests go through `GuestMatcher`.
    - Prices are stored on `unit_nights`.
    - `source = Channel`, `channel_name`, `external_reference` are set.
    - Modified and Cancelled revisions update or cancel the matching units.
3. The revision is acknowledged to Channex.
4. Failures keep `error` and appear on the night audit readiness panel.

## Rules

- OTA bookings are always accepted. Over-capacity nights are flagged as overbooked.
- OTA-collected payments use the `OtaCollected` payment method.
- `payload` jsonb is allowed: it is a raw provider payload.
- `IngestChannelBooking` creates reservations through `CreateReservation` (see [Group bookings](../12-reservations/02-group-bookings.md#creating-reservations-and-units)). `source = Channel` already skips the past-arrival, restriction and availability checks. To decide when this item is built: how `created_by` is filled without a staff user, how OTA prices map onto `unit_nights` (for example `override_total`), and whether quote refusals are skipped so the booking is always accepted.

## Out of scope

OTA financial snapshots, settlements and commission reconciliation.
