# Commission rules

## Purpose

Decide which bookings pay commission, on what amount, and on which date. Each service has one rule class, so a new service plugs in without changing the ledger, vouchers or pages.

## Services

| Service         | Rate | Applies when                                                                 | Base (before tax)                                                                                                  |
| --------------- | ---- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Booking engine  | 10%  | The reservation has the Booking Engine source and a captured Curlec payment  | The room total at booking. On a cancellation, the fee the property keeps                                           |
| Channel manager | 3%   | The reservation came from Channex (it has a `channel_booking_revisions` row) | The folio lines `RoomRevenue`, `CancellationRevenue`, `NoShowRevenue` and `EarlyDepartureRevenue`, after reversals |

- **Before tax.** SST, tourism tax and other tax lines never count.
- **Booking engine extras.** Nights or charges that staff add later at the desk do not count. Only what the guest booked and paid online.
- **Channel manager extras.** Food and drink, laundry, minibar, damage, late check-out and other fees do not count. Channex's `amount` is not used, because it includes tax or the OTA's commission depending on the OTA.
- **Both services on one booking.** Not possible: a booking engine booking does not come from Channex.

## Due date

The due date decides which payout cycle an entry goes into.

| Booking ends by | Due date                                          |
| --------------- | ------------------------------------------------- |
| Check-out       | The last actual check-out date of the reservation |
| Cancellation    | The date the reservation was cancelled            |
| No-show         | The date the no-show was recorded                 |

- A reservation with rooms that leave on different dates goes into the cycle of its last check-out.
- An early check-out uses the actual date, not the booked date.
- Commission is recorded once the booking ends, from the final folio and payment figures.

## Cancellations and no-shows

| Case                             | Booking engine            | Channel manager                        |
| -------------------------------- | ------------------------- | -------------------------------------- |
| Cancelled inside the free window | No commission             | No commission                          |
| Cancelled with a fee kept        | 10% of the fee before tax | 3% of `CancellationRevenue` before tax |
| No-show with a fee charged       | 10% of the fee before tax | 3% of `NoShowRevenue` before tax       |
| No fee charged                   | No commission             | No commission                          |

Example: an Agoda booking is cancelled and the property keeps RM 200 before tax. Commission is RM 6.00.

## Rates

- Each service has one platform rate, stored in `commission_rates` with the date it starts.
- The rate for a booking is the one in force on the date the booking was made.
- Each commission entry saves the base and the rate it used. A later rate change never changes past entries.

## Channel manager start date

Properties already used the channel manager before this module. Admins set a go-live date for channel manager commission. Only reservations whose due date is on or after it pay commission.

## Corrections

When a booking changes after its commission was recorded (a fee reversed on the folio, a late refund), the rule computes the new commission. The ledger records a reversal of the old entry and a new entry, both with the new due date in the current cycle. A paid voucher never changes.

## Code shape

| Part                       | What it is                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------- |
| `CommissionService` enum   | `BookingEngine`, `ChannelManager`. `label()`, the feature class, and the rule class |
| `CommissionRule` interface | `appliesTo(Reservation)`, `base(Reservation)`, `dueDate(Reservation)`               |
| `BookingEngineCommission`  | The booking engine rule                                                             |
| `ChannelManagerCommission` | The channel manager rule                                                            |
| `RecordCommission` Action  | Runs every enabled rule for a reservation and writes the entries                    |

Adding a service later is one enum case, one rule class and one listener for the event that ends its bookings.
