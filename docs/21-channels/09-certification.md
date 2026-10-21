# Channex certification

## Purpose

Pass Channex's PMS certification so we get production credentials. Source: [PMS Certification Tests](https://docs.channex.io/api-v.1-documentation/pms-certification-tests).

## What Channex checks

Channex reads the code and watches a live screen share. They change a price in estatell and expect the Channex call to fire from the real update path. Rejected:

- A script, CLI, Postman collection or "certification UI" that posts the test values.
- A full sync on a timer instead of sending changes.
- Per-date or per-rate calls where a test says "1 API call".
- Hardcoded UUIDs or values from their document.
- Integration logic that lives only in test files.

## Compliance

| Requirement                                               | Where in the plan                                                                     |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Detect changes as they happen, no polling of the PMS data | Domain events → `channel_ari_changes` ([ARI sync](04-ari-sync.md#flow))               |
| Outbox or queue between the PMS and the Channex client    | `channel_ari_changes` + delayed unique `FlushChannelAriJob`                           |
| Full sync: 500 days in 2 calls                            | [Full sync](04-ari-sync.md#full-sync)                                                 |
| Batch: tests 3–8 are one call each                        | 1 × `/restrictions` + 1 × `/availability` per flush, ranges merged                    |
| Limits: 10 + 10 calls per minute per property             | Limiter at 5 per endpoint ([Limits and failures](04-ari-sync.md#limits-and-failures)) |
| 429 and 5xx: back off, pause 1 minute, never drop         | `paused_until`, exponential backoff, `sent_at` stays null                             |
| Changes only; full sync at most once a day, off-peak      | Nightly at about 03:00 MYT, staggered                                                 |
| Webhook and acknowledgement, booking revisions feed       | [Booking ingestion](05-booking-ingestion.md#flow)                                     |
| Acknowledge only after the booking is saved               | Failed revisions are not acknowledged                                                 |
| Mapping layer between our IDs and Channex UUIDs           | [Mappings](02-mappings-and-capability.md#data-model)                                  |
| Check Channex before deleting rooms or rates              | [Archive guard](02-mappings-and-capability.md#archive-guard)                          |

## When to certify

After the Channex client, structure sync, ARI sync and booking ingestion are built. The OTA mapping screen is not needed for certification and can be built during Channex's review.

## Pre-flight

Be able to answer yes, with a file path, to each:

1. A saved price change emits an event the integration observes.
2. An outbox sits between the save and the Channex client.
3. A 429 backs off and keeps the change.
4. The file and line that call `POST /availability`.
5. With all test code deleted, estatell still pushes changes.

## Test property

Built in estatell through the normal screens, then synced:

- Name "Test Property - estatell", currency USD.
- Room types Twin Room and Double Room, 2 guests each.
- Rate plans Best Available Rate (100) and Bed & Breakfast (120) on both.
- Realistic, varied prices, stock and restrictions across the year. Uniform values are flagged as synthetic.

To check before starting: a property can use USD in `property_settings`.

## Tests

| #     | Test                                   | Run from                                    | Calls  |
| ----- | -------------------------------------- | ------------------------------------------- | ------ |
| 1     | Full sync, 500 days                    | Full refresh                                | 2      |
| 2     | Single date, single rate               | Rate calendar                               | 1      |
| 3     | Single date, multiple rates            | Rate calendar, then one flush               | 1      |
| 4     | Multiple dates, multiple rates         | Bulk edit                                   | 1      |
| 5     | Min stay                               | Rate calendar or bulk edit                  | 1      |
| 6     | Stop sell                              | Rate calendar or bulk edit                  | 1      |
| 7     | Multiple restrictions                  | Bulk edit                                   | 1      |
| 8     | Half-year update                       | Bulk edit                                   | 1      |
| 9     | Single date availability               | A booking                                   | 1 or 2 |
| 10    | Multiple date availability             | Bookings or room blocks                     | 1 or 2 |
| 11    | Booking new, modified, cancelled + ack | Booking.com test account or Booking CRS app | –      |
| 12–13 | Rate limits, changes only              | Form answers                                | –      |

Record each task ID from `channel_ari_changes.task_id`.

## Form answers

| Question                            | Answer                                                         |
| ----------------------------------- | -------------------------------------------------------------- |
| Min stay Through and Arrival?       | Arrival only                                                   |
| Restrictions not supported?         | None. Stop sell, CTA, CTD, min stay and max stay are supported |
| Multiple room types and rate plans? | Yes                                                            |
| Need card details with bookings?    | No                                                             |
| PCI certified or a PCI service?     | No                                                             |
| Stay within rate limits?            | Yes                                                            |
| Only send changes?                  | Yes, plus one off-peak full sync a day                         |

## Stages

1. Build (staging) and pass the pre-flight.
2. Run the tests from the estatell UI and record task IDs.
3. Submit the certification form.
4. Live screen-share review.
5. Production credentials. Then switch `CHANNEX_BASE_URL` and `CHANNEX_API_KEY` in production.
