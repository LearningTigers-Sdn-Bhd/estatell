# Taxes

## Purpose

Configure taxes once and apply them to the right charges automatically.

## Data model

```
taxes                   id, property_id, transaction_code_id, name, code,
                        rate_type (TaxRateType), rate, is_inclusive,
                        foreign_guests_only, collect_at_check_in, registration_number, enabled, timestamps
tax_transaction_code    tax_id, transaction_code_id
```

Names and short codes are unique within a property, ignoring case. A custom tax creates and owns an active posting transaction code named `TAX_{SHORT_CODE}`. Disabling the tax does not deactivate that accounting identity.

## Enums

`TaxRateType`: Percent, Flat.

## Services

`TaxCalculator` accepts a property, source charge code, entered amount, quantity and guest nationality. It returns the net charge, ordered tax components and gross amount without depending on folio tables. `calculateWithTaxes()` takes an explicit set of the property's taxes instead of the linked ones, for previews of unsaved selections.

- Flat taxes are `rate × quantity`.
- Inclusive flat taxes are removed first. Inclusive percentage taxes are then extracted together from the remaining gross amount.
- Exclusive taxes use the resulting net amount. Taxes never compound on other taxes.
- Rounding uses the property currency. Any inclusive rounding remainder goes to the last percentage tax in tax-ID order.
- A foreign-only tax refuses calculation when nationality is missing. Inclusive flat taxes refuse calculation when they exceed the entered amount.

## Rules

- The tax form only describes the tax. It does not choose charges.
- The pivot says which charge codes each tax applies to. Two screens edit it: [Room revenue](06-room-revenue.md) for the five room codes, and [Charge items](02-charge-items.md#taxes) for each item. Saving a tax never changes the pivot.
- An enabled tax may apply to no charges yet. The tax list shows an **Applies to** summary ("Room revenue, Breakfast, Parking") and a "Not applied to any charge" badge when it is empty.
- Tax lines are children of the charge line (`parent_line_id`). Reversing a charge reverses its taxes.
- `collect_at_check_in` (default false) marks a tax that staff ask for when a guest checks in. It does not change posting: the tax is still a child line of each night's room line. It decides which taxes the [check-in form](../18-front-desk/01-check-in-and-check-out.md#collect-now) adds up as one amount to collect. The tax form has a **Collect at check-in** switch.
- Malaysia presets are seeded disabled and apply to all five room revenue codes: 8% exclusive Service Tax for all guests and RM10 exclusive Tourism tax per room-night for foreign guests, with **Collect at check-in** on. The owner confirms them in onboarding.
- Properties outside Malaysia get no tax presets. They add their own taxes. "Foreign guests only" compares the guest's nationality with the property's country.

## Lessons from wastays

Tax data was on `hotels` columns and in `hotel_taxes`, linked by a string `primary_tax_key`. One table plus a pivot replaces both.
