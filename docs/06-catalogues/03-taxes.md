# Taxes

## Purpose

Configure taxes once and apply them to the right charges automatically.

## Data model

```
taxes                   id, property_id, transaction_code_id, name, code,
                        rate_type (TaxRateType), rate, is_inclusive,
                        foreign_guests_only, registration_number, enabled, timestamps
tax_transaction_code    tax_id, transaction_code_id
```

Names and short codes are unique within a property, ignoring case. A custom tax creates and owns an active posting transaction code named `TAX_{SHORT_CODE}`. Disabling the tax does not deactivate that accounting identity.

## Enums

`TaxRateType`: Percent, Flat.

## Services

`TaxCalculator` accepts a property, source charge code, entered amount, quantity and guest nationality. It returns the net charge, ordered tax components and gross amount without depending on folio tables.

- Flat taxes are `rate × quantity`.
- Inclusive flat taxes are removed first. Inclusive percentage taxes are then extracted together from the remaining gross amount.
- Exclusive taxes use the resulting net amount. Taxes never compound on other taxes.
- Rounding uses the property currency. Any inclusive rounding remainder goes to the last percentage tax in tax-ID order.
- A foreign-only tax refuses calculation when nationality is missing. Inclusive flat taxes refuse calculation when they exceed the entered amount.

## Rules

- The pivot says which charge codes each tax applies to.
- Disabled drafts may have no applicable charge codes. An enabled tax needs at least one active charge code.
- Tax lines are children of the charge line (`parent_line_id`). Reversing a charge reverses its taxes.
- Malaysia presets are seeded disabled and apply to room revenue: 8% exclusive Service Tax for all guests and RM10 exclusive Tourism tax per room-night for foreign guests. The owner confirms them in onboarding.

## Lessons from wastays

Tax data was on `hotels` columns and in `hotel_taxes`, linked by a string `primary_tax_key`. One table plus a pivot replaces both.
