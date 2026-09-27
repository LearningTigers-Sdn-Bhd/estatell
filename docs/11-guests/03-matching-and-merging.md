# Matching and merging

## Purpose

Avoid duplicate guests without blocking staff.

## Services and Actions

- `GuestMatcher`: finds existing guests by phone, email or ID blind index. Returns suggestions.
- `MergeGuests`: moves unit guests, bookings (booker), folios (payer) and documents to the surviving guest, and sets `merged_into_id` on the other.

## Rules

- The booking form shows "Existing guest found, use this?" instead of throwing a conflict error.
- Channel bookings run through `GuestMatcher` too.
- Merges are logged.

## Permissions

`guest.merge`.

## Lessons from wastays

About 150 lines of identity-conflict logic lived inside `CreateManualBooking`.
