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
                        agent_access (AgentAccess, default Hidden),
                        position, archived_at, timestamps
rate_plan_child_prices  id, rate_plan_id, property_age_group_id,
                        pricing (AgeBandPricing), value (nullable)
                        unique (rate_plan_id, property_age_group_id)
rate_plan_stay_discounts
                        id, rate_plan_id, min_nights, discount_type (StayDiscountType),
                        value, from_night, timestamps
                        unique (rate_plan_id, min_nights)
```

## Enums

- `PricingBasis`: PerRoom, PerGuest, PerBed, Package.
- `RateDerivation`: PercentOff, AmountOff.
- `EarlyDeparturePolicy`: ChargeFull, ChargeUsedNights.
- `AgeBandPricing`: Free, Percent, Fixed.
- `StayDiscountType`: Percent, Amount.
- `AgentAccess`: Hidden, All, Selected. Selected uses `company_rate_plans`. See [Agent portal](../23-agent-portal/01-agent-portal.md).

## Sell unit and pricing basis

| Room type `sell_unit` | Allowed `pricing_basis`    |
| --------------------- | -------------------------- |
| Room                  | PerRoom, PerGuest, Package |
| Bed                   | PerBed, Package            |

The Form Request enforces this with `Rule::enum(PricingBasis::class)->only(...)`.

## Rules

- Child prices apply to PerGuest plans. A PerGuest plan has one row per [age group](../02-property/04-age-groups.md). PerRoom plans use `extra_child_amount`.
- A new PerGuest plan is pre-filled: the first group Free, every other group 50%.
- `Percent` is a percent of the plan's **1-adult** price for that night, never the party's per-adult share.
- `is_package` and `fixed_nights` are described in [Package prices](../09-packages/02-package-prices.md).
- Onboarding creates one "Standard" plan per property (see [Wizard steps](../17-onboarding/02-wizard-steps.md)).

## Long-stay discounts

"Stay at least N nights, get X off", on one rate plan.

| Column          | Meaning                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------ |
| `min_nights`    | 2 or more                                                                                              |
| `discount_type` | Percent (1–100) or Amount                                                                              |
| `value`         | Greater than 0                                                                                         |
| `from_night`    | 1 discounts every night. 2 keeps the first night at full price, and so on. Between 1 and `min_nights`. |

- The longest rule the stay qualifies for wins. A 5-night stay under "2+ nights 10%" and "4+ nights 20%" gets 20%.
- An Amount is taken off per guest on a PerGuest plan and per room otherwise. A night never goes below zero.
- `RateQuoteService` applies the rule after the pricing strategy, because a night's price depends on the stay length (see [Pricing strategies](03-pricing-strategies.md)).
- Direct bookings only. Long-stay discounts are never sent to the channel manager.
- Example: "3+ nights, 20% off from night 2". A 3-night stay at RM200 a night costs 200 + 160 + 160 = RM520.

## UI

The rate plan editor is a full page with tabs. Pricing and "Add long-stay discount" ask one question per step. Background text goes in info tooltips, not paragraphs under each heading.

## Lessons from wastays

- wastays forced one `sell_mode` on the hotel and every plan inherited it.
- Client feedback on the rate plan page: it "showed too much at once". wastays moved to a tabbed page with step-by-step pricing.
- A child's % age band was taken from the party's per-adult share, not the 1-adult price. 2 adults + a child of 8 on a Full Board plan came to 600 instead of 620.
