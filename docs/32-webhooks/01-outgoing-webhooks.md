# Outgoing webhooks

## Purpose

Tell other tools when something happens in Estatell, without code for each tool. A platform admin registers a URL, picks the events it hears, and Estatell posts signed JSON to it. The usual receiver is an automation tool such as n8n.

Incoming webhooks (Channex, Curlec, MillionSend) are a different thing. Each has its own route, signature check and parser, and they stay hardcoded. See [Routing](../routing.md).

## Decisions

| Topic            | Decision                                                                                                          |
| ---------------- | ----------------------------------------------------------------------------------------------------------------- |
| Who manages      | Platform admins only, on `/admin/webhooks`. Property staff do not see webhooks.                                   |
| Scope            | An endpoint hears one property, or every property when its property is empty.                                     |
| Events           | 17 events. Each endpoint picks the events it hears.                                                               |
| Payload          | Full data, so a flow needs no call back to Estatell. Versioned: a field is never renamed or removed in a version. |
| Guest data       | Name, email, phone and country only. No identity document numbers and no scans.                                   |
| Signing          | HMAC-SHA256 of `{timestamp}.{body}` with the endpoint's secret.                                                   |
| Secret           | Shown once when the endpoint is made, stored encrypted, replaced by **Rotate secret**.                            |
| Delivery         | One queued job per endpoint and event. 10 second timeout. Up to 5 tries with backoff.                             |
| Failing endpoint | Turned off by the system after 3 days with no successful delivery. Platform admins get an email.                  |
| Test event       | Made-up sample data in the real payload shape. Never a real guest.                                                |
| Delivery log     | Kept 30 days.                                                                                                     |

## Tables

### `webhook_endpoints`

| Column            | Meaning                                                                                 |
| ----------------- | --------------------------------------------------------------------------------------- |
| `property_id`     | The property the endpoint hears. Empty means every property. Deleted with the property. |
| `name`            | A label for admins, for example "n8n – WhatsApp".                                       |
| `url`             | Where events are posted. `https` only.                                                  |
| `secret`          | The signing secret, encrypted (`encrypted` cast).                                       |
| `is_active`       | The switch. Off means no events are sent.                                               |
| `failing_since`   | The first failed delivery since the last successful one. Empty while healthy.           |
| `disabled_at`     | When the system turned the endpoint off. Empty when an admin turned it off.             |
| `disabled_reason` | Why the system turned it off, for example "HTTP 404 for 3 days".                        |

### `webhook_endpoint_events`

One row per event an endpoint hears. Unique on (`webhook_endpoint_id`, `event`). A CHECK limits `event` to the `WebhookEvent` values.

### `webhook_deliveries`

One row per event and endpoint.

| Column                | Meaning                                                                                                                   |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `webhook_endpoint_id` | The endpoint. Deliveries are deleted with it.                                                                             |
| `property_id`         | The property the event happened at. Empty for a test event on an endpoint for every property.                             |
| `event`               | `WebhookEvent`.                                                                                                           |
| `event_id`            | The `id` in the payload, `evt_` and a UUID v7. The same event sent to two endpoints has the same id. Unique per endpoint. |
| `payload`             | The JSON body that is sent (jsonb). An issued-document snapshot, so the body never changes between tries.                 |
| `is_test`             | Sent by **Send test event**.                                                                                              |
| `status`              | `WebhookDeliveryStatus`.                                                                                                  |
| `attempts`            | Tries so far.                                                                                                             |
| `response_status`     | The HTTP status of the last try. Empty when the request did not get a response.                                           |
| `error`               | A short reason for the last failed try, for example "Timed out after 10 seconds".                                         |
| `next_attempt_at`     | When the next try is due, while the status is Retrying.                                                                   |
| `delivered_at`        | When a try got a 2xx response.                                                                                            |

### `webhook_delivery_attempts`

One row per try: `attempt`, `response_status`, `error`, `duration_ms`, `attempted_at`. Deleted with the delivery. The delivery Sheet lists them.

## Delivery status

