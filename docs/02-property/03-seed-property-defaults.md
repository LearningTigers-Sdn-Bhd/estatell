# Seed property defaults

## Purpose

A new property should work immediately. `SeedPropertyDefaults` creates sensible defaults so the owner confirms instead of building from scratch.

## Current first slice

`SavePropertyBasics` saves type, name and address, fixes country to Malaysia, and creates only the property settings row in the same transaction. A repeated Basics save keeps any edited settings.

## Planned full action

`SeedPropertyDefaults(Property $property, PropertyType $type, string $countryCode)` will run once, as soon as the property has a type and a country:

| Path          | When it runs                                                   |
| ------------- | -------------------------------------------------------------- |
| Self sign-up  | The first time the Basics wizard step is saved                 |
| Admin creates | In `CreatePropertyForOwner`, because the admin enters the type |

Self sign-up asks only for the property name, so sign-up stays short.

## Planned defaults

| Area              | Seeded data                                                           | Source                     |
| ----------------- | --------------------------------------------------------------------- | -------------------------- |
| Roles             | Owner, Manager, FrontDesk, Housekeeping, Accounts                     | `DefaultRoleEnum`          |
| Meal periods      | Breakfast, Lunch, Dinner                                              | `DefaultMealPeriod`        |
| Transaction codes | ROOM, NO_SHOW, LATE_CHECKOUT, SST, TOURISM_TAX, CASH, CARD and others | System code list           |
| Taxes             | Country tax presets, disabled until confirmed                         | Country preset             |
| Payment methods   | Cash, Card, Bank transfer, e-wallet                                   | Country preset             |
| Settings          | Currency, time zone, locale, check-in and check-out times             | Country and type           |
| Terms             | Default terms document, version 1, English and Bahasa Malaysia        | `TermsPreset` for the type |

## Rules

- Idempotent: running it twice creates nothing new.
- System transaction codes are marked `is_system` and cannot be deleted.
