# Events and event folios

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

A bill for people who are not staying: a wedding dinner, a meeting, a walk-in lunch, a kayak hire. Today every folio belongs to a reservation, so these have nowhere to go. An event holds one folio and, later, its facility bookings.

## Data model

```
events   id, property_id, number, name,
         payer_guest_id null → guests, payer_company_id null → companies,
         event_date date, status (EventStatus), notes text null,
         cancelled_at null, cancelled_by null, timestamps
         UNIQUE (property_id, number)
         CHECK exactly one of payer_guest_id, payer_company_id

folios   reservation_id becomes nullable, adds event_id null → events (restrict)
         CHECK exactly one of reservation_id, event_id
         CHECK (type = 'event') = (event_id is set)
```

- `number` is `EVT-{n}` from `document_sequences`, like other document numbers.
- An event has exactly one folio, made with the event (`CreateFolio::forEvent`). A partial unique index allows one active (not Voided) folio per event.
- The event folio is a normal folio: the same lines, ledger rules, payments, discounts, Close and invoice. `FolioType` adds **Event**.
- The event folio has no units, no routing rules and no night lines. Every charge on it is posted by staff or by a [facility booking](04-facility-bookings.md#posting).
- Code that reads `folio->reservation` must handle null. The build item lists every caller (`FolioBalance`, `FolioStatement`, the folio invoice, receipts, the Payments and Folio ledger reports) and adds the event as the folio's owner.

## Enums

| Enum          | Cases                                      | Rules                                                                                       |
| ------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------- |
| `EventStatus` | Tentative, Confirmed, Completed, Cancelled | `canTransitionTo()`: Tentative → Confirmed or Cancelled; Confirmed → Completed or Cancelled |
| `FolioType`   | adds Event ("Event")                       |                                                                                             |

- **Tentative:** held while the client waits for a deposit. Its facility bookings show but do not block.
- **Confirmed:** its facility bookings block and post.
- **Completed:** set when the event folio is closed.
- **Cancelled:** its facility bookings are cancelled. The folio stays Open until staff refund the deposit or post a cancellation fee, then close it at 0.00. Nothing is automatic.

## Events list (Index)

Front desk sidebar, after Reservations: **Events** (icon `calendar-heart`). Needs `events.view` and the feature.

```
Events                                                            [+ New event]
[ e.g. Lim wedding ]   Status [ All ▾ ]   Date [ This month ▾ ]
────────────────────────────────────────────────────────────────────────────────
No.        Event                      Payer               Date     Status     Balance (MYR)
EVT-0012   Lim & Tan wedding dinner   Mr Lim Wei Jie      12 Dec   Confirmed       4,200.00
EVT-0011   WWF field team lunch       WWF-Malaysia        20 Nov   Completed           0.00
EVT-0010   Sabah Tourism meeting      Sabah Tourism Board 11 Dec   Tentative           0.00
```

- Search by number, name or payer. Filters for status and date range. Paginated on the server.
- Balance is the event folio's balance from `FolioBalance`.

## New event (Dialog)

| Field      | Control                                                     | Placeholder                       |
| ---------- | ----------------------------------------------------------- | --------------------------------- |
| Event name | Text                                                        | e.g. Lim & Tan wedding dinner     |
| Payer      | Guest or Company toggle, then the guest or company combobox | Choose a guest / Choose a company |
| Event date | Date picker, default the business date                      | e.g. 12/12/2026                   |
| Status     | Tentative or Confirmed, default Confirmed                   | –                                 |

A walk-in uses the same Dialog: the guest's name, today, Confirmed. "+ New guest" in the guest combobox works as on the booking form.

## Event page (Detail)

Two link tabs with their own routes: **Folio** and **Bookings**.

```
Events › Lim & Tan wedding dinner › Folio ▾
Lim & Tan wedding dinner  [Confirmed]                          [⋯ More ▾]
EVT-0012 · Fri 12 Dec 2026 · Mr Lim Wei Jie
[▤ Folio] [▦ Bookings]
┌──────────────────────────────────────────────────────────┬────────────────┐
│ Event folio · Open                        [Close folio]  │ SUMMARY        │
│ Folio BSAA-26300050                                      │ Mr Lim Wei Jie │
│ Charges 9,776.00   Paid 5,000.00   Balance 4,776.00      │ 012-345 6789   │
│ [⋯ More ▾]     [Apply discount] [Record payment] [Post…] │ Event date     │
│ Date     Description                  Amount (MYR)       │ 12 Dec 2026    │
│ POSTED                                                   │ Bookings       │
│ 01 Nov   Deposit · Bank transfer         -2,000.00       │ 2 facilities   │
│ 20 Nov   Deposit · Bank transfer         -3,000.00       │                │
│ SCHEDULED                                                │                │
│ 12 Dec ⏱ Riverside Hall · 18:00–23:00    1,500.00       │                │
│ 12 Dec ⏱ BBQ pit · 19:00–22:00             150.00       │                │
└──────────────────────────────────────────────────────────┴────────────────┘
```

- The **Folio** tab reuses the folio panel from the reservation page (`components/pms/folio/`). Routing actions are hidden: an event folio has no routing.
- **⋯ More**: Edit event (the same fields), Confirm, Cancel event (AlertDialog), Print booking summary later.
- The **Bookings** tab is in [Facility bookings](04-facility-bookings.md#event-bookings-tab).

## Deposits

Deposits are normal payments on the event folio before the event date. They show in **Deposit liability** until the event date (see [Reports](05-timeline-and-report.md#report-changes)).

## City ledger

A company payer can **Settle to City ledger** as on a reservation folio, if AR invoices accept a folio with no reservation. To verify in the events item; if they do not, that action is hidden on event folios in the first version.

## Actions

| Action          | Does                                                                                                    |
| --------------- | ------------------------------------------------------------------------------------------------------- |
| `CreateEvent`   | Takes the number, creates the event and its folio in one transaction. Logs `events.created`             |
| `UpdateEvent`   | Name, payer, date, notes. A new date moves its facility bookings to the same date after the clash check |
| `ConfirmEvent`  | Tentative → Confirmed. Runs the clash check on its bookings; refuses `facility_clash` and names them    |
| `CancelEvent`   | → Cancelled. Cancels its facility bookings (reversing posted ones). Logs `events.cancelled`             |
| `CompleteEvent` | Called by `CloseFolio` when the closed folio is an event folio                                          |

## Routes

Need the `Facilities` feature.

| Route                                   | Name              | Permission      |
| --------------------------------------- | ----------------- | --------------- |
| `GET /p/{code}/events`                  | `events.index`    | `events.view`   |
| `POST /p/{code}/events`                 | `events.store`    | `events.manage` |
| `GET /p/{code}/events/{event}`          | `events.show`     | `events.view`   |
| `GET /p/{code}/events/{event}/bookings` | `events.bookings` | `events.view`   |
| `PUT /p/{code}/events/{event}`          | `events.update`   | `events.manage` |
| `POST /p/{code}/events/{event}/confirm` | `events.confirm`  | `events.manage` |
| `POST /p/{code}/events/{event}/cancel`  | `events.cancel`   | `events.manage` |

Folio actions on the event page use the existing folio routes, which must accept a folio with no reservation.
