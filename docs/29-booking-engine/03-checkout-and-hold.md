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
booking_holds               id, property_id, booking_client_id, token (unique),
                            status (BookingHoldStatus), arrival_date, departure_date,
                            total_amount, expires_at, guest_name, guest_email,
                            guest_phone, guest_nationality, special_requests,
                            guest_ip_hash, reservation_id (nullable), timestamps
booking_hold_lines          id, booking_hold_id, room_type_id, rate_plan_id,
                            adults, amount, position, timestamps
booking_hold_line_children  id, booking_hold_line_id, age
```

- One line is one room or bed, as a reservation unit is. One child row is one child, as in `reservation_unit_children`.
- `booking_client_id` is the client that made the hold. Only that client can read or release it.
- Guest details use Laravel's `encrypted` cast, because nothing searches them and most holds never become bookings. Guest records are different: their email and phone stay plaintext for search.
- A guest record exists only after a booking is confirmed.
- The purge empties the guest fields and `guest_ip_hash`, so those columns are nullable. They are required when a hold is created.
- `BookingHoldStatus`: Held, Paid, Confirmed, Expired, with `label()`, `tone()` and `canTransitionTo()`.

| From    | To                 |
| ------- | ------------------ |
| Held    | Paid, Expired      |
| Expired | Paid (a late bank) |
| Paid    | Confirmed, Expired |

## Create a hold

`CreateBookingHold` takes the property, the chosen lines, the guest details and the total the guest saw.

| Check, in order                                                            | Refusal                               |
| -------------------------------------------------------------------------- | ------------------------------------- |
| Name, email, phone, nationality and the lines are valid (the Form Request) | a field error                         |
| A Microsite request has `X-Guest-IP`                                       | `guest_ip_required`                   |
| The arrival is today or later, and at most 6 months ahead                  | `outside_window`                      |
| Each plan is public and active, and sells the room type                    | `plan_not_available`                  |
| The party fits, the stay can be priced, and restrictions pass              | the same code as a blocked offer      |
| Rooms are free on every night for all lines of the room type               | `not_available`                       |
| The [open hold limits](#open-hold-limits) allow it                         | `hold_limit_reached`                  |
| The total equals the total now                                             | `price_changed`, with `current_total` |

- From the plan check on, the checks run under the room type lock. They use `BookingOffers`, so offers and holds follow the same rules.
- The room type rows are locked together, in id order, so holds and staff bookings cannot sell the same room twice.
- `AddUnit` locks one room type per line, in line order, so a booking with several room types can rarely deadlock with a hold. The hold's transaction retries, so the guest does not see an error.

## Release a hold

`DELETE /holds/{token}` lets a guest who goes back free their room at once. `ReleaseBookingHold` marks a Held hold Expired. Any other status refuses with `hold_not_open`. A guest who still pays after releasing follows [Paid after the hold expired](04-payment.md#paid-after-the-hold-expired).

## Open hold limits

| Limit        | Rule                                                                 | Counts                                        |
| ------------ | -------------------------------------------------------------------- | --------------------------------------------- |
| Per property | At most half of the property's rooms and beds, rounded up, minimum 2 | Lines of open holds, including the new hold's |
| Per guest IP | At most 3 open holds                                                 | Open holds with the same `guest_ip_hash`      |

- An open hold is Held with `expires_at` in the future, or Paid.
- `guest_ip_hash` is an HMAC of the address with the app key, so the address itself is not stored.
- The property limit also caps one online booking. A 10-room hotel takes at most 5 rooms in one hold.

See [Limits](../28-api-access/04-booking-engine-microsite.md#limits) for how the header is trusted.

## Holds and availability

- `AvailabilityService` subtracts the lines of holds that are Held with `expires_at` in the future, and of holds that are Paid. It gets them from the `HeldUnits` Service of this module, in one query. `NightAvailability` shows them as `held`.
- The expiry time alone decides. A hold past `expires_at` stops counting at once, so availability stays right with no job running, including on the demo environment. `GET /holds/{token}` also shows such a hold as Expired.
- `ExpireBookingHolds` runs every minute and marks old holds Expired. It is tidying only.
- `PurgeBookingHoldGuestDetails` runs daily. It empties the guest details of holds that have been Expired or Confirmed for 30 days.
- A change in held rooms changes what is sent to channels, because ARI reads `AvailabilityService`. Creating, releasing and expiring a hold each fire `InventoryChanged` for the affected room types and dates. An expiry with no job running may reach channels late.
- Overselling can still happen between an OTA booking and the 10–30 second ARI delay. OTA bookings are always accepted and flagged as overbooked, as today.
- Staff cannot see holds. A room can look free on the Stay View but refuse a new booking with "not available" for up to 20 minutes.
- Night audit statistics use total minus blocked, so holds do not change them.

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
