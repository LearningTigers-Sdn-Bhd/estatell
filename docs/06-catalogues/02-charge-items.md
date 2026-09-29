# Charge items

## Purpose

The things staff can post to a folio: extra bed, breakfast, laundry, parking, boat transfer.

## Data model

```
charge_items    id, property_id, transaction_code_id, name, description,
                default_amount, charging_unit (ChargingUnit), allow_override,
                position, active, timestamps
```

The property currency applies to `default_amount`. Several charge items may use the same transaction code. For example, breakfast and dinner may both use the F&B code.

## Enums

`ChargingUnit`: PerItem, PerNight, PerStay, PerGuestNight, PerGuest.

## Lifecycle

- New properties start with no charge items. The property creates the products and services it sells.
- Names are unique within a property, ignoring case. Deactivating an item does not free its name.
- Deactivation removes an item from new work without deleting historical configuration. Reactivation puts it at the end of the active list.
- The transaction code must be an active charge code from the same property when selected. An item may keep its existing code if that code is later deactivated.
- `default_amount` is positive. `allow_override` controls whether staff may change it while posting.
- Active items have an explicit display order.

## Quantity resolution

`ChargeQuantityResolver` is deferred until reservations and folios establish the canonical stay dates, guest counts and item quantities. It will turn a charging unit and stay context into a quantity. For example, PerGuestNight with two guests and three nights produces six.

## Rules

- Charge items are also used as package inclusions and add-ons (see [Inclusions](../09-packages/01-inclusions.md), [Add-ons](../12-reservations/04-add-ons.md)).

## Lessons from wastays

Replaces `hotel_extra_charges`. Charge items do not create or duplicate accounting codes; they refer to the normalized transaction-code catalogue.
