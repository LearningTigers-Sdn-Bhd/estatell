# Checkout and hold

## Purpose

Keep a room for a guest while they pay, so a guest does not pay for a room someone else just took.

## Flow

```
Offer ──► Guest details ──► Hold (20 minutes) ──► Payment ──► Confirmed
                                  │
                                  └─ not paid in time ──► Expired, room free again
```

## Data model

```
booking_holds        id, property_id, token (unique), status (BookingHoldStatus),
                     arrival_date, departure_date, total_amount, expires_at,
                     guest_name, guest_email, guest_phone, guest_nationality,
                     special_requests (nullable), reservation_id (nullable),
                     timestamps
booking_hold_lines   id, booking_hold_id, room_type_id, rate_plan_id,
                     adults, child_ages, amount, position, timestamps
```

- One line is one room or bed, as a reservation unit is.
- Guest details are encrypted like guest records, and removed once the hold has been expired for 30 days. A guest record exists only after a booking is confirmed.
- `BookingHoldStatus`: Held, Paid, Confirmed, Expired, with `label()`, `tone()` and `canTransitionTo()`.

| From    | To                 |
| ------- | ------------------ |
| Held    | Paid, Expired      |
| Expired | Paid (a late bank) |
| Paid    | Confirmed, Expired |

## Create a hold

`CreateBookingHold` takes the property, the chosen lines, the guest details and the total the guest saw.

| Check, in order                                           | Refusal                |
| --------------------------------------------------------- | ---------------------- |
| The arrival is today or later, and at most 6 months ahead | `outside_window`       |
| Each plan is public and active, and sells the room type   | `plan_not_available`   |
| Restrictions pass (`RestrictionCheck`)                    | the restriction reason |
| Rooms are free on every night, under the room type lock   | `not_available`        |
| The stay can be priced                                    | the quote refusal      |
| The total equals the total now                            | `price_changed`        |
| Name, email, phone and nationality are valid              | a field error          |

The room type rows are locked in id order, as `AddUnit` does, so holds and staff bookings cannot sell the same room twice.

## Holds and availability

- `AvailabilityService` subtracts the lines of holds that are Held with `expires_at` in the future, and of holds that are Paid.
- The expiry time alone decides. A hold past `expires_at` stops counting at once, so availability stays right with no job running, including on the demo environment.
- A scheduled job marks old holds Expired and purges guest details. It is tidying only.
- A change in held rooms changes what is sent to channels, because ARI reads `AvailabilityService`. An expiry with no job running may reach channels late, so the job also triggers an ARI update for the affected dates.
- Overselling can still happen between an OTA booking and the 10–30 second ARI delay. OTA bookings are always accepted and flagged as overbooked, as today.

## Guest details

| Field       | Rule                                                                                           |
| ----------- | ---------------------------------------------------------------------------------------------- |
| Name        | Required                                                                                       |
| Email       | Required: it receives the Manage booking link                                                  |
| Phone       | Required, parsed like the guest form                                                           |
| Nationality | Required, because `TaxCalculator` refuses with `nationality_required` and tourism tax needs it |

No IC or passport number is asked for online. Identity is taken at registration.

## Taxes in the online total

The booking engine never checks which tax it is. It reads the property's **Collect at check-in** switch (`taxes.collect_at_check_in`), so no code names tourism tax.

**A flagged tax on a room night is paid at the desk. Every other tax is paid online.** Decided 2026-11-03.

| Part                 | Behaviour                                                                                                    |
| -------------------- | ------------------------------------------------------------------------------------------------------------ |
| Who a tax applies to | Existing tax rules decide, such as `foreign_guests_only` with the guest's nationality                        |
| Posting              | Unchanged: every tax still posts on the folio each night, so the desk amount shows as a balance              |
| Check-in             | Unchanged: `CheckInCollection` asks staff for the flagged taxes                                              |
| Add-ons              | A flagged tax on an add-on or plan inclusion is paid online, because check-in collects only room-night taxes |
| API                  | Offers return `pay_online` and `pay_at_property`, each with a line per tax, named from the tax record        |
| Cancellation         | The desk amount was never paid, so there is nothing to refund for it                                         |
| Commission           | No effect: commission is on the room total before tax                                                        |

The online total and check-in use one shared Service for this rule (`CheckInTaxRule`), so a guest never pays a tax twice or not at all. An inclusive flagged tax comes out of the online total, because the guest pays it at the desk. `booking_holds.total_amount` is the online total.

Example, 2 nights at RM 200, with SST 8% not flagged and tourism tax RM 10 a night for foreign guests, flagged:

| Guest     | Pays online | Pays at the desk |
| --------- | ----------- | ---------------- |
| Malaysian | RM 432      | RM 0             |
| Foreign   | RM 432      | RM 20            |

```
┌─ Your booking ────────────────────────┐
│ Deluxe King · 2 nights                │
│ Room                       RM 400.00  │
│ SST 8%                     RM  32.00  │
│ Pay now                    RM 432.00  │
│                                       │
│ Pay at the property                   │
│ Tourism tax                RM  20.00  │
│                                       │
│ [ Pay RM 432.00 ]                     │
└───────────────────────────────────────┘
```

## Lines of a booking

A guest books and cancels the whole booking online. Group bookings do not get a Master folio by default: each unit has its own folio, and the amount is split to match. See [Payment](04-payment.md#on-the-folio).
