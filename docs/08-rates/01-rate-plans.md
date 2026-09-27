# Rate plans

## Purpose

A rate plan is how a room type is priced and sold: Standard, Room + Breakfast, Member, 3D2N Island Escape.

## Data model

```
rate_plans              id, property_id, name, description, pricing_basis (PricingBasis),
                        base_occupancy, extra_adult_amount, extra_child_amount, single_supplement,
                        parent_rate_plan_id (nullable), derivation (RateDerivation, nullable),
                        derivation_value (nullable),
                        is_package, fixed_nights (nullable),
                        early_departure_policy (EarlyDeparturePolicy),
                        position, archived_at, timestamps
rate_plan_age_bands     id, rate_plan_id, label, min_age, max_age,
                        pricing (AgeBandPricing), value, position
```

## Enums

- `PricingBasis`: PerRoom, PerGuest, PerBed, Package.
- `RateDerivation`: PercentOff, AmountOff.
- `EarlyDeparturePolicy`: ChargeFull, ChargeUsedNights.
- `AgeBandPricing`: Percent, Fixed.

## Sell unit and pricing basis

| Room type `sell_unit` | Allowed `pricing_basis`    |
| --------------------- | -------------------------- |
| Room                  | PerRoom, PerGuest, Package |
| Bed                   | PerBed, Package            |

The Form Request enforces this with `Rule::enum(PricingBasis::class)->only(...)`.

## Rules

- `rate_plan_age_bands` apply to PerGuest plans.
- `is_package` and `fixed_nights` are described in [Package prices](../09-packages/02-package-prices.md).
- Onboarding creates one "Standard" plan per property (see [Wizard steps](../17-onboarding/02-wizard-steps.md)).

## Lessons from wastays

wastays forced one `sell_mode` on the hotel and every plan inherited it.
