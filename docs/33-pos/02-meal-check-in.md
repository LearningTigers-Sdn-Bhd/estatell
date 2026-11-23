# Restaurant meal check-in

## Purpose

Give restaurant hosts a fast screen to check off entitled guests arriving for included meals (e.g., Breakfast, Lunch, Dinner), built directly on top of [Meal periods](../07-meals/01-meal-periods.md) and [Meal entitlements](../07-meals/02-meal-entitlements.md).

## Scope

| Now                                                                  | Later                                                              |
| -------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Daily meal check-in list per active `MealPeriod`                     | Extra walk-in / non-entitled meal charge directly from this screen |
| Search by room number, guest name or booking ref                     | QR / room keycard tap check-in at restaurant entrance              |
| Partial and full room redemptions (e.g., 2 of 3 guests arrive first) | Adult vs. child entitlement split                                  |
| Undo redemption (same business date)                                 | Historical meal redemption analytics report                        |

## Feature gate

- Behind `PosIntegration` (`pos-integration`) alongside [Outlets and POS postings](01-outlets-and-postings.md).
- Requires the property to have at least one active `MealPeriod`.

## Data model

```
meal_redemptions        id, property_id, reservation_unit_id, meal_period_id, service_date (date),
                        redeemed_guests (smallint unsigned), redeemed_by_user_id (nullable),
                        redeemed_at (timestamp), undone_at (nullable), undone_by_user_id (nullable),
                        notes (nullable), timestamps
                        index (property_id, service_date, meal_period_id)
                        index (reservation_unit_id, service_date, meal_period_id)
```

- One row per check-in event (a room with 4 entitled guests can have two check-ins of 2 guests each).
- Rows are never hard-deleted; undoing a redemption sets `undone_at` and `undone_by_user_id` for auditability.
- Active redeemed count for `(reservation_unit_id, meal_period_id, service_date)` is `SUM(redeemed_guests) WHERE undone_at IS NULL`.

## Services

### `DailyMealCheckInList` (`App\Services\Pos\`)

`(Property $property, CarbonImmutable $date, MealPeriod $period, ?string $search = null): MealCheckInListData`

- Reuses `MealEntitlementResolver` (same source of truth as `MealCounts` in [02-meal-entitlements.md](../07-meals/02-meal-entitlements.md)) for all `CheckedIn` (and optionally `Confirmed` arriving today) units entitled to `$period` on `$date`.
- Excludes `Cancelled`, `NoShow`, and day-use units.
- Joins active `meal_redemptions` to compute per-room:
    - `room_number` (via `room_id`), `guest_name`, `reservation_reference`
    - `entitled_guests`
    - `redeemed_guests`
    - `remaining_guests` (`max(0, entitled_guests - redeemed_guests)`)
    - `status`: `Pending` (0 redeemed), `Partial` (0 < redeemed < entitled), `Redeemed` (redeemed >= entitled)
- Returns summary KPIs for the header: **Total Entitled**, **Checked In**, **Remaining**.

## Actions

### `RedeemMeal` (`App\Actions\Pos\`)

`(User $actor, ReservationUnit $unit, MealPeriod $period, CarbonImmutable $serviceDate, int $guests, ?string $notes = null): MealRedemptionResult`

| Check, in order                                                                        | Refusal                 |
| -------------------------------------------------------------------------------------- | ----------------------- |
| Unit belongs to property and status is `CheckedIn` (or `CheckedOut` on departure date) | `unit_not_eligible`     |
| `$period` belongs to property                                                          | `meal_period_not_found` |
| `$guests` is between 1 and 99                                                          | `guest_count_invalid`   |
| Unit has an entitlement for `$period` on `$serviceDate` via `MealEntitlementResolver`  | `not_entitled`          |
| `already_redeemed + $guests <= entitled_guests`                                        | `exceeds_entitlement`   |

- Locks the `reservation_unit` row inside a transaction before checking `already_redeemed` so two hosts tapping the same room simultaneously cannot over-redeem.
- Inserts `meal_redemptions` and logs `meals.redeemed`.

### `UndoMealRedemption` (`App\Actions\Pos\`)

`(User $actor, MealRedemption $redemption): MealRedemptionResult`

- Refuses if already undone (`already_undone`) or if `service_date` is before the property's current business date (`service_date_closed`).
- Sets `undone_at = now()`, `undone_by_user_id = $actor->id`, and logs `meals.redemption_undone`.

## UI (`/p/{code}/restaurant/meal-check-in`)

- Defaults to the property's current business date and the currently active `MealPeriod` (by property local time, falling back to the first active period of the day, e.g., Breakfast).
- Top summary bar: **Entitled**, **Checked in**, **Remaining**.
- Fast search bar (instant filter by room number or guest name) + status tabs (`All`, `Pending`, `Partial`, `Redeemed`).
- Compact table (shadcn Nova style): Room, Guest, Entitled, Redeemed, Remaining, and one-click **Check in all** button plus a **Partial** popover (to pick guest count, e.g., 1 of 2) and **Undo** in the row menu.

## Routes & Permissions

| Method & URL                                    | Route name                         | Permission     | Purpose                                     |
| ----------------------------------------------- | ---------------------------------- | -------------- | ------------------------------------------- |
| `GET /restaurant/meal-check-in`                 | `restaurant.meal-check-in.index`   | `meals.redeem` | Daily meal check-in screen                  |
| `POST /restaurant/meal-check-in`                | `restaurant.meal-check-in.store`   | `meals.redeem` | Record meal redemption (`RedeemMeal`)       |
| `DELETE /restaurant/meal-check-in/{redemption}` | `restaurant.meal-check-in.destroy` | `meals.redeem` | Undo meal redemption (`UndoMealRedemption`) |

| Permission     | Default roles              | Gives                                                 |
| -------------- | -------------------------- | ----------------------------------------------------- |
| `meals.redeem` | Owner, Manager, Front Desk | View and check off daily restaurant meal entitlements |
