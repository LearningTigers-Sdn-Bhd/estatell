# Derived rate plans

## Purpose

Rate-level discounts that follow a parent plan: Member -10%, Walk-in -RM20, Corporate -15%.

## Data model

Columns on `rate_plans`: `parent_rate_plan_id`, `derivation`, `derivation_value`.

## Service

`DerivedRateResolver`: returns the parent's amount adjusted by the derivation, for a date.

## Rules

- A derived plan has no own `rates.amount`. It can still have its own restriction rows.
- Derivation chains are limited to one level.
- Companies can be linked to derived plans (see [Companies](../15-city-ledger/01-companies.md)).

## Future

Promo codes: a `promotions` table (code, booking window, stay window, minimum nights) that points to a derived rate plan.

## Lessons from wastays

Replaces the walk-in, corporate and OTA types of `hotel_pricing_rules`.
