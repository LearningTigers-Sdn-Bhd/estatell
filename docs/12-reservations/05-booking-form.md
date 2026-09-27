# Booking form

## Purpose

Create a booking in as few fields as possible.

## Create step

1. Dates.
2. Lines: room type + rate plan per line.
    - Room lines: pax count.
    - Bed lines: "Beds: N", expanded into N units.
    - Package plans: departure is locked, the stay total is shown.
3. Optional: company, add-ons, discount.
4. Guest: search, or quick-add with name and phone. `GuestMatcher` suggests existing guests.
5. Price: auto-quoted by `RateQuoteService`, with an optional override (logged) or discount.

## Check-in step

ID, registration and address fields move to the [registration card](../16-registration/04-signing-flow.md).

## Implementation

- Inertia `<Form>` or `useForm` with a Form Request. No client-side validation library.
- One Action per concern: `CreateReservation`, `AddUnit`, `AttachGuest`, `RecordPayment`. No god service.

## Lessons from wastays

The booking creation form was 335 lines of ERB plus a 761-line `booking_room_rows_controller.js`. `CreateManualBooking` (439 lines) mixed guest matching, rate override, prepayment and audit.
