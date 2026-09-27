# Folio discounts

## Purpose

Apply a discount to posted charges with correct tax.

## Action

`ApplyDiscount`: posts a negative child line under each target charge (`parent_line_id`) plus negative tax child lines. Tax is then charged on the discounted amount.

## Rules

- Target charges come from the discount's scope (RoomOnly, AllCharges, Selected).
- Reversing a charge also reverses its discount.
- A booking-time discount sets `reservation_units.discount_id` and `unit_nights.discount_amount`. The night audit posts gross room + discount line, so reports show gross revenue and discounts.
- `RemoveDiscount` is a reversal.

## Permissions

`folio.discount`, `folio.discount-open`.

## Future

A maximum discount percentage per role.
