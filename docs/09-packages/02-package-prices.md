# Package prices

## Purpose

Let staff set "RM600 for 3D2N" as one price per stay, based on arrival date.

## Data model

```
rate_plans                   fixed_nights (1 to 30), package_unit (PackageUnit)
                             both set on a package, both null on every other plan
package_prices               id, rate_plan_id, room_type_id, arrival_from, arrival_to, amount
rate_plan_arrival_weekdays   id, rate_plan_id, weekday (Weekday)
                             unique (rate_plan_id, weekday)
```

There is no `is_package` column. `pricing_basis = Package` already says it. A database CHECK ties `fixed_nights` and `package_unit` to the Package basis.

## Enums

- `PackageUnit`: PerGuest, PerRoom. Set when the plan is created.
- `Weekday`: Monday to Sunday, with `label()`, `shortLabel()`, `isoNumber()` and `options()`.

## Why per stay

Package prices depend on the arrival date. A Friday arrival should cost one price, not Friday rate + Saturday rate.

## Meaning of `amount`

- `PackageUnit::PerGuest`: price per adult per stay. Children use the plan's child prices (the Children tab shows for these plans). A Bed room type counts one guest per bed.
- `PackageUnit::PerRoom`: price per room per stay, plus `extra_adult_amount` and `extra_child_amount` **per stay** for guests above the room type's `base_occupancy`. The Details tab shows both fields for these plans.

## Rules

- Applies to plans with `pricing_basis = Package`. `fixed_nights` is the stay length in nights, from 1 to 30.
- Rows exist only for a plan and room type pair linked in `rate_plan_room_types`. A Package plan can link Room and Bed room types.
- Arrival periods of one room type never overlap. Both ends count, so a period cannot start on the day another ends. The Form Request and the Action both check this. There is no database exclusion constraint.
- Different room types can use the same dates.
- The booking form locks departure = arrival + `fixed_nights`.
- `rate_plan_arrival_weekdays` limits arrival days. No rows means any day. Saving all seven weekdays stores none. At least one weekday must be ticked.
- Stop-sell and closed-to-arrival still come from `rates`.
- `PackagePricing` splits the total across nights (see [Pricing strategies](../08-rates/03-pricing-strategies.md)). A Fixed child price is an amount for the stay.

## Built first slice

Staff with `settings.manage` use the Pricing tab of a package plan (`settings.rate-plans.pricing.edit`, `settings.rate-plans.pricing.package.update`). `SavePackagePricing` in `app/Actions/Rates/` replaces the prices and the weekdays in one transaction. Price rows with an `id` are updated, new rows are added and rows left out are removed.

- **Refused:** an archived plan, a plan that is not a package, a price `id` from another plan, a room type the plan does not sell, and overlapping periods. A save that changes nothing is not logged.
- **Quotes:** `PackagePricing` prices the stay, see [Pricing strategies](../08-rates/03-pricing-strategies.md). The quote is refused when the nights are not `fixed_nights` or no period holds the arrival date.
- **Arrival weekdays:** `RestrictionCheck` reports an arrival on a day that is not allowed. The quote does not check it.
- **Not built yet:** the booking form lock.
- **Not built yet:** the `early_departure_policy` field. It arrives with reservations.
- **Other endpoints:** the nightly pricing form and extend stay closed to Package plans. Derivation is open to a package only as a percent of a package parent with the same unit and nights.

## Derived packages

A package can follow another package of the same charged unit and the same nights, with a percent. The child has no `package_prices` of its own. `RateQuoteService` prices the stay from the parent's periods, child prices and extra guest amounts, then adjusts each night. The child's nights and unit are copied from the parent and locked. The child keeps its own room types, arrival weekdays and inclusions. See [Derived rate plans](../08-rates/04-derived-rate-plans.md#derived-packages).

## Edge cases

| Case                       | Behaviour                                                                                    |
| -------------------------- | -------------------------------------------------------------------------------------------- |
| Guest extends by one night | The extra night uses another rate plan on its `unit_nights.rate_plan_id`                     |
| Guest leaves early         | `early_departure_policy`: ChargeFull or ChargeUsedNights                                     |
| Package price edited       | Existing bookings keep their stored nightly amounts                                          |
| `fixed_nights` edited      | Existing bookings keep their stored nights. Refused while an active package follows the plan |

## Example

Island Escape 3D2N, arrival 1-30 Jun: RM600 per adult. 2 adults + 1 child aged 8 (50%) = RM1,500, stored as RM750 + RM750.

## UI

- **New plan:** choosing Package in the new plan Sheet adds Nights and Charged (per guest or per room).
- **Pricing tab:** a package plan shows an "Arrival days" weekday picker and, for each linked room type, a table of arrival periods and prices with "Add period". It replaces the pricing form on the rate plan's Pricing tab. "Follow another plan" is offered as a percent of a package with the same unit and nights, and then the price grid is replaced by the follow form. The "Arrival days" picker stays, because a derived package keeps its own arrival days. A plan with no linked room types shows an empty state that points to the Room types tab.
- **Details tab:** a package also shows its Nights. A package charged per room shows the extra adult and extra child amounts, per stay.
- **Children tab:** shown only for packages charged per guest.
- **Inclusions tab:** open to every plan, see [Inclusions](01-inclusions.md).
