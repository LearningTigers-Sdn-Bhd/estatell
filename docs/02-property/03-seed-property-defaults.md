# Seed property defaults

## Purpose

A new property should work immediately. `SeedPropertyDefaults` creates sensible defaults so the owner confirms instead of building from scratch.

## Built first slice

`SeedPropertyDefaults(Property $property, PropertyType $type, string $countryCode)` creates the default roles, property settings, [transaction codes](../06-catalogues/01-transaction-codes.md), [payment methods](../06-catalogues/04-payment-methods.md) and [taxes](../06-catalogues/03-taxes.md). The presets come from the property's [country profile](#country-profiles). Every property type currently shares the same preset.

`SavePropertyBasics` saves type, name, country, address, currency and time zone, and calls `SeedPropertyDefaults` in the same transaction. The action is idempotent. A repeated Basics save keeps edited settings, roles and permissions.

## Country profiles

A strategy class per country replaces the `$countryCode !== 'MY'` checks in the seeders. `CountryProfiles::for(string $countryCode)` returns `MalaysiaProfile` for `MY` and `GenericProfile` for every other country. The seeders take the profile and never throw for a country.

| Area                                                                 | `MalaysiaProfile`                  | `GenericProfile`                                                                                               |
| -------------------------------------------------------------------- | ---------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Currency                                                             | MYR, fixed                         | Prefilled from the country (ICU, for example `en_TH` → THB). The owner can change it.                          |
| Time zone                                                            | `Asia/Kuala_Lumpur`, fixed         | The country's zones (`DateTimeZone::PER_COUNTRY`). Prefilled when there is one, picked when there are several. |
| Locale                                                               | English, Bahasa Malaysia available | English                                                                                                        |
| State                                                                | Malaysian state list               | Free text                                                                                                      |
| Taxes                                                                | SST and Tourism tax presets        | None                                                                                                           |
| Payment methods                                                      | The six system methods             | Cash, Card and Bank transfer                                                                                   |
| Transaction codes, charge items, discounts, age groups, meal periods | Malaysian presets                  | Neutral presets: same structure, no Malaysia-specific names                                                    |
| Identity document types                                              | MyKad and others                   | Passport and national ID                                                                                       |
| Terms                                                                | English and Bahasa Malaysia        | English                                                                                                        |

- Country options come from `App\Services\Guests\CountryOptions`, with Malaysia first. Basics prefills Malaysia.
- "Foreign guests only" on a tax means a nationality other than the property's country.
- Country, currency and time zone lock after the first Basics save, because the presets are seeded then. Basics shows them read-only with "Wrong country? Contact support". See [Property settings](02-property-settings.md).

## Currencies

`Currency` lists every active ISO 4217 currency from ICU (`ICUDATA-curr`), without historical codes.

| Rule            | Detail                                                                                                                                                                               |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Label           | ICU display name and code, for example "Thai baht (THB)"                                                                                                                             |
| `decimals()`    | ICU fraction digits. 0-decimal currencies (JPY, KRW, VND, …) enter and show whole amounts and store `.00`.                                                                           |
| 3-decimal codes | BHD, IQD, JOD, KWD, LYD, OMR and TND are left out. Money columns store 2 decimals.                                                                                                   |
| Amount in words | `wordsName()` is the ICU name ("Ringgit Malaysia" for MYR). `minorUnitWords()` has a short map (sen, cents, satang, centavos, …). Other currencies write the minor part as "50/100". |

The frontend reads `decimals` from the shared currency prop instead of a fixed 2.

## Call sites

The action runs as soon as the property has a type and a country:

| Path          | When it runs                                                   |
| ------------- | -------------------------------------------------------------- |
| Self sign-up  | The first time the Basics wizard step is saved                 |
| Admin creates | In `CreatePropertyForOwner`, because the admin enters the type |

Self sign-up asks only for the property name, so sign-up stays short.

Roles are the exception during self sign-up: `SeedDefaultRoles` runs when the property is created because the owner needs the Owner role from the first login. It is idempotent, so `SeedPropertyDefaults` can call it again safely.

## Planned defaults

| Area                  | Seeded data                                                                                                                            | Source                                                 |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Roles                 | Owner, Manager, FrontDesk, Housekeeping, Finance (already seeded)                                                                      | `DefaultRoleEnum`                                      |
| Meal periods          | Breakfast, Lunch, Dinner                                                                                                               | `DefaultMealPeriod`                                    |
| Transaction codes     | The complete Malaysia system-code catalogue (already seeded)                                                                           | `SystemTransactionCode`                                |
| Taxes                 | Malaysia: 8% SST and RM10 Tourism tax on room revenue, disabled until confirmed. Other countries: none.                                | `SeedTaxes`                                            |
| Payment methods       | Malaysia: six system methods; Cash, Card, Bank transfer and E-wallet start active. Other countries: Cash, Card, Bank transfer.         | `SeedPaymentMethods`                                   |
| Settings              | Currency, time zone, locale, check-in and check-out times (built), weekend nights FriSat                                               | Country profile and type                               |
| Room revenue policies | Four rows (no-show, cancellation, early departure, late check-out), all Free, with a short default text in English and Bahasa Malaysia | `SeedRoomRevenuePolicies`                              |
| Rate plan             | "Basic Rate", an ordinary plan with no prices yet. PerRoom, linked to room-sold room types.                                            | `SeedRatePlans`                                        |
| Age groups            | Infant 0–3, Child 4–11 (adults from 12)                                                                                                | [Age groups](04-age-groups.md)                         |
| Registration card     | 18 field rows, Full name locked, prices hidden                                                                                         | `SeedRegistrationCardFields`                           |
| Print settings        | One `print_settings` row: Office font style, Medium size, logo, address, contact and registration number on, TIN off, no tax numbers   | [Printing settings](05-printing-settings.md)           |
| Terms                 | Default terms document, version 1, English and Bahasa Malaysia                                                                         | `SeedRegistrationTerms` and `TermsPreset` for the type |

## Rules

- Idempotent: running it twice creates nothing new.
- The `print_settings` row also takes the [Documents tab](05-printing-settings.md#documents-tab) defaults from its column defaults: room and guest on lines on, nightly breakdown off, tax breakdown on, amount in words off, cancellation policy on, signature lines off, no payment details. The migration that adds the columns gives existing rows the same values.
- System transaction codes are marked `is_system`, have stable system keys and have no deletion path. Repeated seeding preserves property changes.
- Tax presets own the SST and Tourism tax posting codes. Repeated seeding preserves tax rates, names, applicability and enabled state.
- Payment-method presets wrap the six payment transaction codes. Repeated seeding preserves names, surcharges, reference requirements, availability and order.
- "Basic Rate" has no special flag. It can be renamed and archived within the [rate plan guards](../08-rates/01-rate-plans.md#guards). Room types created later are linked to it when the sell unit fits.
