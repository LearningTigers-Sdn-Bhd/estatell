# Facility bookings

> **Built for events and reservations** (checklist items 4 and 5). Posting is planned (item 6). See [Facilities and events](01-overview.md).

## Purpose

Book a facility for a time and a quantity, on an event or a reservation, with no clash, and charge it to a chosen folio.

## Data model

```
facility_bookings  id, property_id, facility_id → facilities (restrict),
                   event_id null → events (cascade),
                   reservation_id null → reservations (cascade),
                   folio_id → folios (restrict),
                   booking_date date, starts_at time, ends_at time,
                   quantity int, unit_amount decimal(12,2) null,
                   status (FacilityBookingStatus),
                   notes text null, created_by, cancelled_at null, cancelled_by null,
                   timestamps
                   CHECK exactly one of event_id, reservation_id
                   CHECK ends_at > starts_at
                   CHECK quantity > 0
                   CHECK unit_amount >= 0
                   INDEX (facility_id, booking_date)
```

- `booking_date` is the day it happens, in the property time zone. A booking cannot cross midnight.
- `folio_id` is **Charge to**. It is saved on the booking, so a later routing rule change does not move it.
- `unit_amount` is the price of one unit of the rate basis (one day, one session, one hour, one person). See [Price](#price).
- An event's booking has a date inside the event's dates (`booking_outside_event`).
- A booking of a Shared facility with sessions uses one session's times (`facility_session_not_found`). It copies the times and keeps no link to the session.

## Enums

| Enum                    | Cases                           | Notes                                                                                       |
| ----------------------- | ------------------------------- | ------------------------------------------------------------------------------------------- |
| `FacilityBookingStatus` | Tentative, Confirmed, Cancelled | An event's bookings follow the event: Tentative or Confirmed. A reservation's are Confirmed |

## Clash check

`FacilityAvailability` (Service) answers for one facility, one date and one time range.

| Sharing | Rule                                                                                                 | Refusal          |
| ------- | ---------------------------------------------------------------------------------------------------- | ---------------- |
| Private | No other Confirmed booking overlaps the time                                                         | `facility_clash` |
| Shared  | At every moment of the range, the quantity of Confirmed bookings plus this one is at most `capacity` | `facility_full`  |

- Times overlap when one starts before the other ends. 18:00–23:00 and 09:00–18:00 do not overlap.
- **Confirm event** runs the check for each of its Tentative bookings in turn, so two bookings of the same event that clash are caught too. A Private facility refuses with `facility_clash`, a Shared one with `facility_full`. A refusal confirms nothing and names the facility.
- Tentative bookings never block. They show as a **warning** in the Sheet: "Riverside Hall is held 09:00–17:00 (Sabah Tourism meeting, Tentative)."
- `BookFacility` locks the facility row while it checks and writes, so two staff cannot take the last slot together.
- An inactive facility takes no new bookings (`facility_inactive`).
- For a Shared facility with [sessions](02-facility-settings.md#sessions), the Session select shows what is left of each session ("8 of 20 left") from the same Service.

## Price

- A booking copies the charge item's amount into `unit_amount` when it is made. Staff can change it until the booking posts, for example a negotiated hall price.
- The charge is `unit_amount` × `FacilityRateBasis::quantity()`, plus the charge item's taxes. The server calculates it when shown and when posted.
- A later price change in Settings does not change saved bookings.
- A price that differs from the list price is logged on the booking's event or reservation (`facility_bookings.price_changed`, with both prices).
- "Charge by hand" (the charge item has no amount): `unit_amount` is null and the price field is hidden.

## Charge to

The **Charge to** select lists the Open folios of the owner.

| Owner       | Choices                                                                           | Default                                                                                |
| ----------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Event       | The event folio only (the select is read only)                                    | The event folio                                                                        |
| Reservation | Master, each unit folio, each third-party folio, and **+ New third-party folio…** | `FolioRouter::targetFor()` for the first live unit and the facility's transaction code |

- `ReservationChargeTo` (Service) builds the choices. Only Open folios are listed. A choice is `f{folio id}`, or `u{unit id}` for a unit with no folio yet.
- **+ New third-party folio…** swaps the Sheet body to a payer step (Guest or Company), and comes back with the new folio picked. One overlay only. The step posts to `facility-bookings.folios.store`, which calls `CreateFolio::thirdParty` and flashes the new folio (`chargeToFolioCreated`). It needs `folio.transfer`, as on the folio tab, so the choice shows only with that permission.
- A unit folio that does not exist yet is shown as "Unit 101 (new folio)" and created on save, as `PostCharge` does.
- A choice that is not a folio of the reservation (a voided or event folio, another reservation's folio, a cancelled unit) is refused with `folio_not_of_owner`.
- Taxes in the quote use the nationality of the Charge to folio's payer, else of the booker.

## Posting

- Each booking posts **one charge line** (and its tax children) for the [price](#price), with the key `facility:{facility_booking_id}`, the facility's transaction code, the description "{facility} · {from}–{to}", and `service_date` = `booking_date`. Its source is the facility booking (`source_type` = the `FacilityBooking` class). The description and amounts come from `FacilityBookingCharge`, which the Scheduled rows use too.
- **Night audit:** a new step `PostFacilityChargesStep`, after `PostScheduledAddOnsStep`, calls `PostFacilityCharges` for the business date. It posts every Confirmed booking on that date that has a price and no line. Each booking is its own transaction, so one refusal never stops the others. With the `Facilities` feature off, the step does nothing. A Tentative booking on that date becomes an exception (`facility_booking_tentative`) and is not posted. A Closed target folio becomes an exception (`folio_not_open`). Both are `Facility` exceptions linked to the booking ([Audit run](../20-night-audit/03-audit-run.md#steps)). **Post now** on the booking, or Dismiss, resolves them.
- **Post now:** staff with `facilities.book` and `folio.post-charge` can post a Confirmed booking with a price from the row menu, before its date. Same Action, same key, so it never posts twice; a second click says it is already on the folio. A line never serves after it posts (`service_date <= posting_date`), so an early line has `service_date` = the business date and its description adds the booking date ("Riverside Hall · 19:00–22:00 · 12 Dec").
- A posted booking shows **Posted** under its amount.
- Until it posts, the folio shows the booking as a **Scheduled** row (`ScheduledFolioRows` adds `facility:{id}` rows), like nights and add-ons. The row moves to Posted when the line exists.
- No amount on the charge item ("Charge by hand"): nothing posts and no Scheduled row shows. Staff post the charge themselves.
- **Change after posting:** "posted" means a line with the key `facility:{id}` exists (no column on the booking). Date, time, quantity, price and Charge to are locked: `UpdateFacilityBooking` refuses `facility_booking_posted`, and Edit leaves the row menu. Notes can still change. Cancel reverses the line and its tax children through `ReverseLine` (key `reverse:{line_id}`, as every reversal), then staff book again. A cancel with no actor (a guest cancelling online, the audit's no-show) writes the reversal as the system.
- **Cancel on a closed folio:** cancelling one booking is refused (`folio_not_open`); reopen the folio first. Cancelling an event or a reservation's last unit still cancels its bookings, and a charge on a closed folio stays for staff to correct, so those changes are never blocked.

## No-shows

A reservation's facility bookings are cancelled when the reservation has no live unit left (every unit Cancelled or NoShow).

- `CancelUnit` and `MarkNoShow` call `CancelFacilityBookings::forEndedReservation` after they change a unit. It cancels the bookings only when no live unit is left. There is no separate cancel-reservation Action: Cancel reservation, the company portal, the booking engine and channel cancellations all go through `CancelUnits`, which calls `CancelUnit` for each unit. The night audit marks no-shows through `MarkNoShow`, so its no-shows are covered too.
- The Cancel unit, Mark no-show and Cancel reservation forms warn first: "2 facility bookings will also be cancelled." The unit forms show it when the unit is the last live one (`UnitFeePreviewData::facilityBookingsToCancel`). Cancel reservation shows it when every live unit is ticked (`CancelAllPreviewData::facilityBookings` and `liveUnitIds`).
- Posted bookings are reversed, as on any cancel.
- `ReinstateUnit` does not bring them back. Staff book again.
- The reservations and front desk modules call the Action only. They never touch `facility_bookings`.

## Book facility (Sheet)

The same Sheet on the event page, the reservation page and the Timeline View peek. Six fields, so a Sheet (DESIGN.md §5).

```
┌──────────────────────────────────────────┐
│ Book facility                          ✕ │
│ Facility                                 │
│ [ Choose a facility                  ▾ ] │
│ Date                                     │
│ [ e.g. 12/12/2026                      ] │
│ From              To                     │
│ [ e.g. 18:00 ]    [ e.g. 23:00 ]         │
│ Quantity                                 │
│ [ e.g. 20 ]  pax                         │
│ Price (MYR)              Per day         │
│ [ 1,200.00 ]   List price 1,500.00       │
│ Charge to                                │
│ [ Master folio · Borneo Trails       ▾ ] │
│ ⚠ Riverside Hall is held 09:00–17:00     │
│   (Sabah Tourism meeting, Tentative).    │
│ 1,200.00 + SST 96.00                     │
│                        [Cancel] [Book]   │
└──────────────────────────────────────────┘
```

A Shared facility with sessions shows **Session** in place of From and To:

```
│ Session                                  │
│ [ 20:00–22:00 · 8 of 20 left         ▾ ] │
│ Quantity                                 │
│ [ 3 ]  pax                               │
│ Price (MYR)              Per person      │
│ [ 80.00 ]                                │
│ 240.00 + SST 19.20                       │
```

- **Notes** (optional) sits under Charge to, e.g. Round tables, stage on the left.
- Edit booking is the same Sheet with the facility locked.
- Facility, date and time (or session) prefill from the Timeline cell that opened it.
- **Price** prefills with the list price. "List price …" shows beside it only when staff change it. Hidden for "Charge by hand".
- The price line, the refusal and the Tentative warning come from the server (`POST facility-bookings/quote`, 300 ms after a change, like the payment quote). The browser does not calculate them. Book is disabled while the quote has a refusal.
- A reservation booking defaults the date to the business date when it is inside the stay, else the arrival date. Dates outside the stay are allowed (a dinner the night before arrival).
- A reservation takes bookings while it has a Confirmed or In-house unit (`reservation_not_live`).

## Reservation Facilities tab

A new tab on the [reservation page](../12-reservations/06-reservation-page.md), after Add-ons, two panels like Add-ons. Shown when the feature is on.

```
Facilities                                      [+ Book facility]
Facility        Date    Time         Qty     Charge to          Amount (MYR)
Riverside Hall  12 Dec  19:00–22:00  20 pax  Lee Chong Wei      1,500.00   ⋯
Kayak           11 Dec  09:00–11:00   2      Room 101 folio        80.00   ⋯
                                                       Total      1,580.00
```

- Row menu: Edit (not once posted), Post now and Cancel. See [Posting](#posting).
- Cancelled bookings stay in the list, last and muted. The total counts live bookings only.
- **Book facility** needs `facilities.book` and a Confirmed or In-house unit.
- Cancelling the reservation cancels its facility bookings. See [No-shows](#no-shows).

## Event Bookings tab

Bookings can also be made with the event, as lines on the [New event page](03-events-and-event-folios.md#facility-lines).

The same table on the event page, without the Charge to column (always the event folio). New bookings default to the event's first date and take its status. The date must be inside the event's dates. Cancelled bookings stay in the list, last and muted. Book facility and the row menu need `facilities.book` and a Tentative or Confirmed event. In the Sheet, Charge to is read only ("Event folio · {payer}").

## Actions

| Action                   | Does                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BookFacility`           | Locks the event or reservation and the facility, runs the clash check, creates the booking with its price. Refuses `event_not_open` (a Completed or Cancelled event), `facility_inactive`, `booking_outside_event`, `facility_session_not_found`, `folio_not_open`, `facility_clash`, `facility_full`. Reservations add `reservation_not_live` and `folio_not_of_owner` |
| `UpdateFacilityBooking`  | Date, time, quantity, price, Charge to, notes. The facility stays. Runs the same checks, leaving this booking out. Refuses `facility_booking_cancelled`, and `facility_booking_posted` for any change but notes once posted                                                                                                                                             |
| `CancelFacilityBooking`  | → Cancelled. Reverses a posted line first; refuses `folio_not_open` when that folio is closed                                                                                                                                                                                                                                                                           |
| `CancelFacilityBookings` | Cancels all bookings of an event (`CancelEvent`) or of a reservation with no live unit left (`CancelUnit` and `MarkNoShow`)                                                                                                                                                                                                                                             |
| `PostFacilityBooking`    | Posts one booking (Post now and the night audit). Idempotent by key. Refuses `facility_booking_cancelled`, `facility_booking_tentative`, `facility_charged_by_hand`, a folio that takes no charges and a tax it cannot work out. Resolves the booking's open `Facility` audit exceptions once it is posted                                                              |
| `PostFacilityCharges`    | The night audit step body for one business date                                                                                                                                                                                                                                                                                                                         |

## Routes

Need `facilities.book` and the `Facilities` feature. The Facilities tab needs `reservations.view` instead. The folio route also needs `folio.transfer`, and Post now (`facility-bookings.post`) needs `folio.post-charge`.

| Route                                                                | Name                             |
| -------------------------------------------------------------------- | -------------------------------- |
| `GET /p/{code}/reservations/{reservation}/facilities`                | `reservations.facilities`        |
| `POST /p/{code}/facility-bookings/reservations/{reservation}/folios` | `facility-bookings.folios.store` |
| `POST /p/{code}/facility-bookings`                                   | `facility-bookings.store`        |
| `POST /p/{code}/facility-bookings/quote`                             | `facility-bookings.quote`        |
| `PUT /p/{code}/facility-bookings/{facilityBooking}`                  | `facility-bookings.update`       |
| `POST /p/{code}/facility-bookings/{facilityBooking}/post`            | `facility-bookings.post`         |
| `POST /p/{code}/facility-bookings/{facilityBooking}/cancel`          | `facility-bookings.cancel`       |
