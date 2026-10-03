# Folio discounts

## Purpose

Apply a discount to posted charges with correct tax.

## Flow

1. Staff pick a discount. `DiscountQuote` (service) returns the eligible charges, their subtotal, the calculated amount and a fingerprint.
2. Staff review the quote, and enter or change the amount when the discount allows it.
3. `ApplyDiscount` recalculates the quote. When the fingerprint differs, it refuses with "Folio charges changed. Review the discount again."
4. `ApplyDiscount` posts a negative discount line under each target charge (`parent_line_id` is the charge), plus negative tax lines under each discount line (`parent_line_id` is the discount line).

## Eligible charges

- Charge lines on the folio that match the discount's scope (RoomOnly, AllCharges, Selected).
- Not reversed, with a positive amount, posted on or before the business date, and not a child line.
- Tax lines are never targets.
- **A charge carries at most one discount.** A charge that already has a discount line is not eligible, whether the discount came from the folio or from the night audit (booking-time discount). To change a discount, remove it and apply another.
- The folio is Open.

## Amount

| Type    | Amount                                                                    |
| ------- | ------------------------------------------------------------------------- |
| Percent | Percentage × each eligible charge's net amount                            |
| Fixed   | The discount value, or the amount staff enter when `allow_override` is on |
| Open    | The amount staff enter                                                    |

- The net amount of a charge is its line `amount`, which is already without an inclusive tax.
- The amount is greater than 0 and at most the eligible subtotal.
- A Fixed or Open amount is split across the eligible charges in proportion to each net amount. Each share is rounded in the property currency. The rounding remainder goes to the last charge in line-ID order.
- A discount line has `quantity` 1 and `unit_amount` equal to its negative `amount`. Its `kind` is Adjustment and its code is the discount's own code.

## Tax on a discount

The discount lowers the tax that the charge carries. It does not run `TaxCalculator` again.

- For each **percent** tax line of the charge, the discount posts a negative tax line under the discount line: the tax amount × the share ÷ the net amount, rounded in the property currency. The tax code is the tax line's own code.
- **Flat taxes** (for example the RM10 per room-night tourism tax) get no line. A discount never reduces a flat tax.
- Because the proportion comes from the charge's own tax lines, inclusive taxes and foreign-guest taxes follow the charge without extra rules.
- The Tax row is read for its rate type. A tax that was deleted after the charge posted is treated as flat (no line). The Tax row is found by the tax line's transaction code and the property.

## Fingerprint

A SHA-256 hash of the discount (ID, `updated_at`, type, value, scope, selected codes), the business date, and each eligible line's ID and amount. It stops a quote from being posted after the charges or the discount changed. A charge that gets a discount leaves the eligible set, so the fingerprint changes and a second click is refused as changed.

## Actions

All Actions return a result and write nothing on a refusal. Each one is one transaction on the folio lock. Permissions are checked by the route, not the Action.

### `ApplyDiscount`

`(User $actor, Folio $folio, Discount $discount, ?string $amount, string $fingerprint): PostingResult`.

| Check, in order                                                                     | Refusal                       |
| ----------------------------------------------------------------------------------- | ----------------------------- |
| A business date exists                                                              | `no_business_date`            |
| The discount is active and belongs to the folio's property                          | `discount_not_found`          |
| The folio is Open                                                                   | `folio_not_open`              |
| At least one charge is eligible                                                     | `nothing_eligible`            |
| An Open discount, or a Fixed one with no value, has an amount                       | `amount_required`             |
| A given amount is greater than 0 with at most two decimals and at most the subtotal | `amount_invalid`              |
| A given amount that differs from a Fixed value needs `allow_override`               | `amount_override_not_allowed` |
| The fingerprint still matches the recalculated quote                                | `quote_changed`               |

- Keys: the discount line is `discount:{discount_id}:{charge_line_id}`, its tax lines are `{discount key}:tax:{tax_id}`.
- `posting_date` is the business date. Description and kind are copied at posting time.
- It logs `folio.discount_applied` on the reservation.

### `RemoveDiscount`

`(User $actor, FolioLine $discountLine): PostingResult`. It reverses the discount line and its tax lines. The charge stays.

