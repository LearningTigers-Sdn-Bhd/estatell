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

## Enums

`TaxRateType`: Percent, Flat.

## Services

`TaxCalculator`: given a charge line, returns the tax child lines. Handles inclusive and exclusive taxes and `foreign_guests_only` (for example Malaysian tourism tax, using the guest's nationality).

## Rules

- The pivot says which charge codes each tax applies to.
- Tax lines are children of the charge line (`parent_line_id`). Reversing a charge reverses its taxes.
- Country presets are seeded disabled. The owner confirms them in onboarding.

## Lessons from wastays

Tax data was on `hotels` columns and in `hotel_taxes`, linked by a string `primary_tax_key`. One table plus a pivot replaces both.