| Status      | Meaning                                                                  | Tone    |
| ----------- | ------------------------------------------------------------------------ | ------- |
| `pending`   | Queued, not tried yet.                                                   | neutral |
| `retrying`  | A try failed. Another try is due at `next_attempt_at`.                   | warning |
| `delivered` | The endpoint answered with a 2xx status.                                 | success |
| `refused`   | The endpoint answered 4xx (not 408 or 429). It is not tried again.       | danger  |
| `failed`    | 5 tries failed, or the endpoint was off or deleted when the try was due. | danger  |

An admin can retry a delivery that is `refused` or `failed`. A retry is a new try on the same row, with the same `event_id` and body.

## Events

The `WebhookEvent` enum holds the events, their group, their label and the class that builds their `data`.

An Action that causes an event calls `TriggerWebhook` with the event, the property and the subject (for example the reservation). `TriggerWebhook`:

- Does nothing when no active endpoint hears the event at the property.
- Sends one event per subject per request or job. Cancelling three units of one booking sends one `booking.cancelled`.
- Queues `BroadcastWebhookJob` after the transaction commits. A rolled-back change sends nothing, and the job builds the payload from the saved state.
- Does nothing inside `TriggerWebhook::muted()`. Old stays (`CreateOldStay`), migrated stays (`CreateMigratedStay`) and every `wastays:*` command run muted, also for the jobs they queue, so a receiver never messages a guest about an imported stay.

| Group        | Event                      | When                                                                      |
| ------------ | -------------------------- | ------------------------------------------------------------------------- |
| Booking      | `booking.created`          | A booking is made: by staff, from a channel or from the booking engine.   |
|              | `booking.updated`          | Dates, rooms, party or prices of a booking change. One event per save.    |
|              | `booking.cancelled`        | One or more units are cancelled. One event per booking.                   |
|              | `booking.no_show`          | One or more units are marked no-show. One event per booking.              |
| Stay         | `stay.checked_in`          | A unit is checked in.                                                     |
|              | `stay.checked_out`         | A unit is checked out.                                                    |
| Payment      | `payment.received`         | A payment is recorded, at the counter or online.                          |
|              | `payment.refunded`         | A refund is recorded or an online refund completes. A void sends nothing. |
|              | `folio.closed`             | A folio is closed.                                                        |
| Guest        | `guest.created`            | A guest is created.                                                       |
|              | `guest.updated`            | A guest's details change. VIP and blacklist changes send nothing.         |
|              | `guest.merged`             | Two guests are merged. Sends the kept id and the removed id.              |
|              | `guest.anonymized`         | A guest is anonymized, also in bulk (one per guest). Sends the id only.   |
| Housekeeping | `room.cleaned`             | A room cleaning is finished.                                              |
|              | `room.maintenance_flagged` | A room is marked as needing maintenance.                                  |
| Operations   | `night_audit.completed`    | A night audit completes.                                                  |
|              | `channel.alert`            | The channel manager reports a problem.                                    |

