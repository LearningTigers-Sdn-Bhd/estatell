# Charge items

## Purpose

The things staff can post to a folio: extra bed, breakfast, laundry, parking, boat transfer.

## Data model

```
charge_items    id, property_id, transaction_code_id, name, description,
                default_amount, charging_unit (ChargingUnit), allow_override,
                position, active, timestamps
```

## Enums

`ChargingUnit`: PerItem, PerNight, PerStay, PerGuestNight, PerGuest.

## Services

`ChargeQuantityResolver`: turns a charging unit and a stay into a quantity. Example: PerGuestNight, 2 guests, 3 nights = 6.

## Rules

- Charge items are also used as package inclusions and add-ons (see [Inclusions](../09-packages/01-inclusions.md), [Add-ons](../12-reservations/04-add-ons.md)).

## Lessons from wastays

Replaces `hotel_extra_charges`.