| Check                                                            | Refusal            |
| ---------------------------------------------------------------- | ------------------ |
| A business date exists                                           | `no_business_date` |
| The line is a discount line (Adjustment with a parent charge)    | `not_a_discount`   |
| The line is not already reversed, and its charge is not reversed | `already_reversed` |
| The folio is Open                                                | `folio_not_open`   |

It logs `folio.discount_removed`.

## Rules

- Reversing a charge also reverses its discount line and the discount's tax lines. `ReverseLine::post()` and `TransferLines` handle every level of children, not only the first, and skip a child that already has a reversal. Without the skip, reversing a charge after its discount was removed breaks on the unique `reverse:{line_id}` key. This item builds the recursion and the skip, and adds the tests (see [Posting charges](02-posting-charges.md#rules)).
- A booking-time discount sets only `reservation_units.discount_id` (RoomOnly discounts only). `unit_nights.discount_amount` holds the rate plan's stay discount, not this one. The night audit calculates the catalogue discount for each night and posts gross room + discount line, so reports show gross revenue and discounts.
- A discount applies to the folio that holds the charges. With routing, a RoomOnly discount finds nothing on a unit folio when the room lines are on the master folio ([Routing](03-routing.md)).
- `RemoveDiscount` is a reversal.

## Permissions

| Permission            | Allows                                                                | Default roles                |
| --------------------- | --------------------------------------------------------------------- | ---------------------------- |
| `folio.discount`      | Apply a Percent or an unchanged Fixed discount, and remove a discount | Manager, Finance, Front desk |
| `folio.discount-open` | Apply an Open discount, or any amount that staff enter or change      | Manager, Finance             |

A role with `folio.discount-open` also has `folio.discount`. The Owner has both. The two permissions and their role defaults are added in the build commit, with a migration that syncs them.

## Build scope

Backend only: `DiscountQuote`, a quote data class, `ApplyDiscount`, `RemoveDiscount`, the two permissions, and the recursive reversal and transfer. No schema change: the parent link already allows two levels and `discount:{id}:{line}` fits `idempotency_key`. The folio screen and the night audit discount posting come later.

## Build status

Built: `DiscountQuote` (service) with `DiscountQuoteData` and `DiscountQuoteLineData`, `ApplyDiscount`, `RemoveDiscount`, the refusal codes on `PostingResult`, the recursive `ReverseLine::post()` and `TransferLines` (every level, already reversed children skipped), and the permissions `folio.discount` and `folio.discount-open` with a syncing migration. Not built: the folio screen, the night audit discount posting, the booking-time discount posting.

Decisions made at build time:

- `DiscountQuote::quote(Folio, Discount, CarbonImmutable $businessDate, ?string $amount)` also checks the amount and returns the refusal code in `amountRefusal`. `ApplyDiscount` passes it on after `nothing_eligible`, in the order of the table above.
- A Percent discount ignores a given amount. The percentage is never changed, and the form can send back what it shows.
- A discount line counts as "carried" while it has no reversal. After `RemoveDiscount`, the charge is eligible again for any discount **except the same one**: the key `discount:{discount_id}:{charge_line_id}` is unique in the ledger, so a removed discount cannot be applied to that charge a second time. Staff pick another discount. A charge that was transferred is a new line, so it has no history.
- A share that rounds to 0.00 posts no discount line, and a tax reduction that rounds to 0.00 posts no tax line. When every share is 0.00, the Action refuses with `nothing_eligible`.
- The discount line description is the discount name. A discount tax line copies the description of the tax line it reduces.
- Eligible charges are lines of kind Charge. The AllCharges scope needs no code lookup beyond that, and the RoomOnly scope uses the `system_key` of the five room revenue codes.
- `TransferLines` does not copy a reversed child (a removed discount stays removed). `RemoveDiscount` posts the reversal of the discount line without a parent, the same as any single reversal.
- Logged on the reservation: `folio.discount_applied`, `folio.discount_removed`.

## Future

A maximum discount percentage per role.

## Lessons from wastays

`Discounts::Quote` used the same quote-then-post fingerprint. It posted one lump discount line and left tax on the full amount; per-charge child lines fix that.