Where each event is sent from is listed in [Build order](#build-order).

## Payload

```json
{
    "id": "evt_0193b6f2-5c1e-7d3a-9a1f-4c2b8e7d6a10",
    "event": "booking.created",
    "version": 1,
    "occurred_at": "2026-11-12T14:02:00+08:00",
    "test": false,
    "property": {
        "code": "sandbay",
        "name": "Sand Bay Resort",
        "timezone": "Asia/Kuala_Lumpur"
    },
    "data": {}
}
```

- `occurred_at` is in the property's time zone. A delivery for every property with no property uses UTC.
- `property` is `null` only for a test event on an endpoint for every property.
- `data` holds one shape per event group:

| Group        | `data`                                                                                                                                                                                                                                           |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Booking      | `booking`: id, reference, short code, status, source, external reference, group, company, booker, currency, total, balance, created at, and `units` (dates, times, party, room type, rate plan, rooms, guests). Stay events also send `unit_id`. |
| Payment      | `payment`: id, amount, purpose, status, method, reference, receipt number, received on and at, refund of, refund reason, voided, folio number. `booking` as above.                                                                               |
| Folio        | `folio`: id, number, type, status, invoice number, unit id, balance, closed at. `booking` as above.                                                                                                                                              |
| Guest        | `guest`: id, name, email, phone, country code. `guest.merged` adds `removed_guest_id`. `guest.anonymized` sends `guest.id` only.                                                                                                                 |
| Housekeeping | `room`: id, number, room type, housekeeping status, needs maintenance, remark.                                                                                                                                                                   |
| Night audit  | `night_audit`: id, business date, status, trigger, forced.                                                                                                                                                                                       |
| Channel      | `alert`: id, event, label, external booking id, revision id, created at. The raw provider payload is not sent.                                                                                                                                   |

A guest row in a payload never holds identity document numbers, scans, tax details, notes or the blacklist reason. Anonymized guests are left out of a unit's `guests`.

## Request

`POST` to the endpoint URL with these headers:

| Header                 | Value                                                      |
| ---------------------- | ---------------------------------------------------------- |
| `Content-Type`         | `application/json`                                         |
| `User-Agent`           | `Estatell-Webhooks/1`                                      |
| `X-Estatell-Event`     | The event, for example `booking.created`.                  |
| `X-Estatell-Delivery`  | The event id, the same as `id` in the body.                |
| `X-Estatell-Timestamp` | Unix seconds when this try was sent.                       |
| `X-Estatell-Signature` | `sha256=` and the hex HMAC-SHA256 of `{timestamp}.{body}`. |

To check a request, the receiver:

1. Joins the timestamp header, a dot and the raw body.
2. Calculates the HMAC-SHA256 of that string with the secret, as hex.
3. Compares it with the signature after `sha256=`.
4. Refuses a timestamp older than 5 minutes.
5. Ignores an `id` it has seen before. A retry sends the same `id`.

The `WebhookSignature` service signs requests, and the tests use it to check them.

## Retries

`DeliverWebhookJob` sends one try. The `DeliverWebhook` Action records the try and decides what happens next:

| Result                                  | Next                                                                                                          |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| 2xx                                     | `delivered`. The endpoint's `failing_since` is cleared.                                                       |
| 4xx, but not 408 or 429                 | `refused`. No more tries.                                                                                     |
| 408, 429, 5xx, timeout or no connection | `retrying`, with the next try 1 minute, 5 minutes, 30 minutes and 2 hours later. After the 5th try: `failed`. |

Redirects are not followed. A 3xx counts as refused.

Every failed try sets the endpoint's `failing_since` if it is empty.

## Endpoint states

| State    | Stored as                            | Sends events |
| -------- | ------------------------------------ | ------------ |
| Active   | `is_active` on                       | Yes          |
| Off      | `is_active` off, `disabled_at` empty | No           |
| Disabled | `is_active` off, `disabled_at` set   | No           |

**Failing** is a warning, not a state: the endpoint is active and `failing_since` is set.

After a failed try, `DisableFailingWebhookEndpoint` turns the endpoint off when `failing_since` is 3 days or more ago. It sets `disabled_at` and `disabled_reason` and sends `WebhookEndpointDisabled` to every active platform admin by mail and in the app. The email names the endpoint and the property but holds no guest data.

Turning an endpoint on clears `failing_since`, `disabled_at` and `disabled_reason`, so the 3 days start again.

## Admin page

`/admin/webhooks`, platform admins only (`can:admin`). The sidebar item is **Webhooks**, after Channels.

- **Endpoints** tab: the switch in the first column, then name, URL, scope and event count. A Failing or Disabled endpoint shows a warning line under its row. The ⋯ menu has **Edit** and **Delete**. Filters (in the browser, the list is bounded): search, scope, state.
- **Deliveries** tab: every delivery, newest first, with an Endpoint column. Filters: endpoint, event, status.
- **Add endpoint** and **Edit** open a Sheet: name, URL, property (the first option is "All properties"), events grouped by `WebhookEvent` group, and Active.
- After **Save** on a new endpoint, and after **Rotate secret**, a Dialog shows the secret once with a Copy button.
- Turning on a Disabled endpoint asks first, with the reason it was turned off.
- The endpoint page shows the switch, deliveries in the last 24 hours, failed deliveries in the last 24 hours, the last successful delivery, and the deliveries list. The ⋯ menu has **Rotate secret**, **Turn off** and **Delete**.
- **Send test event** lists the endpoint's events. It sends made-up data from `WebhookSampleData` with `"test": true`, also while the endpoint is off, so an admin can check a URL before turning it on.
- A delivery row opens a Sheet with every try and the payload. The open delivery is `?delivery={id}` in the URL. **Retry now** shows on a refused or failed delivery while the endpoint is on.

## Retention

`PruneWebhookDeliveries` deletes deliveries older than 30 days (`WebhookDelivery::RETENTION_DAYS`), with their tries. It runs daily at 03:55 from `routes/console.php`, on one server. Running it twice deletes nothing more.

## Privacy

- Payloads hold guest contact details, so only platform admins see them, and they are deleted after 30 days.
- Identity document numbers and scans are never sent.
- `guest.anonymized` tells receivers to delete their copy. Estatell cannot delete data a receiver already holds.

## Build order

One PR on `feat/webhooks`:

1. `feat(webhooks): add endpoints, signed delivery and auto-disable` — the four tables, `WebhookEvent`, `WebhookDeliveryStatus`, the models and factories, `BroadcastWebhook`, `DeliverWebhook`, `DeliverWebhookJob`, `WebhookSignature`, `DisableFailingWebhookEndpoint`, `WebhookEndpointDisabled`, `PruneWebhookDeliveries`.
2. `feat(webhooks): send all events with versioned payloads` — `TriggerWebhook`, `BroadcastWebhookJob`, a payload builder per group in `app/Services/Webhooks/Payloads` (booking, payment, folio, guest, room, night audit, channel alert) and the triggers:

    | Event                      | Sent from                                                                                   |
    | -------------------------- | ------------------------------------------------------------------------------------------- |
    | `booking.created`          | `CreateReservation` (staff, channel, booking engine, portal, kiosk, arrivals import)        |
    | `booking.updated`          | `ChangeUnitDates`, `ExtendDepartureTime`, `MoveUnit`, `ChangeUnitParty`, `ChangeNightPrice` |
    | `booking.cancelled`        | `CancelUnit` (also through `CancelUnits`, `CancelUnitAndRefund` and the portal)             |
    | `booking.no_show`          | `MarkNoShow` (front desk and night audit)                                                   |
    | `stay.checked_in`          | `CheckInUnit`                                                                               |
    | `stay.checked_out`         | `CheckOutUnit`                                                                              |
    | `payment.received`         | `RecordPayment` (counter, Curlec, payment proof, OTA collected)                             |
    | `payment.refunded`         | `RefundPayment` (also through `CompleteOnlineRefund`), not for a void                       |
    | `folio.closed`             | `CloseFolio`                                                                                |
    | `guest.created`            | `CreateGuest`                                                                               |
    | `guest.updated`            | `UpdateGuest`, `SetGuestDetails`                                                            |
    | `guest.merged`             | `MergeGuests`                                                                               |
    | `guest.anonymized`         | `AnonymizeGuest` (also through `BulkUpdateGuests`)                                          |
    | `room.cleaned`             | `FinishRoomCleaning`                                                                        |
    | `room.maintenance_flagged` | `SetNeedsMaintenance`, only when the flag is set                                            |
    | `night_audit.completed`    | `RunNightAudit`                                                                             |
    | `channel.alert`            | `RecordChannelAlert`, only for a new alert                                                  |

    `UndoCheckIn` sends nothing, although it fires `StayCheckedOut` for door locks.

3. `feat(admin): manage webhook endpoints` — the routes, controller, Actions and pages in [Admin page](#admin-page).
4. `docs(webhooks): add webhooks feature file` — this file, the checklist item and the module map.
