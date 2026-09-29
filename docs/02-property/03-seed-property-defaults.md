# Seed property defaults

## Purpose

A new property should work immediately. `SeedPropertyDefaults` creates sensible defaults so the owner confirms instead of building from scratch.

## Built first slice

`SeedPropertyDefaults(Property $property, PropertyType $type, string $countryCode)` creates the default roles, property settings, [transaction codes](../06-catalogues/01-transaction-codes.md) and [taxes](../06-catalogues/03-taxes.md). Malaysia is the only supported country. Every property type currently shares the same Malaysia preset.

`SavePropertyBasics` saves type, name and address, fixes country to Malaysia, and calls `SeedPropertyDefaults` in the same transaction. The action is idempotent. A repeated Basics save keeps edited settings, roles and permissions.

## Call sites

The action runs as soon as the property has a type and a country:

| Path          | When it runs                                                   |
| ------------- | -------------------------------------------------------------- |
| Self sign-up  | The first time the Basics wizard step is saved                 |
| Admin creates | In `CreatePropertyForOwner`, because the admin enters the type |

Self sign-up asks only for the property name, so sign-up stays short.

Roles are the exception during self sign-up: `SeedDefaultRoles` runs when the property is created because the owner needs the Owner role from the first login. It is idempotent, so `SeedPropertyDefaults` can call it again safely.

## Planned defaults

| Area              | Seeded data                                                                                   | Source                         |
| ----------------- | --------------------------------------------------------------------------------------------- | ------------------------------ |
| Roles             | Owner, Manager, FrontDesk, Housekeeping, Finance (already seeded)                             | `DefaultRoleEnum`              |
| Meal periods      | Breakfast, Lunch, Dinner                                                                      | `DefaultMealPeriod`            |
| Transaction codes | The complete Malaysia system-code catalogue (already seeded)                                  | `SystemTransactionCode`        |
| Taxes             | 8% SST and RM10 Tourism tax on room revenue, disabled until confirmed                         | `SeedTaxes`                    |
| Payment methods   | Cash, Card, Bank transfer, e-wallet                                                           | Country preset                 |
| Settings          | Currency, time zone, locale, check-in and check-out times (built), weekend nights FriSat      | Country and type               |
| Rate plan         | "Basic Rate", an ordinary plan with no prices yet. PerRoom, or PerBed for a dorm-only hostel. | Property type                  |
| Age groups        | Infant 0–3, Child 4–11 (adults from 12)                                                       | [Age groups](04-age-groups.md) |
| Terms             | Default terms document, version 1, English and Bahasa Malaysia                                | `TermsPreset` for the type     |

## Rules

- Idempotent: running it twice creates nothing new.
- System transaction codes are marked `is_system`, have stable system keys and have no deletion path. Repeated seeding preserves property changes.
- Tax presets own the SST and Tourism tax posting codes. Repeated seeding preserves tax rates, names, applicability and enabled state.
- "Basic Rate" has no special flag. It can be renamed and archived within the [rate plan guards](../08-rates/01-rate-plans.md#guards). Room types created later are linked to it when the sell unit fits.
