# Discounts catalogue

## Purpose

Predefined discounts staff can apply on a folio or at booking.

## Data model

```
discounts                    id, property_id, transaction_code_id (unique), name,
                             type (DiscountType), value (nullable), allow_override,
                             scope (DiscountScope), position, active, timestamps
discount_transaction_code    discount_id, transaction_code_id
```

Each discount **owns one discount transaction code** (kind Adjustment, category Discount). The code gives the discount its visible code and its own report line, so "Staff rate" and "Complaint" are reported apart. The property currency applies to a Fixed `value`.

## Enums

- `DiscountType`: Percent, Fixed, Open (staff enter the amount).
- `DiscountScope`: RoomOnly, AllCharges, Selected (uses the pivot).

## Starter discount

`SeedDiscounts` runs inside `SeedPropertyDefaults`. Every Malaysian property receives one active **Discount**: Open, AllCharges, owning the existing `DISCOUNT` system code.

- Seeding again restores it when missing and keeps property changes.
- Staff can rename, reprice, reorder and deactivate it. Its code does not change.
- The table migration backfills existing Malaysian properties.

## Lifecycle

- Creating a discount creates its discount code in the same transaction. The code is not a system code.
- Codes are upper case with `_` separators and at most 20 characters. They are unique among all transaction codes of the property.
- Renaming a discount renames its code. Deactivating a discount deactivates its code. Reactivation puts the discount at the end of the active list and reactivates the code.
- Names are unique within a property, ignoring case. Deactivating a discount does not free its name.
- Active discounts have an explicit display order.

## Pricing

| Type    | `value`                  | `allow_override`                              |
| ------- | ------------------------ | --------------------------------------------- |
| Percent | 1–100                    | Always false. The percentage is never changed |
| Fixed   | Greater than 0           | Staff choose. True lets staff change it       |
| Open    | Null ("Enter when used") | Always true                                   |

Database CHECKs enforce every row of this table.

## Scope

| Scope      | Targets                                                                          |
| ---------- | -------------------------------------------------------------------------------- |
| RoomOnly   | The five room revenue codes (see [Room revenue](06-room-revenue.md))             |
| AllCharges | Every charge code except tax                                                     |
| Selected   | The codes in the pivot. At least one. Only non-tax charge codes of this property |

## Rules

- Rate-level discounts use derived rate plans instead (see [Derived rate plans](../08-rates/04-derived-rate-plans.md)). Automatic stay-length discounts are [long-stay discounts](../08-rates/01-rate-plans.md#long-stay-discounts), not catalogue entries.
- Folio-level application is described in [Folio discounts](../13-folio/04-folio-discounts.md).
- Only RoomOnly discounts are offered on the booking form, because no other charges exist at booking.

## Page

Settings → Billing setup → Discounts (permission `settings.manage`). The list shows name, code, pricing ("10%", "MYR 50.00" or "Enter when used") and scope ("Room only", "All charges", or the selected item names). Active rows move up and down with arrow buttons. The form is a sheet; the charge picker appears only for Selected and lists inactive charges too.

## Permissions

Managing the catalogue: `settings.manage`. Applying: `folio.discount`, `folio.discount-open`.

## Lessons from wastays

`hotel_pricing_rules` mixed discounts with rate overrides using a `weekdays` array and jsonb metadata. Split into derived rate plans, daily rates and this catalogue. `hotel_discounts` already owned a unique discount code with manual, fixed and percentage pricing and an amount override; that shape is kept.
