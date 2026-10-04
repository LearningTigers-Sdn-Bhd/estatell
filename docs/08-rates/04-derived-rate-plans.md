# Derived rate plans

## Purpose

Plans that follow a parent plan's price: Breakfast +RM50, Member −10%, Walk-in −RM20, Corporate −15%.

## Data model

Columns on `rate_plans`: `parent_rate_plan_id`, `derivation` (`RateDerivation`), `derivation_value`.

- `RateDerivation`: Percent, Amount.
- `derivation_value` is signed. −10 with Percent is 10% off. +50 with Amount is RM50 more.

## Service

`DerivedRateResolver`: adjusts a night priced from the parent by the derivation. A result never goes below zero.

- `RateQuoteService` prices a derived plan as its parent: the parent's rates, pricing strategy, extra guest amounts and child prices. Then the resolver adjusts each night. The link is live. A parent price change shows in the derived price at once, because nothing is copied.
- **Percent** scales the base, the extra guests and children, and the supplement.
- **Amount** is added per unit of the pricing basis (guest, bed or room) to the base. A drop that empties the base takes the rest from the extras, then the supplement.
- The plan's **own long-stay discounts** apply after the derivation. The parent's discounts are ignored.
- The Discounts tab of a derived plan with no rules of its own shows "Copy discounts from {parent}" beside "Add long-stay discount" in the empty state, when the parent has rules. It saves a copy at once (`CopyParentStayDiscounts`). The copy is not linked, so later parent changes do not reach it. The button is hidden once the plan has a rule.
- Room types, restrictions, name and early departure policy belong to the derived plan.

## Derived packages

A Package plan can follow a Package parent, for example "Dive Package − Petronas 10%". A Package plan has no nightly rates, so the rules differ from nightly plans in these ways.

| Topic                   | Rule                                                                                                                                                                                                                                              |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Parent                  | A Package plan with the same `package_unit` and the same `fixed_nights`. A nightly plan cannot follow a package, and a package cannot follow a nightly plan.                                                                                      |
| Derivation              | Percent only. A package price is per stay, and an Amount is added per night. Amount for packages is future work.                                                                                                                                  |
| Price                   | The child uses the parent's `package_prices` for the arrival date, the parent's child prices and the parent's extra guest amounts. Then the percent adjusts each night after the stay is split.                                                   |
| Nights and charged unit | Copied from the parent. The child cannot edit them. The parent cannot change its nights while an active plan follows it: "Dive Package − Petronas follows this plan, so its nights cannot change." The charged unit never changes after creation. |
| Own to the child        | Name, room types, arrival weekdays and inclusions. A new derived package allows any arrival day and has no inclusions.                                                                                                                            |
| Not applicable          | Long-stay discounts and own prices. The Discounts tab stays closed to packages, and the Pricing tab shows no price form.                                                                                                                          |
| Quote                   | `RateQuoteService` already prices from the parent plan. The parent's nights check and package price lookup apply. A missing period is refused as `package_price_missing`.                                                                         |

The database needs no change. `rate_plans_package_check` and `rate_plans_derivation_complete_check` allow a plan that is both a package and a follower.

## Rules

