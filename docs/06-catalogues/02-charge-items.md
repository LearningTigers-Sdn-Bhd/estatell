# Charge items

## Purpose

The things staff can post to a folio: extra bed, breakfast, laundry, parking, boat transfer.

## Data model

```
charge_items    id, property_id, transaction_code_id (unique), name, description,
                default_amount (nullable), charging_unit (ChargingUnit), allow_override,
                auto_add (default false), needs_child_price_review (default false),
                position, active, timestamps
                CHECK auto_add needs default_amount
charge_item_child_prices
                id, charge_item_id, property_age_group_id,
                pricing (AgeBandPricing), value (nullable), timestamps
                unique (charge_item_id, property_age_group_id)
```

Each charge item **owns one charge transaction code**. The code gives the item its visible code, its report category and its taxes. The property currency applies to `default_amount`.

## Enums

- `ChargingUnit`: PerItem, PerNight, PerStay, PerGuestNight, PerGuest.
- Item categories come from `TransactionCategory::chargeItemCases()`: FoodAndBeverage, Laundry, Minibar, Fee, Transport, Other. Transport marks a boat fare (see [Boat settings](../24-boats/01-boat-settings.md#fares-tab)).

## Starter items

`SeedChargeItems` runs inside `SeedPropertyDefaults`. Every Malaysian property receives four active items. Each has no default amount and owns an existing system code.

| Item              | Code    | Category        |
| ----------------- | ------- | --------------- |
| Food and beverage | FNB     | FoodAndBeverage |
| Laundry           | LAUNDRY | Laundry         |
| Minibar           | MINIBAR | Minibar         |
| General fee       | FEE     | Fee             |

- Seeding again restores missing items and keeps property changes.
- Staff can rename, price, reorder and deactivate starter items. The code and category of a starter item do not change.

## Lifecycle

- Creating an item creates its charge code in the same transaction. The code is not a system code.
- Codes are upper case with `_` separators and at most 20 characters. They are unique among all transaction codes of the property.
- Renaming an item renames its code. Deactivating an item deactivates its code. Reactivation puts the item at the end of the active list and reactivates the code.
- Names are unique within a property, ignoring case. Deactivating an item does not free its name.
- Active items have an explicit display order.

## Pricing

- `default_amount` is positive, or null for **Enter when charging**.
- `allow_override` controls whether staff may change the default amount while posting. An item without a default amount always allows it (database CHECK).

## Child prices

A PerGuest or PerGuestNight item can charge children less than adults, by [age group](../02-property/04-age-groups.md), the same way a rate plan's Children tab does (`AgeBandPricing`):

| Choice  | Meaning                                                     |
| ------- | ----------------------------------------------------------- |
| Free    | Not charged. Children in this group are not counted.        |
| Percent | A percentage of the default amount (100 = same as an adult) |
| Fixed   | Its own price                                               |

- `charge_item_child_prices` has one row per age group, for PerGuest and PerGuestNight items only. Other charging units have none, and changing an item to another charging unit deletes its rows.
- **Default: Percent 100 for every group**, so an item charges children as adults until staff change it. A back-fill migration writes these rows for existing per-guest items.
- **Children only** (a kids' club): default amount 0 is not allowed (it must be positive), so the item uses **Enter when charging** or a small adult price with Fixed child prices. **Adults only** (a bar package): every group Free.
- An item with **Enter when charging** has no default to take a percentage of: Percent is disabled, and only Free and Fixed are offered.
- A new age group gets Percent 100 on every per-guest item and sets `needs_child_price_review` (the "Check child prices" badge), like rate plans. Saving the item clears it. A removed group's rows go with it.
- How the rows split by price is in [Add-ons](../12-reservations/04-add-ons.md#rows-by-charging-unit).

## Add to every new booking

`auto_add` puts the item on every new room as an add-on, so staff do not have to remember a resort fee or a cleaning fee.

- It needs a default amount (database CHECK). With **Enter when charging** the switch is disabled: "Set a fixed price first."
- Turning it on or off never changes existing bookings.
- On a fare that a boat departure uses, the switch is disabled with "Added by boat trips", because the boat trip adds it (see [Boat trips](../24-boats/02-boat-trips.md#fare)).
- Where it applies is in [Add-ons](../12-reservations/04-add-ons.md#added-automatically).

## Taxes

The item form selects the taxes that apply to the item. It writes the same `tax_transaction_code` pivot as the tax form (see [Taxes](03-taxes.md)).

- Disabled taxes can be selected and show as inactive.
- Room nights are not charge items. Their taxes are set on [Room revenue](06-room-revenue.md).

## Pages

Charge items use a list, a short **New charge item** Dialog and a full edit page. The edit page follows the rate plan editor's shell (title and breadcrumb, panel width, sticky Cancel and Save footer, `useUnsavedChanges`), but it is **one page with sections**, not tabs: a charge item is one small decision saved once, and its Children section depends on the Pricing section on the same page.

| Page or overlay | Route                                                                               | Content                                                                                                     |
| --------------- | ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| List            | `GET /p/{code}/settings/charge-items` (`settings.charge-items.index`)               | As today, with the badges **Auto** and **Check child prices**. Reorder, deactivate and reactivate stay here |
| New item        | Dialog, `POST …/charge-items` (`.store`)                                            | Name, Code, Category                                                                                        |
| Edit page       | `GET …/charge-items/{item}/edit` (`.edit`), `PUT …/charge-items/{item}` (`.update`) | The five sections below                                                                                     |

**New charge item Dialog.** Three fields: **Name**, **Code** (upper case as typed, suggested from the name) and **Category**. **Create** makes the item with the defaults below and goes to its edit page, with a toast "Breakfast created. Set its price and taxes." The Dialog replaces the add Sheet.

| Default on create        | Value                                                                        |
| ------------------------ | ---------------------------------------------------------------------------- |
| Price                    | **Enter when charging** (`default_amount` null, so `allow_override` is true) |
| Charged                  | Per item                                                                     |
| Taxes                    | None                                                                         |
| Add to every new booking | Off                                                                          |
| Position                 | The end of the active list                                                   |

**Edit page sections**, one column, max 720 px:

```
Settings › Charge items › Breakfast                          [Cancel] [Save]
Details        Name, Code, Category, Description
Pricing        Charged (charging unit), Price: Fixed amount or Enter when charging,
               Allow other amount when charging
Children       One row per age group: Free, % or Fixed (per guest items only)
New bookings   Add to every new booking
Taxes          The taxes multiselect
```

- **Children** shows only when Charged is Per guest or Per guest per night. It reuses the rate plan Children control, so both look and behave the same.
- **New bookings** disables its switch with "Set a fixed price first" while the price is Enter when charging.
- **Taxes**: an item with no taxes shows a muted note "No taxes are added to this item."
- A starter item keeps its code and category (read only), as today.
- Save writes everything in one transaction (`UpdateChargeItem`, which also syncs the child prices) and returns to the list.

## Quantity resolution

`ChargeQuantityResolver` is built with [Add-ons](../12-reservations/04-add-ons.md#rows-by-charging-unit). It turns a charging unit and a unit's stay into add-on rows: nightly units give one row per night, so PerGuestNight with two guests and three nights gives three rows of quantity two.

## Rules

- Charge items are also used as package inclusions and add-ons (see [Inclusions](../09-packages/01-inclusions.md), [Add-ons](../12-reservations/04-add-ons.md)).
- Payment-method surcharges post through a charge item (see [Payment methods](04-payment-methods.md)).

## Lessons from wastays

Replaces `hotel_extra_charges`. As in wastays, each item owns a unique transaction code, so taxes, reports and discounts can target one item. A shared code would force items such as parking and boat transfers to share taxes and report lines. Percentage pricing is not carried over; a percentage service charge is configured as a tax.
