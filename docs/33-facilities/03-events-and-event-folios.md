# Events and event folios

> **Built** (checklist items 3 and 4). Reservation bookings, posting, the Timeline View and the report are still planned. See [Facilities and events](01-overview.md).

## Purpose

A bill for people who are not staying: a wedding dinner, a meeting, a walk-in lunch, a kayak hire. Before this, every folio belonged to a reservation, so these had nowhere to go. An event holds one folio and, later, its facility bookings.

## Data model

```
events   id, property_id, number, name,
         payer_guest_id null → guests, payer_company_id null → companies,
         starts_on date, ends_on date, status (EventStatus), notes text null,
         cancelled_at null, cancelled_by null → users, timestamps
         UNIQUE (property_id, number)
         CHECK exactly one of payer_guest_id, payer_company_id
         CHECK ends_on >= starts_on

folios   reservation_id becomes nullable, adds event_id null → events (restrict)
         CHECK exactly one of reservation_id, event_id
         CHECK (type = 'event') = (event_id is set)
         UNIQUE (event_id) WHERE status <> 'voided'
```

- `number` comes from `document_sequences` with the **Event** document type (code 9). It has the same shape as every other document number, for example `BSAA-26900012`. Codes 4 and 6 stay unused to match wastays.
- An event runs from `starts_on` to `ends_on`: one day (a lunch) or several (a two-day conference, a wedding with a rehearsal the day before). Each facility booking keeps its own date inside that range.
- An event has exactly one folio, made with the event (`CreateEvent`). The partial unique index allows one active (not Voided) folio per event.
- The event folio is a normal folio: the same lines, ledger rules, payments, discounts, Close and invoice. `FolioType` adds **Event**.
- The event folio has no units, no routing rules and no night lines. Every charge on it is posted by staff or, later, by a [facility booking](04-facility-bookings.md#posting).
- The folio's payer is the event's payer. Changing the payer of an event also changes the payer of its Open folio.
- A folio's activity is logged on its owner, the reservation or the event (`FolioOwner`).

## Enums

| Enum           | Cases                                      | Rules                                                                                                              |
| -------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ |
| `EventStatus`  | Tentative, Confirmed, Completed, Cancelled | `canTransitionTo()`: Tentative → Confirmed or Cancelled; Confirmed → Completed or Cancelled; Completed → Confirmed |
| `FolioType`    | adds Event ("Event")                       |                                                                                                                    |
| `DocumentType` | adds Event ("Event", code 9)               |                                                                                                                    |

- **Tentative:** held while the client waits for a deposit. Its facility bookings show but do not block. A new event that starts on or before the business date cannot be Tentative: it has happened, and a Tentative booking never posts.
- **Confirmed:** its facility bookings block and post.
- **Completed:** set when the event folio is closed. Reopening the folio puts the event back to Confirmed. A Tentative or Cancelled event keeps its status when its folio closes.
- **Cancelled:** its facility bookings are cancelled. The folio stays Open until staff refund the deposit or post a cancellation fee, then close it at 0.00. Nothing is automatic.

## Events list (Index)

Front desk sidebar, in the Reservations group after All reservations: **Events** (icon `calendar-heart`). Needs `events.view` and the feature.

```
Events                                                            [+ New event]
[ e.g. Lim wedding ]   [ All statuses ▾ ]   [ This month ▾ ]
────────────────────────────────────────────────────────────────────────────────
No.             Event                      Payer               Dates          Status     Balance (MYR)
BSAA-26900012   Lim & Tan wedding dinner   Mr Lim Wei Jie      11–12 Dec      Confirmed       4,200.00
BSAA-26900011   WWF field team lunch       WWF-Malaysia        20 Nov         Completed           0.00
BSAA-26900010   Sabah Tourism meeting      Sabah Tourism Board 11 Dec         Tentative           0.00
```

- Search by number, name or payer. Filters for status and month: **This month** (the default), Next month, Last month, All dates. The month counts from the business date. The month filter keeps events whose dates overlap it.
- Sorted by the first day, then by number. Paginated on the server, 25 to a page.
- Balance is the event folio's balance (posted lines only), from one sub-query.

## New event page

**New event** on the Events list opens a full page at `/p/{code}/events/new`, in the layout of the [booking form](../12-reservations/05-booking-form.md#container-and-layout): one scroll region with the title, the fields and a sticky summary rail (two columns from `xl`), and Cancel and **Create event** in a footer that stays visible. Saving opens the event page.

**Decided 2026-11-23:** a page, not a Dialog. Six fields fit a Dialog under DESIGN.md §5, but the page has [facility booking lines](04-facility-bookings.md) (repeating rows), so it follows the full-page rule.

**Decided 2026-11-23 (later):** the page looks like New reservation. The Event section, then underline tabs (**Facilities**, **Notes**), the facility lines as one table with a header row and a footer, and the guest payer as one guest row like the booker of the booking form. **New guest** is gone: the guest row finds a guest or adds a new one.

`?date=Y-m-d&facility={id}` starts both dates on that date and adds one facility line for that facility, at its list price. **+ New event** on the [Timeline's For step](05-timeline-and-report.md#book-facility-from-the-timeline) links here this way. A value that does not parse, or a facility of another property, is ignored.

```
← New event
  A bill for people who are not staying: a wedding dinner, a meeting or a walk-in lunch. It gets its own folio.

  Event                                                         SUMMARY       (sticky)
  Who pays and on which days.                                   Event   Lim & Tan wedding
  Event name [ e.g. Lim & Tan ]  Dates [ 12/12 → 12/12 ]  Status [ Confirmed ▾ ]   Dates   12 Dec 2026
  Payer ( Guest )  ( Company )                                  Status  Confirmed
  ┌ Guest                      │ Phone            ┐             Facilities        2
  │ > [ Lim Wei Jie  Existing ]│ [ +60 12-345 … ] │             ─────────────────
  │   Email, details, MyKad / ID (opens below)    │             Facilities 1,440.00
  └───────────────────────────────────────────────┘             Tax          115.20
                                                                Total  RM 1,555.20
  ─ Facilities (2) ─  Notes ─────────────────────
  Facility bookings
  ┌ Facility       │ Date   │ Time          │ Qty │ Price    │   ┐
  │ Riverside Hall │ 12 Dec │ 18:00 – 23:00 │ 1   │ 1,200.00 │ 🗑 │
  │   1,200.00 + SST 96.00                                       │
  │ Night Walk     │ 12 Dec │ 20:00–22:00   │ 3   │ 80.00    │ 🗑 │
  │   ⚠ Night Walk is full at that time.                         │
  ├ 2 bookings                                [+ Add facility] ┤
  └──────────────────────────────────────────────────────────────┘
──────────────────────────────────────────────────────────────────────────────────
                                                         [Cancel] [Create event]
```

| Field      | Control                                                                                                                                             | Placeholder                        |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Event name | Text                                                                                                                                                | e.g. Lim & Tan wedding dinner      |
| Dates      | Date range picker, both ends default to the business date                                                                                           | e.g. 11/12/2026 → 12/12/2026       |
| Status     | Tentative or Confirmed, default Confirmed. Locked to Confirmed when the first date is on or before the business date (`EventRequest` checks it too) | –                                  |
| Payer      | Choice cards: Guest or Company (shown with `company.view`)                                                                                          | –                                  |
| Guest      | One guest row of the booking form's Guests table, without Stays in                                                                                  | –                                  |
| Company    | Active companies, full width, when the payer is Company                                                                                             | –                                  |
| Notes      | Notes tab: text box with a counter, optional, at most 2,000 characters                                                                              | e.g. Vegetarian menu for 20 guests |

- A one-day event has the same date at both ends. A walk-in uses the same page: type the guest's name, today, Confirmed.
- **Guest row:** the same row as the booking form's Guests table (Name and Phone in the row; Email, guest details, MyKad and ID below), with no Stays in, Remove or footer. Typing a name searches the guest list. A picked guest is linked, and **Update guest profile with my changes** saves edits to it. A typed name is a new guest. It sends `guest` (`guest.guest_id`, `guest.update_profile`, the profile fields and the ID), checked like a booking guest row: a new guest needs a name, adding or changing a guest needs `guests.manage` (without it staff can only pick), and an ID for a new guest needs `guests.view-documents`. Errors come back as `guest.{field}`.
- **Save:** `CreateEvent::withBookings()` resolves the guest row with `ResolveUnitGuest` (the same as a booking guest row) inside its transaction, so a new guest is not kept when a line is refused. A guest merged or removed after the page loaded comes back as an error on `guest.full_name`.
- **Company** needs `company.view` and offers Active companies.
- Back and Cancel go to the Events list. Leaving with unsaved changes asks to Save, Discard or Cancel.

### Facility lines

Shown with `facilities.book`. Each line is a [facility booking](04-facility-bookings.md) made with the event: Facility, Date (inside the event's dates, default its first day), Time or Session, Qty and Price (hidden for "Charge by hand"). There is no Charge to: every line goes to the event folio.

- **Quote:** 300 ms after a change, `POST events/quote` (`events.quote`, `events.manage` and `facilities.book`) answers each line, in order, with its price and tax, its refusal, the Tentative hold warning and the places left in each session, and the totals for the rail. The browser calculates nothing. Each line is checked against the bookings already saved and against the earlier lines, counted as saved when the status is Confirmed, so two lines that clash are caught. **Create event** is disabled while a line is refused or not filled in.
- **Save:** `CreateEvent::withBookings()` creates the event and its folio, then books each line with `BookFacility` in order, in one transaction. The first refused line rolls everything back (the event number included) and comes back as an error on that line (`bookings.{line}.{field}`).
- Lines take the event's status. At most 30 lines.
- **Table:** the booking form's lines style: a header row from `2xl` (each cell shows its label below that), one row per line, and a footer with the count and **Add facility**. The price's rate basis shows under the price. An **Amount** column shows the amount before tax with an ⓘ button; its Popover shows how it adds up ("3 items × 2 hours × 25.00", the quote's `breakdown`), each tax and the total, like the room price on New reservation. A refusal or Tentative warning shows under the row.
- **Summary rail:** event name, dates, status and the number of lines, then the facilities before tax, the added tax and the total. Lines charged by hand are not counted.

## Edit event (Dialog)

Event name, Payer (Guest or Company cards, then a guest picker or a company), Dates and Notes, in a Dialog on the event page (**⋯ More actions**, **Edit event**). It sends `payer_guest_id`; it does not add guests. A Completed or Cancelled event cannot be edited.

## Event page (Detail)

Two link tabs with their own routes and pages: **Folio** (`events/show`) and **Bookings** (`events/bookings`). The summary aside shows the payer, phone, dates, event number and notes.

```
Events › Lim & Tan wedding dinner › Folio ▾
‹  Lim & Tan wedding dinner  [Confirmed]                       [⋯ More actions]
   BSAA-26900012 · 11 Dec 2026 – 12 Dec 2026 · Mr Lim Wei Jie
[▤ Folio] [▦ Bookings]
┌──────────────────────────────────────────────────────────┬────────────────┐
│ Event folio · Open                        [Close folio]  │ SUMMARY        │
│ Folio BSAA-26300050                                      │ Payer          │
│ Charges 9,776.00   Paid 5,000.00   Balance 4,776.00      │ Mr Lim Wei Jie │
│              [Apply discount] [Record payment] [Post…]   │ Phone          │
│ Date     Description                  Amount (MYR)       │ 012-345 6789   │
│ POSTED                                                   │ Dates          │
│ 01 Nov   Deposit · Bank transfer         -2,000.00       │ 11–12 Dec 2026 │
│ 20 Nov   Deposit · Bank transfer         -3,000.00       │ Event no.      │
│                                                          │ BSAA-26900012  │
└──────────────────────────────────────────────────────────┴────────────────┘
```

- The **Folio** tab reuses the folio panel of the reservation page (`components/pms/folio/folio-tab.tsx`) with an event owner (`FolioOwner` in `folio-owner.ts`). The panel posts to the event's folio routes.
- Not on an event folio: routing rules, third-party folios, Move charges, Move credit, the Manual Folio Editor and e-invoice. Post charge has no Folio or Room field.
- **⋯ More actions**: Edit event, Confirm (Tentative only), **Print booking summary**, **Print without prices** (company payer only), Cancel event (AlertDialog). The prints show to anyone with `events.view`, on every status. See [Booking summary](#booking-summary).
- The **Bookings** tab shows an empty state until the [facility bookings item](04-facility-bookings.md#event-bookings-tab).

## Booking summary

**Decided 2026-11-23.** The same proforma as the [reservation booking summary](../12-reservations/08-booking-summary.md), for an event. `EventBookingSummary` (`app/Services/Events/`) returns the same `BookingSummaryDocument`, and the PDF uses the same view.

| Part                | For an event                                                                                                                                                |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Number              | The event number, with "As at {date}, {time}". Nothing is stored.                                                                                           |
| Strip               | Bill to (company, else the payer guest), Event, Dates, Booked on.                                                                                           |
| Facilities          | In place of Rooms: one line per booking that is not Cancelled, "14 Oct · Riverside Hall · 18:00–23:00 · 1 item". A Tentative booking ends with "Tentative". |
| Charges             | The posted lines of the folios that are not Voided, then the bookings not posted yet on Open folios. One list with no group heading.                        |
| Payments, balance   | As on a reservation.                                                                                                                                        |
| Stamp               | CANCELLED for a Cancelled event.                                                                                                                            |
| Cancellation policy | Left out. The property policy is written for rooms.                                                                                                         |
| Without prices      | `?prices=0`, only for a company payer. A guest payer always gets prices.                                                                                    |

## Deposits

Deposits are payments on the event folio before the event starts. Before `starts_on` the folio offers **Advance**; from `starts_on` it offers **Settlement**. A Security deposit is refused. The event folio takes any active method of the property except City ledger, OTA collected, Online and Old system. The surcharge tax follows the payer guest's nationality.

Deposits will show in **Deposit liability** until `starts_on` (see [Reports](05-timeline-and-report.md#report-changes)).

## City ledger

A company payer can **Settle to City ledger** on an event folio, as on a reservation folio. `ar_invoices` links by `folio_id`, not by reservation, and `SettleToCityLedger` needs only an Open folio with a company payer. Settling closes the folio, so it completes the event.

Code that read `folio->reservation` now handles an event folio:

| Where                                                      | Change for an event folio                                                              |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `ArInvoicePrinter`                                         | No units: the lines print with no room. The strip shows the event number               |
| `StatementBuilder`, `PaymentReceipt`                       | The event number where the reservation reference shows                                 |
| `FolioInvoice`                                             | The strip shows Bill to, Event, Dates and Event no. (in place of Stay and Reservation) |
| `FolioVoucher`                                             | The event number and dates; a company payer's name in place of a guest                 |
| `InHouseUnits`, `IncomingScheduledCharges`                 | No change needed: they return nothing for an event folio. Bookings are added in item 4 |
| Activity on close, reopen, discounts, reversal and refunds | Logged on the event (`FolioOwner`)                                                     |

## Actions

| Action                      | Does                                                                                                                                                                                                                  |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateEvent`               | Takes the Event number, creates the event and its folio in one transaction. Logs `events.created`. `withBookings()` also resolves the New event guest row with `ResolveUnitGuest` and books the lines, all or nothing |
| `UpdateEvent`               | Name, payer, dates, notes. Refuses a Completed or Cancelled event (`event_not_open`), and new dates that leave a live facility booking outside them (`booking_outside_event`)                                         |
| `ConfirmEvent`              | Tentative → Confirmed, with its Tentative bookings. Each booking runs the clash check in turn (`facility_full`); a refusal confirms nothing. Logs `events.confirmed`                                                  |
| `CancelEvent`               | → Cancelled, with its bookings (`CancelFacilityBookings`). Logs `events.cancelled`. A posted booking on an Open folio is reversed                                                                                     |
| `CompleteEvent`             | Called by `CloseFolio`: a Confirmed event becomes Completed                                                                                                                                                           |
| `ReopenEvent`               | Called by `ReopenFolio`: a Completed event becomes Confirmed                                                                                                                                                          |
| `PostEventCharge`           | Posts a charge and its tax on the event folio, with the same checks as `PostCharge` and no unit or routing. Logs `folio.charge_posted` on the event                                                                   |
| `RecordPayment::forEvent()` | Records a payment on the event folio, with the checks in [Deposits](#deposits). Shares the write step with `RecordPayment`                                                                                            |

Refusals return `false` or a refused result; the controller shows an error toast and nothing is written or logged.

## Routes

Need the `Facilities` feature (`facilities` middleware). Routes in `routes/events.php`, with scoped bindings: a folio must belong to the event, and a line or payment to the folio.

| Route                                          | Name                     | Permission                            |
| ---------------------------------------------- | ------------------------ | ------------------------------------- |
| `GET /p/{code}/events`                         | `events.index`           | `events.view`                         |
| `GET /p/{code}/events/new`                     | `events.create`          | `events.manage`                       |
| `POST /p/{code}/events`                        | `events.store`           | `events.manage`                       |
| `POST /p/{code}/events/quote`                  | `events.quote`           | `events.manage` and `facilities.book` |
| `GET /p/{code}/events/{event}`                 | `events.show`            | `events.view`                         |
| `GET /p/{code}/events/{event}/bookings`        | `events.bookings`        | `events.view`                         |
| `GET /p/{code}/events/{event}/booking-summary` | `events.booking-summary` | `events.view`                         |
| `PUT /p/{code}/events/{event}`                 | `events.update`          | `events.manage`                       |
| `POST /p/{code}/events/{event}/confirm`        | `events.confirm`         | `events.manage`                       |
| `POST /p/{code}/events/{event}/cancel`         | `events.cancel`          | `events.manage`                       |

The folio actions sit under `/p/{code}/events/{event}/folios/{folio}/…` (`events.folios.*`) and use the existing folio permissions on top of `events.view`:

| Path                                                                     | Permission                               |
| ------------------------------------------------------------------------ | ---------------------------------------- |
| `charges`                                                                | `folio.post-charge`                      |
| `lines/{line}/reversal`                                                  | `folio.reverse`                          |
| `discounts/quote`, `discounts`, `lines/{line}/discount`                  | `folio.discount`                         |
| `close`                                                                  | `folio.close`                            |
| `reopen`                                                                 | `FolioReopenRule`                        |
| `city-ledger`                                                            | `city-ledger.invoice`                    |
| `void-invoice-and-reopen`                                                | `city-ledger.invoice` and `folio.reopen` |
| `payments`, `payments/{payment}/receipt`                                 | `payment.record`                         |
| `payments/{payment}/refunds`, `payments/{payment}/void`, `refund-credit` | `payment.refund`                         |
| `voucher`, `invoice`                                                     | `folio.print-voucher`                    |

`POST /p/{code}/events/{event}/payments/quote` (`events.payments.quote`, `payment.record`) quotes the surcharge.

## Open

- An event folio over RM10,000 needs its own e-invoice, and event folios cannot submit one yet. Below that, a closed event folio goes into the monthly consolidated e-invoice like a stay.
- The event page has no Activity tab. The log is written on the event.
