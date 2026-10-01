# Folio discounts

## Purpose

Apply a discount to posted charges with correct tax.

## Flow

1. Staff pick a discount. `DiscountQuote` (service) returns the eligible charges, their subtotal, the calculated amount and a fingerprint.
2. Staff review the quote, and enter or change the amount when the discount allows it.
3. `ApplyDiscount` recalculates the quote. When the fingerprint differs, it refuses with "Folio charges changed. Review the discount again."
4. `ApplyDiscount` posts a negative child line under each target charge (`parent_line_id`) plus negative tax child lines. Tax is then charged on the discounted amount.

## Eligible charges

- Charge lines on the folio that match the discount's scope (RoomOnly, AllCharges, Selected).
- Not reversed, with a positive amount, posted on or before the business date.
- Tax lines are never targets.

## Amount

| Type    | Amount                                                                    |
| ------- | ------------------------------------------------------------------------- |
| Percent | Percentage × each eligible charge's net amount                            |
| Fixed   | The discount value, or the amount staff enter when `allow_override` is on |
| Open    | The amount staff enter                                                    |

- The amount is greater than 0 and at most the eligible subtotal.
- A Fixed or Open amount is split across the eligible charges in proportion to each net amount. Each share is rounded in the property currency. The rounding remainder goes to the last charge in line-ID order.
- Tax child lines use `TaxCalculator` on each negative share, so the discount and its taxes always add up.

## Fingerprint

A SHA-256 hash of the discount (ID, `updated_at`, type, value, scope, selected codes), the business date, and each eligible line's ID and amount. It stops a quote from being posted after the charges or the discount changed.

## Rules

- Reversing a charge also reverses its discount.
- A booking-time discount sets only `reservation_units.discount_id` (RoomOnly discounts only). `unit_nights.discount_amount` holds the rate plan's stay discount, not this one. The night audit calculates the catalogue discount for each night and posts gross room + discount line, so reports show gross revenue and discounts.
- `RemoveDiscount` is a reversal.

## Permissions

`folio.discount` for Percent and Fixed. `folio.discount-open` for Open, and for changing a Fixed amount.

## Future

A maximum discount percentage per role.

## Lessons from wastays

`Discounts::Quote` used the same quote-then-post fingerprint. It posted one lump discount line and left tax on the full amount; per-charge child lines fix that.
