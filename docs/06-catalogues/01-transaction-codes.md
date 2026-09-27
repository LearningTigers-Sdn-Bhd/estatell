# Transaction codes

## Purpose

Every money line is classified by a transaction code. Codes drive revenue reports, tax rules and accounting export.

## Data model

```
transaction_codes    id, property_id, code, name, kind (TransactionKind),
                     category (TransactionCategory), gl_account_code (nullable),
                     is_system, active, timestamps
                     unique (property_id, code)
```

## Enums

- `TransactionKind`: Charge, Payment, Adjustment, Tax.
- `TransactionCategory`: Room, FoodAndBeverage, Laundry, Minibar, Fee, Discount, Cash, Card, and others.

## Rules

- System codes are seeded (see [Seed property defaults](../02-property/03-seed-property-defaults.md)) and cannot be deleted.
- Owners rarely see codes. Onboarding never shows them.
- Folio lines copy the code's `description` and `kind` at posting time, so renaming a code does not rewrite history.
