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
- Country, currency and time zone can change until go-live. See [Changing country](#changing-country).

## Changing country

| Stage                                                    | How                                                           | Confirm                                                           |
| -------------------------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------- |
| Basics saved, every other onboarding section Not started | Edit country, currency or time zone on Basics                 | No. Only untouched presets change.                                |
| Another section started, before go-live                  | Basics shows them read-only with **Change country**           | Yes. An AlertDialog lists what is reset, with server-sent counts. |
| Live                                                     | Not possible. Settings shows them read-only (`SettingsLock`). | –                                                                 |

"Not started" comes from `OnboardingProgress`. There is no extra flag.

**UI on Basics once setup has started:**

1. Country, currency and time zone show read-only with **Change country**.
2. **Change country** unlocks the three fields in place, with a warning Alert: "Changing country resets taxes, payment methods, prices and practice data. Room types, rooms and team stay." **Cancel** restores the saved values and locks them again.
3. **Save & continue** opens an AlertDialog (no fields, destructive button **Change country**) titled "Change country to {country}?". It lists the server-sent counts of what resets. Type, name and address changes save in the same request.

The fields stay on the page because Basics is their form, and an AlertDialog allows at most one field ([Form containers](../design-system/05-form-containers.md#choosing-the-container)).

`ChangePropertyCountry` handles both cases in one transaction: it resets the country-bound data below and runs `SeedPropertyDefaults` with the new profile. Presets are matched by system key, so keys shared by both profiles stay.

| Kept (not country-bound)                               | Reset to the new profile (country- or currency-bound)                             |
| ------------------------------------------------------ | --------------------------------------------------------------------------------- |
| Type, name, address                                    | Currency, time zone, locale                                                       |
| Room types, rooms, room groups                         | Taxes, and the tax links on room revenue and charge items                         |
| Rate plans (the plans themselves)                      | Rates: prices are cleared, so Basic rate becomes Not started                      |
| Roles, staff, invitations                              | Payment methods, charge items, discounts (fixed amounts are in the old currency)  |
| Check-in and check-out times, registration card fields | Security deposit amount, terms (room types lose a link to a reset terms document) |
| Age groups and meal periods the owner edited           | Untouched age groups and meal periods, practice data (`ClearPracticeData`)        |

Guard test: after `ChangePropertyCountry` from Malaysia to another country, no Malaysian preset key, tax link or old-currency amount is left for the property.

## Currencies

The currency list comes from **brick/money** (already a dependency), not from a hand-written enum. `App\Enums\Currency` is removed.

| Part               | Design                                                                                                                                                                                                                                                                                                                             |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| List               | `ISOCurrencyProvider::getAvailableCurrencies()` (current ISO 4217, 214 codes in 0.15.1), filtered by `CurrencyOptions` in `app/Services/Property/`                                                                                                                                                                                 |
| Left out           | More than 2 decimals: BHD, CLF, IQD, JOD, KWD, LYD, OMR, TND, UYW (money columns store 2 decimals). Fund and accounting codes: BOV, CHE, CHW, COU, MXV, USN, XAD.                                                                                                                                                                  |
| Storage            | `property_settings.currency` stays a 3-letter string. `AsCurrency` cast returns a `Brick\Money\Currency`. Validation: `Rule::in(CurrencyOptions::codes())`.                                                                                                                                                                        |
| Decimals           | `getDefaultFractionDigits()`. 0-decimal currencies (JPY, KRW, VND, …) enter and show whole amounts and store `.00`.                                                                                                                                                                                                                |
| To the browser     | `CurrencyData { code, name, decimals }` wherever a page receives the property currency today                                                                                                                                                                                                                                       |
| Browser formatting | `Intl.NumberFormat` with `style: 'currency'` and the currency code. No fixed 2 decimals in components. Inputs use `decimals` for step and rounding.                                                                                                                                                                                |
| Amount in words    | `AmountInWords` keeps ICU spellout. It splits major and minor by `decimals`, not ×100. `CurrencyWords` holds the words name ("Ringgit Malaysia" for MYR, the brick name otherwise) and a short minor-unit map (sen, cents, satang, centavos, …). Others write the minor part as "50/100". 0-decimal currencies have no minor part. |

Not chosen: `kwn/number-to-words`. Its English list has 55 currencies, several defunct, misses THB, SGD and VND, and assumes 100 minor units.

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
