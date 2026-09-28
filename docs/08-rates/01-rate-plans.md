# Rate plans

## Purpose

A rate plan is how room types are priced and sold: Basic Rate, Breakfast, Corporate, 3D2N Island Escape. A plan is defined once and linked to many room types.

## Data model

```
rate_plans              id, property_id, name, description, pricing_basis (PricingBasis),
                        extra_adult_amount, extra_child_amount, single_supplement,
                        parent_rate_plan_id (nullable), derivation (RateDerivation, nullable),
                        derivation_value (nullable, signed),
                        is_package, fixed_nights (nullable),
                        early_departure_policy (EarlyDeparturePolicy),
                        agent_access (AgentAccess, default Hidden),
                        position, archived_at, timestamps
rate_plan_room_types    id, rate_plan_id, room_type_id, timestamps
                        unique (rate_plan_id, room_type_id)
rate_plan_child_prices  id, rate_plan_id, property_age_group_id,
                        pricing (AgeBandPricing), value (nullable)
                        unique (rate_plan_id, property_age_group_id)
rate_plan_stay_discounts
                        id, rate_plan_id, min_nights, discount_type (StayDiscountType),
                        value, from_night, timestamps
                        unique (rate_plan_id, min_nights)
```

- `rate_plan_room_types` is a many-to-many link. It holds no prices. Prices live only in [daily rates](02-daily-rates-and-restrictions.md).
- The number of guests a price covers is the room type's `base_occupancy` ("Guests included"), not a plan column (see [Room types](../05-rooms/01-room-types.md)).
- There is no default plan flag. `position` sets the order.

## Enums

- `PricingBasis`: PerRoom, PerGuest, PerBed, Package.
- `RateDerivation`: Percent, Amount. See [Derived rate plans](04-derived-rate-plans.md).
- `EarlyDeparturePolicy`: ChargeFull, ChargeUsedNights.
- `AgeBandPricing`: Free, Percent, Fixed.
- `StayDiscountType`: Percent, Amount.
- `AgentAccess`: Hidden, All, Selected. Selected uses `company_rate_plans`. See [Agent portal](../23-agent-portal/01-agent-portal.md).

## Sell unit and pricing basis

A plan links only room types whose sell unit fits its pricing basis.

| Room type `sell_unit` | Allowed `pricing_basis`    |
| --------------------- | -------------------------- |
| Room                  | PerRoom, PerGuest, Package |
| Bed                   | PerBed, Package            |

Plans fall into two families: room plans and bed plans. Package is the only basis that can link both. The Form Request enforces the table with `Rule::enum(PricingBasis::class)->only(...)`.

## Rules

- `rates`, `package_prices` and `channel_rate_plan_mappings` exist only for a linked plan and room type pair.
- The first linked plan by `position` is preselected on the booking form and listed first on the public site.
- A booking can move only to a room type that is linked to its plan (see [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md)).
- Property creation seeds one ordinary plan, "Basic Rate". New room types are linked to it when the sell unit fits (see [Seed property defaults](../02-property/03-seed-property-defaults.md)).
- Child prices apply to PerGuest plans. A PerGuest plan has one row per [age group](../02-property/04-age-groups.md). PerRoom plans use `extra_child_amount`.
- A new PerGuest plan is pre-filled: the first group Free, every other group 50%.
- `Percent` is a percent of the plan's **1-adult** price for that night, never the party's per-adult share.
- `is_package` and `fixed_nights` are described in [Package prices](../09-packages/02-package-prices.md).

## Guards

| Guard                                                                 | Message                                                        |
| --------------------------------------------------------------------- | -------------------------------------------------------------- |
| Every room type keeps at least one active linked plan                 | "6-bed Dorm would have no rate plan. Link another plan first." |
| A parent cannot be archived while active plans follow it              | "Breakfast and Corporate follow this plan."                    |
| A plan used by a reservation cannot be deleted. Archive it instead.   | "Used by 42 reservations. Archive instead."                    |
| A room type cannot be unlinked while future reservations use the pair | "3 upcoming bookings use this."                                |

Unlinking a room type with no future reservations deletes the pair's future `rates` rows. Past rows stay.

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

Three screens, one job each:

| Screen         | Job                                                                  | Stores prices                   |
| -------------- | -------------------------------------------------------------------- | ------------------------------- |
| Rooms          | Room types, rooms, beds. "N rate plans" opens the filtered calendar. | No                              |
| Rate plans     | What a plan is, which room types sell it, how it is priced           | No. Pricing only fills `rates`. |
| Rates calendar | Price and restrictions per room type, plan and date                  | Yes                             |

### Rate plans list

A table with name, family (Room or Bed), pricing summary ("Per room", "Basic Rate +RM50") and linked room types. Rows are reordered by drag, which sets `position`.

### New plan

One question per step, then the editor opens on the Room types tab.

1. Rooms or beds?
2. How is it priced? Only the bases for that family are offered.
3. Name.

### Editor

A full page with tabs: **Details · Room types · Pricing · Children · Discounts · Agents**. Background text goes in info tooltips, not paragraphs under each heading.

| Tab        | Content                                                                                                    |
| ---------- | ---------------------------------------------------------------------------------------------------------- |
| Details    | Name, description (shown to guests and agents), extra guest charges, single supplement                     |
| Room types | A checkbox per room type. Room types of the other family are shown disabled, with the reason in a tooltip. |
| Pricing    | Step 1: "Set prices directly" or "Follow another plan". Package plans show package prices instead.         |
| Children   | PerGuest plans only. One row per age group: Free, Percent or Fixed.                                        |
| Discounts  | Long-stay discounts. "Add long-stay discount" asks one question per step.                                  |
| Agents     | `agent_access`: Hidden, All or Selected                                                                    |

- "Set prices directly" uses the shared [pricing form](02-daily-rates-and-restrictions.md#pricing-form). It writes `rates` rows and stores nothing on the plan.
- "Follow another plan" saves a derivation. It is disabled when no parent is eligible (see [Derived rate plans](04-derived-rate-plans.md#ui)).

## Lessons from wastays

- wastays forced one `sell_mode` on the hotel and every plan inherited it.
- Client feedback on the rate plan page: it "showed too much at once". wastays moved to a tabbed page with step-by-step pricing. estatell keeps that layout.
- A child's % age band was taken from the party's per-adult share, not the 1-adult price. 2 adults + a child of 8 on a Full Board plan came to 600 instead of 620.
- A nightly price came from three layers: `room_types.base_price`, `room_type_rate_plans.pricing_mode` (fixed, multiplier, offset) and daily `room_rates`. Staff could not tell which price won.
- Room types were attached to a plan from a separate "Assign room rate" sheet, and the plan page priced one room type at a time.
- `room_type_rate_plans.primary_plan` added a per-room-type default that staff did not need.
