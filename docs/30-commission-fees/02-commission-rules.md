# Commission rules

## Purpose

Decide which bookings pay commission, on what amount, and on which date. Each service has one rule class, so a new service plugs in without changing the ledger, vouchers or pages.

## Services

| Service         | Rate | Applies when                                                                               | Base (before tax)                                                                  |
| --------------- | ---- | ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| Booking engine  | 10%  | The reservation has the Booking Engine source and a Curlec payment that Estatell collected | The folio's room revenue (below), capped at the room total the guest booked online |
| Channel manager | 3%   | The reservation came from Channex (it has a `channel_booking_revisions` row)               | The folio's room revenue (below)                                                   |

**Room revenue** is `ReservationRoomRevenue`: the folio lines `RoomRevenue`, `CancellationRevenue`, `NoShowRevenue` and `EarlyDepartureRevenue` on the reservation's folios, with their discounts and reversals. One rule covers a full stay, an early check-out, a fee kept on cancellation or no-show, and one room cancelled out of several.

- **Before tax.** SST, tourism tax and other tax lines never count.
- **Booking engine cap.** Nights or charges that staff add later at the desk do not count: the base never goes above the room total before tax the guest saw online (`booking_hold_lines.room_total`). A hold with no saved room total has no cap.
- **Booking engine and the flag.** The commission applies whenever Estatell collected the money, even if the property's `BookingEngine` flag is turned off later.
- **Late payment with the rooms taken.** No reservation is made and the whole payment is refunded, so nothing goes in the ledger. Estatell pays Curlec's fees.
- **Channel manager extras.** Food and drink, laundry, minibar, damage, late check-out and other fees do not count. Channex's `amount` is not used, because it includes tax or the OTA's commission depending on the OTA.
- **Both services on one booking.** Not possible: a booking engine booking does not come from Channex.

## Due date

The due date decides which payout cycle an entry goes into.

- A booking ends when every unit is checked out, cancelled or no-show (`UnitStatus::hasEnded()`).
- An hourly run (`RecordDueCommissions`) asks each rule for its candidate reservations and keeps the ended ones that are:
    - **new:** nothing recorded yet, with a departure in the last 90 days, or
    - **changed:** already recorded, with a folio line posted or a unit changed in the last 3 hours. Folio lines are never edited, so a charge, reversal, discount or refund always shows as a new line.
- It records each one with the property's **current business date** as the due date: the booking engine money first, then every service's commission.
- Units do not store when they were cancelled or marked no-show, and ledger postings use business dates, so the business date of that run is the due date. The drift is at most one hour.
- A refund or a commission change found on a later run takes that run's business date.
- An undone check-out records nothing; the commission is worked out again when the booking ends again.

## Cancellations and no-shows

| Case                             | Booking engine                          | Channel manager                        |
| -------------------------------- | --------------------------------------- | -------------------------------------- |
| Cancelled inside the free window | No commission                           | No commission                          |
| Cancelled with a fee kept        | 10% of `CancellationRevenue` before tax | 3% of `CancellationRevenue` before tax |
| No-show with a fee charged       | 10% of `NoShowRevenue` before tax       | 3% of `NoShowRevenue` before tax       |
| No fee charged                   | No commission                           | No commission                          |

Example: an Agoda booking is cancelled and the property keeps RM 200 before tax. Commission is RM 6.00.

## Rates

- Each service has one platform rate, stored in `commission_rates` with the date it starts.
- The rate for a booking is the one in force on the date the booking was made.
- Each commission entry saves the base and the rate it used. A later rate change never changes past entries.

## Charging periods

Each property is charged per service only inside its **charging periods** (`property_commission_periods`). Admins start, stop and cancel them on the admin property page, Commissions tab.

- **No period, no commission.** A new property, or one an admin never started, pays nothing for that service. This holds for the booking engine too.
- **Charge date.** A booking is charged when its **check-out date** (the latest unit departure date) falls inside a period, end date included. The check-out date does not move when the booking is checked again later, so a period that starts after a booking ended never charges it, and a correction after a stop still follows the booking.
- **Money is not a commission.** Booking engine money (collected, refunded, refund fee) is recorded with or without a period, because Estatell holds the guest's money either way.
- **Start:** from today or a later date (Malaysia time), with no end. A service has at most one open period, and a new period cannot overlap an old one.
- **Stop:** sets the last day charged, today or later and not before the start. Commission already recorded stays and is paid out as usual; stopping is never a refund.
- **Cancel:** removes a period that has not started yet, to fix a wrong start date. A period that has started is kept, because recorded commission depends on it.
- Every start, stop and cancel is logged. Past dates cannot be changed.

The commission applies to every reservation that came through Channex, even if the property's `ChannelManager` flag is turned off later. OTA cancellations arrive with no fee, so they pay nothing unless staff post a cancellation or no-show fee.

## Corrections

On every run, `RecordReservationCommission` works out each service's commission again. When the amount changed (a fee reversed on the folio, a discount), it reverses the live entry and posts a new one (`commission:{service}:{reservation}:{n}`), both on that run's due date. The same amount writes nothing. A paid voucher never changes.

## Code shape

| Part                                 | What it is                                                                                  |
| ------------------------------------ | ------------------------------------------------------------------------------------------- |
| `CommissionService` enum             | `BookingEngine`, `ChannelManager`. `label()`, `featureClass()` and `rule()`                 |
| `CommissionRule` interface           | `candidates()`, `appliesTo(Reservation)`, `base(Reservation)`                               |
| `BookingEngineCommission`            | The booking engine rule                                                                     |
| `ChannelManagerCommission`           | The channel manager rule. Uses `ChannexReservations` (Channels)                             |
| `ReservationRoomRevenue` (Folio)     | The room revenue before tax of a reservation. `ReservationFolioActivity` finds recent lines |
| `OnlineBookingMoney` (BookingEngine) | The collected payment, Curlec's fee, the booked room total and the processed refunds        |
| `RecordBookingEngineMoney` Action    | Writes collected, refunded and refund fee entries once each                                 |
| `PropertyCommissionPeriods`          | `chargeDate()`, `covers()`, and the rows of the property Commissions tab                    |
| `RecordReservationCommission` Action | Runs every service's rule for an ended reservation and keeps the commission entry in line   |
| `RecordDueCommissions` Action        | The hourly run, scheduled in `routes/console.php`                                           |

Adding a service later is one enum case and one rule class: the hourly run asks every rule for its candidates, and charging periods work for it as they are.
