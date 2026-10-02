# Charge items

## Purpose

The things staff can post to a folio: extra bed, breakfast, laundry, parking, boat transfer.

## Data model

```
charge_items    id, property_id, transaction_code_id (unique), name, description,
                default_amount (nullable), charging_unit (ChargingUnit), allow_override,
                position, active, timestamps
```

Each charge item **owns one charge transaction code**. The code gives the item its visible code, its report category and its taxes. The property currency applies to `default_amount`.

## Enums

- `ChargingUnit`: PerItem, PerNight, PerStay, PerGuestNight, PerGuest.
- Item categories come from `TransactionCategory::chargeItemCases()`: FoodAndBeverage, Laundry, Minibar, Fee, Other.

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

## Taxes

The item form selects the taxes that apply to the item. It writes the same `tax_transaction_code` pivot as the tax form (see [Taxes](03-taxes.md)).

- Disabled taxes can be selected and show as inactive.
- Room nights are not charge items. Their taxes are set on [Room revenue](06-room-revenue.md).

## Quantity resolution

`ChargeQuantityResolver` is built with [Add-ons](../12-reservations/04-add-ons.md#rows-by-charging-unit). It turns a charging unit and a unit's stay into add-on rows: nightly units give one row per night, so PerGuestNight with two guests and three nights gives three rows of quantity two.

## Rules

- Charge items are also used as package inclusions and add-ons (see [Inclusions](../09-packages/01-inclusions.md), [Add-ons](../12-reservations/04-add-ons.md)).
- Payment-method surcharges post through a charge item (see [Payment methods](04-payment-methods.md)).

## Lessons from wastays

Replaces `hotel_extra_charges`. As in wastays, each item owns a unique transaction code, so taxes, reports and discounts can target one item. A shared code would force items such as parking and boat transfers to share taxes and report lines. Percentage pricing is not carried over; a percentage service charge is configured as a tax.
