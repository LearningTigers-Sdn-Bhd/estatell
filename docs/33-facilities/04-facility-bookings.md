# Facility bookings

> **Planned.** See [Facilities and events](01-overview.md).

## Purpose

Book a facility for a time and a quantity, on an event or a reservation, with no clash, and charge it to a chosen folio.

## Data model

```
facility_bookings  id, property_id, facility_id → facilities (restrict),
                   event_id null → events (cascade),
                   reservation_id null → reservations (cascade),
                   folio_id → folios (restrict),
                   booking_date date, starts_at time, ends_at time,
                   quantity int, status (FacilityBookingStatus),
                   notes text null, created_by, cancelled_at null, cancelled_by null,
                   timestamps
                   CHECK exactly one of event_id, reservation_id
                   CHECK ends_at > starts_at
                   CHECK quantity > 0
                   INDEX (facility_id, booking_date)
```

- `booking_date` is the day it happens, in the property time zone. A booking cannot cross midnight.
- `folio_id` is **Charge to**. It is saved on the booking, so a later routing rule change does not move it.
- The amount is not stored. It is the charge item's amount × `FacilityRateBasis::quantity()`, calculated when shown and when posted.

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
- Tentative bookings never block. They show as a **warning** in the dialog: "Riverside Hall is held 09:00–17:00 (Sabah Tourism meeting, Tentative)."
- `BookFacility` locks the facility row while it checks and writes, so two staff cannot take the last slot together.
- An inactive facility takes no new bookings (`facility_inactive`).

## Charge to

The **Charge to** select lists the Open folios of the owner.

| Owner       | Choices                                                                           | Default                                                                                |
| ----------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Event       | The event folio only (the select is read only)                                    | The event folio                                                                        |
| Reservation | Master, each unit folio, each third-party folio, and **+ New third-party folio…** | `FolioRouter::targetFor()` for the first live unit and the facility's transaction code |

- **+ New third-party folio…** swaps the dialog body to a payer step (Guest or Company and the combobox), calls the existing `CreateFolio::thirdParty`, and comes back with the new folio picked. One overlay only.
- A unit folio that does not exist yet is shown as "Room 101 folio (new)" and created on save, as `PostCharge` does.

## Posting

- Each booking posts **one charge line** (and its tax children) with the key `facility:{facility_booking_id}`, the facility's transaction code, the description "{facility} · {from}–{to}", and `service_date` = `booking_date`. Its source is the facility booking (`source_type` `facility_booking`).
- **Night audit:** a new step `PostFacilityChargesStep`, after `PostScheduledAddOnsStep`, calls `PostFacilityCharges` for the business date. It posts every Confirmed booking on that date that has no line. A Tentative booking on that date becomes an exception (`facility_booking_tentative`) and is not posted. A Closed target folio becomes an exception (`folio_not_open`).
- **Post now:** staff with `folio.post` can post a booking before its date from the row menu. Same Action, same key, so it never posts twice.
- Until it posts, the folio shows the booking as a **Scheduled** row (`ScheduledFolioRows` adds `facility:{id}` rows), like nights and add-ons. The row moves to Posted when the line exists.
- No charge item ("Charge by hand"): nothing posts and no Scheduled row shows. Staff post the charge themselves.
- **Change after posting:** time, quantity and Charge to are locked. Cancel reverses the line (`ReverseLine`, key `facility-cancel:{id}`), then staff book again.

## Book facility (Dialog)

The same Dialog on the event page, the reservation page and the Timeline View peek.

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
│ Charge to                                │
│ [ Master folio · Borneo Trails       ▾ ] │
│ ⚠ Riverside Hall is held 09:00–17:00     │
│   (Sabah Tourism meeting, Tentative).    │
│ 1,500.00 + SST 120.00                    │
│                        [Cancel] [Book]   │
└──────────────────────────────────────────┘
```

- Five fields, so a Dialog. Facility, date and time prefill from the Timeline cell that opened it.
- The price line and the clash warning come from the server (`facilities.quote`, an optional prop reload). The browser does not calculate them.
- A reservation booking defaults the date to the business date when it is inside the stay, else the arrival date. Dates outside the stay are allowed (a dinner the night before arrival).

## Reservation Facilities tab

A new tab on the [reservation page](../12-reservations/06-reservation-page.md), after Add-ons, two panels like Add-ons. Shown when the feature is on.

```
Facilities                                      [+ Book facility]
Facility        Date    Time         Qty     Charge to          Amount (MYR)
Riverside Hall  12 Dec  19:00–22:00  20 pax  Lee Chong Wei      1,500.00   ⋯
Kayak           11 Dec  09:00–11:00   2      Room 101 folio        80.00   ⋯
```

- Row menu: Edit (before posting), Post now, Cancel.
- Cancelling the reservation cancels its facility bookings. `CancelReservation` calls `CancelFacilityBookings` (an Action of this module), so the reservations module never touches `facility_bookings`.

## Event Bookings tab

The same table on the event page, without the Charge to column (always the event folio). New bookings take the event's date and status.

## Actions

| Action                   | Does                                                                                                                                                                  |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BookFacility`           | Locks the facility, runs the clash check, creates the booking. Refuses `facility_clash`, `facility_full`, `facility_inactive`, `folio_not_open`, `folio_not_of_owner` |
| `UpdateFacilityBooking`  | Time, quantity, Charge to, notes. Refuses `facility_booking_posted` after posting                                                                                     |
| `CancelFacilityBooking`  | → Cancelled. Reverses the posted line when there is one                                                                                                               |
| `CancelFacilityBookings` | Cancels all bookings of a reservation or an event                                                                                                                     |
| `PostFacilityBooking`    | Posts one booking (Post now and the night audit). Idempotent by key                                                                                                   |
| `PostFacilityCharges`    | The night audit step body for one business date                                                                                                                       |

## Routes

Need `facilities.book` and the `Facilities` feature.

| Route                                                 | Name                       |
| ----------------------------------------------------- | -------------------------- |
| `GET /p/{code}/reservations/{reservation}/facilities` | `reservations.facilities`  |
| `POST /p/{code}/facility-bookings`                    | `facility-bookings.store`  |
| `PUT /p/{code}/facility-bookings/{booking}`           | `facility-bookings.update` |
| `POST /p/{code}/facility-bookings/{booking}/post`     | `facility-bookings.post`   |
| `POST /p/{code}/facility-bookings/{booking}/cancel`   | `facility-bookings.cancel` |
