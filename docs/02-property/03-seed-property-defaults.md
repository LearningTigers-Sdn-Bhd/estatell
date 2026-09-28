# Seed property defaults

## Purpose

A new property should work immediately. `SeedPropertyDefaults` creates sensible defaults so the owner confirms instead of building from scratch.

## Built first slice

`SeedPropertyDefaults(Property $property, PropertyType $type, string $countryCode)` creates the default roles and property settings. Malaysia is the only supported country. Every property type currently shares the same Malaysia settings preset.

`SavePropertyBasics` saves type, name and address, fixes country to Malaysia, and calls `SeedPropertyDefaults` in the same transaction. The action is idempotent. A repeated Basics save keeps edited settings, roles and permissions.

## Call sites

The action runs as soon as the property has a type and a country:

| Path          | When it runs                                                             |
| ------------- | ------------------------------------------------------------------------ |
| Self sign-up  | The first time the Basics wizard step is saved                           |
| Admin creates | In `CreatePropertyForOwner`, because the admin enters the type (planned) |

Self sign-up asks only for the property name, so sign-up stays short.

Roles are the exception during self sign-up: `SeedDefaultRoles` runs when the property is created because the owner needs the Owner role from the first login. It is idempotent, so `SeedPropertyDefaults` can call it again safely.

## Planned defaults

| Area              | Seeded data                                                           | Source                     |
| ----------------- | --------------------------------------------------------------------- | -------------------------- |
| Roles             | Owner, Manager, FrontDesk, Housekeeping, Finance (already seeded)     | `DefaultRoleEnum`          |
| Meal periods      | Breakfast, Lunch, Dinner                                              | `DefaultMealPeriod`        |
| Transaction codes | ROOM, NO_SHOW, LATE_CHECKOUT, SST, TOURISM_TAX, CASH, CARD and others | System code list           |
| Taxes             | Country tax presets, disabled until confirmed                         | Country preset             |
| Payment methods   | Cash, Card, Bank transfer, e-wallet                                   | Country preset             |
| Settings          | Currency, time zone, locale, check-in and check-out times (built)     | Country and type           |
| Terms             | Default terms document, version 1, English and Bahasa Malaysia        | `TermsPreset` for the type |

## Rules

- Idempotent: running it twice creates nothing new.
- System transaction codes are marked `is_system` and cannot be deleted.
