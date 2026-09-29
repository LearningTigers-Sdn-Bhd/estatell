# Booking form

## Purpose

Create a booking in as few fields as possible.

## Create step

1. Dates.
2. Lines: room type + rate plan per line.
    - Room lines: pax count. When children > 0 and the property has age groups, one age field per child. Each age is required.
    - Bed lines: "Beds: N", expanded into N units.
    - Package plans: departure is locked, the stay total is shown.
3. Optional: company, add-ons, discount.
4. Guest: search, or quick-add with name and phone. `GuestMatcher` suggests existing guests.
5. Price: auto-quoted by `RateQuoteService`, with an optional override (logged) or a RoomOnly discount from the [catalogue](../06-catalogues/05-discounts.md).
    - A restriction that fails (stop-sell, closed to arrival or departure, min or max stay) shows a warning. Staff with `rates.override-restrictions` can continue after entering a reason (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).

## Check-in step

ID, registration and address fields move to the [registration card](../16-registration/04-signing-flow.md).

## Implementation

- Inertia `<Form>` or `useForm` with a Form Request. No client-side validation library.
- One Action per concern: `CreateReservation`, `AddUnit`, `AttachGuest`, `RecordPayment`. No god service.

## Lessons from wastays

- wastays only asked for child ages at per-guest hotels and fell back to a flat child price when they were missing, which overcharged infants and undercharged older children.
- The booking creation form was 335 lines of ERB plus a 761-line `booking_room_rows_controller.js`. `CreateManualBooking` (439 lines) mixed guest matching, rate override, prepayment and audit.
