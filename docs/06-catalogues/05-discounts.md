# Discounts catalogue

## Purpose

Predefined discounts staff can apply on a folio or at booking.

## Data model

```
discounts                    id, property_id, transaction_code_id, name,
                             type (DiscountType), value, scope (DiscountScope),
                             position, active, timestamps
discount_transaction_code    discount_id, transaction_code_id
```

## Enums

- `DiscountType`: Percent, Fixed, Open (staff enter the amount).
- `DiscountScope`: RoomOnly, AllCharges, Selected (uses the pivot).

## Rules

- Rate-level discounts use derived rate plans instead (see [Derived rate plans](../08-rates/04-derived-rate-plans.md)).
- Folio-level application is described in [Folio discounts](../13-folio/04-folio-discounts.md).

## Permissions

`folio.discount`, `folio.discount-open`.

## Lessons from wastays

`hotel_pricing_rules` mixed discounts with rate overrides using a `weekdays` array and jsonb metadata. Split into derived rate plans, daily rates and this catalogue.