- A derived plan has no own `rates.amount`. It follows the parent's price per date, so parent weekend and holiday prices carry through. It can still have its own restriction rows.
- Derivation chains are limited to one level.
- A derived plan can link only room types that its parent is linked to.
- A parent cannot be archived while active plans follow it (see [Rate plans](01-rate-plans.md#guards)).
- A room type cannot be unlinked from a parent while an active follower sells it: "Breakfast follows this plan for Deluxe."
- A follower cannot be restored while its parent is archived.
- A package parent can have followers only when they are packages of the same unit and nights. Nightly plans and packages never mix.
- Companies can be linked to derived plans (see [Companies](../15-city-ledger/01-companies.md)).

## Eligible parent

A plan can be a parent when all of these hold:

| Condition                                             | Why                                                          |
| ----------------------------------------------------- | ------------------------------------------------------------ |
| It is a different plan                                | A plan cannot follow itself                                  |
| It is active                                          | Archived plans have no future prices                         |
| It does not follow another plan                       | One level only                                               |
| It is not a Package plan, or the child is a package   | A nightly plan has no nightly price to follow on a package   |
| For a package: same `package_unit` and `fixed_nights` | The child uses the parent's package prices and stay length   |
| It has the same pricing basis                         | Per room, per guest and per bed prices mean different things |
| It is linked to every room type the child plan sells  | Otherwise some rows have no price                            |

## UI

On the Pricing tab, "Follow another plan" asks for the parent, the direction (Down or Up), the value and the unit (% or RM). A preview shows today's price per room type, for example "Deluxe 800 → 850". For a package, the Pricing tab offers the same choice with Percent only. The preview shows the package price for the period that holds today's date, or "No package price for today".

The option is disabled, with the reason in a tooltip, in these cases:

| Case                        | Tooltip                                                                 |
| --------------------------- | ----------------------------------------------------------------------- |
| This is the only plan       | "Add another plan first. This is your only rate plan."                  |
| No plan is eligible         | "No other plan sells Deluxe and Spa Villa at a nightly price."          |
| Other plans follow this one | "Breakfast and Corporate follow this plan, so it needs its own prices." |

## Create derived plan quick action

A shortcut for "give Petronas 15% off". It is on the row menu of the Rate plans list and shows only for an active plan that is an eligible parent (see above), a Package plan included. The Sheet of a package parent shows its Nights and Charged read-only and copies them to the new plan. It opens a Sheet with the parent fixed.

| Field                  | Rule                                                                                                                    |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Name                   | Required. Unique among active plans. Prefilled as "{Parent} – ".                                                        |
| Direction, value, unit | Down or Up, a number, % or RM. The same preview as the Pricing tab: "Deluxe 800 → 680". A package parent allows % only. |
| Room types             | All of the parent's room types start ticked. Staff can untick. At least one is needed.                                  |
| Companies              | Optional multi-select of the property's Active companies. Shown only to staff with `company.manage`.                    |

- **Action:** `CreateDerivedRatePlan` runs in one transaction. It creates the plan through `CreateRatePlan`, sets the derivation through `SaveRatePlanDerivation`, then syncs `company_rate_plans` for the chosen companies. It adds no new rule. Every check is the one the plan editor and the Rates tab already use.
- **Route:** `settings.rate-plans.derived.store`, with `settings.manage`. Choosing companies also needs `company.manage`.
- **Result:** back to the list with a success toast. The new plan shows in the list, and its row menu opens it.
- Companies can still be linked or unlinked later on the company's Rates tab.

## Built

- **Enum and columns:** `RateDerivation` (Percent, Amount) and the three `rate_plans` columns. The database checks that the three are all set or all empty, that a plan is not its own parent, and that a percent is above −100.
- **Services:** `DerivedRateResolver`, `DerivedRatePlans` (eligible parents, reasons, followers, guard messages) and `DerivedRatePreview`.
- **Action:** `SaveRatePlanDerivation` sets, changes or removes the link (a null parent removes it). Following clears the plan's own `rates.amount` and occupancy prices and keeps the restriction columns. Stopping leaves the plan with no prices until staff set them. `SaveRates` refuses an amount for a derived plan, and `ExtendRates` refuses the plan.
- **Routes:** `settings.rate-plans.pricing.derivation.update` and `.preview` (preview saves nothing).
- **UI:** a "Set prices directly / Follow another plan" choice on the Pricing tab. The form has the parent, Down or Up, the value and the unit. The preview shows today's room price per room type. A plan that follows another shows "Stop following" instead of the price form. The list shows "Follows Basic Rate -10%".
- **Rate calendar:** a derived plan row shows the parent's room price after the derivation, with a link marker, read-only. Its own restriction rows show too (see [the calendar](02-daily-rates-and-restrictions.md#the-rate-calendar)).
- **Company links:** `company_rate_plans`, set on the company's Rates tab (see [Companies](../15-city-ledger/01-companies.md#rules)) or in the Create derived plan Sheet. The rate plan editor has no link screen.

## Future

Promo codes: a `promotions` table (code, booking window, stay window, minimum nights) that points to a derived rate plan.

## Lessons from wastays

- Replaces the walk-in, corporate and OTA types of `hotel_pricing_rules`.
- "Adjust Standard Rate" adjusted a fixed `room_types.base_price`, so weekend and date prices on the standard plan did not carry through.
