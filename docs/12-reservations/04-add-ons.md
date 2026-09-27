# Add-ons

## Purpose

Extras sold with a booking that are not part of the rate: airport transfer, birthday cake, extra bed.

## Data model

```
unit_add_ons    id, reservation_unit_id, charge_item_id, quantity, unit_amount,
                service_date (nullable), timestamps
```

## Rules

- Add-ons with a `service_date` post in the night audit for that date (`addon:{id}` idempotency key).
- Add-ons without a date post at check-in.
- Add-ons are not split out of the rate. Package inclusions are (see [Inclusions](../09-packages/01-inclusions.md)).

## Actions

`AddUnitAddOn`, `RemoveUnitAddOn`.
