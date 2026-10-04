# estatell overview

## Description

estatell is a property management system (PMS) for small and budget accommodation in Malaysia and Southeast Asia: hotels, hostels, resorts, serviced apartments and homestays.

It covers the PMS core:

- Property setup and a guided onboarding wizard.
- Staff, roles and permissions per property.
- Room types, rooms, beds and room groups.
- Rate plans, daily rates, packages, derived rates and discounts.
- Reservations for single rooms, groups and dorm beds.
- Guest CRM and registration cards.
- Folios, payments, taxes and city ledger (accounts receivable).
- Front desk, housekeeping status and night audit.
- An optional channel manager (Channex) behind a feature flag.

## Background

estatell is a rebuild of the PMS core of **wastays**, a Rails 8 platform. wastays grew into a full hospitality platform:

| wastays metric  | Value |
| --------------- | ----- |
| Database tables | 160   |
| jsonb columns   | 95    |
| Service files   | 841   |
| Controllers     | 287   |
| Spec files      | 1,466 |

wastays also includes an AI concierge, e-invoicing, a corporate portal, payouts, OTA financials, boats and attractions. Most properties only need the PMS core. The size of wastays made simple changes slow and risky.

estatell keeps the proven PMS ideas from wastays and removes the rest. The goals are:

1. **Cut to the PMS core.** Remove modules that are not needed to run a property day to day.
2. **Normalize the database.** One fact lives in one place. Use foreign keys, not copied strings.
3. **Minimize jsonb.** Use real columns and tables for anything that is queried, validated or reported.
4. **Simplify forms.** Ask only what is needed at each step. Reuse one form component in onboarding and in settings.
5. **Modularize.** Group code by domain module with clear boundaries.
6. **Avoid god objects.** Small models, one Action per verb, strategy classes instead of mode branches.

Target: about 81 tables including framework and package tables, down from 160.

## Why this tech stack

| Choice                                           | Reason                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Laravel 13**                                   | Native backed enums (casts, `Rule::enum()`, route binding, `$request->enum()`), invokable classes for Actions (`make:class --invokable`), container attributes (`#[Bind]`, `#[Singleton]`, `#[Scoped]`, `#[CurrentUser]`), job batching, unique jobs, `WithoutOverlapping`, the scheduler and first-party packages (Fortify, Pennant, Boost). Most PMS needs are covered without extra packages. |
| **Inertia 3 + React 19**                         | A single-page app without a separate API. Server routing, validation and authorization stay in Laravel. Inertia 3 adds polling, layout props and optimistic updates.                                                                                                                                                                                                                             |
| **TypeScript + Wayfinder**                       | Typed route functions generated from Laravel routes. No hardcoded URLs in the frontend.                                                                                                                                                                                                                                                                                                          |
| **spatie/laravel-data + typescript-transformer** | One typed data object per payload. TypeScript types for props and enums are generated, so the frontend and backend cannot drift.                                                                                                                                                                                                                                                                 |
| **Tailwind 4 + shadcn/ui (Nova style, Base UI)** | Accessible, composable UI components that live in the repository and can be changed freely. Nova is compact, suited to dense PMS screens.                                                                                                                                                                                                                                                        |
| **PostgreSQL**                                   | CHECK constraints, partial unique indexes and exclusion constraints protect data integrity at the database level.                                                                                                                                                                                                                                                                                |
| **Redis + Horizon**                              | Queue, cache and sessions. Horizon runs and monitors the queue workers (channel sync, emails, night audit jobs).                                                                                                                                                                                                                                                                                 |
| **Pest + Larastan + Pint**                       | Fast readable tests, including architecture tests for module boundaries; static analysis; one code style.                                                                                                                                                                                                                                                                                        |
| **Laravel Pennant**                              | Per-property feature flags, for example to enable the channel manager.                                                                                                                                                                                                                                                                                                                           |
| **spatie/laravel-permission (teams mode)**       | Roles and permissions scoped to each property, with custom roles per property.                                                                                                                                                                                                                                                                                                                   |

See [Dependencies](#approved-dependencies) for the full list.

UI decisions (shadcn Nova on Base UI, preset `b3ZzDdiJy4`, component folders, form containers, data loading) are in [design-system.md](design-system.md). The short contract is [DESIGN.md](../DESIGN.md).

URL shape, route groups and middleware are in [routing.md](routing.md). Staff URLs include the property code: `app.example.com/p/10142/...`.

## Architecture rules

- **Actions write.** One verb per invokable class, for example `CreateReservation`, `PostCharge`, `GoLive`.
- **Services read and calculate.** Stateless classes, for example `AvailabilityService`, `TaxCalculator`, `FolioBalance`.
- **Enums hold rules.** Status, labels, allowed transitions and defaults live on backed enums.
- **Strategies replace mode branches.** For example `PerRoomPricing`, `PerGuestPricing`, `PerBedPricing`, `PackagePricing`.
- **Models hold relations, casts and scopes only.** No business logic in models.
- **Controllers stay thin.** Form Request for validation, one Action call, one Inertia response.
- **Modules talk through Actions, Services and events.** No Eloquent reach-through across modules. Pest `arch()` tests enforce this.
- **Every query is scoped by `property_id`.**
- **The ledger is immutable.** Folio lines and AR entries are never edited or deleted. Corrections are reversal lines.
- **Postings are idempotent.** Every automatic posting has a unique idempotency key.
- **Closed business dates are immutable.**

## What to avoid

These are lessons from wastays. Each one caused real maintenance cost.

### Database

- **Copied data.** wastays stores guest identity in `guests`, in 14 `bookings.guest_*` columns and in 14 `booking_guests.*_snapshot` columns. Store it once and reference it.
- **Copied labels.** wastays copies `room_number` strings into 4 tables next to `room_id`. Use the foreign key only.
- **jsonb for structured data.** wastays has jsonb for occupancy prices, available room numbers, registration card fields, notification settings and night audit results. Use tables and columns.
- **Expression indexes on jsonb keys.** wastays indexes 5 `metadata->>` keys on `folio_transactions` for idempotency. Use real columns.
- **Stored derived amounts.** wastays stores `paid_amount` and `outstanding_amount` on AR invoices and a separate financial summary. Calculate from the ledger.
- **Local copies of external state.** wastays keeps `channel_room_rates` as a copy of rates sent to Channex. Calculate values when sending.
- **Wide tables.** `bookings` has 101 columns and `hotels` has 74. Split into focused tables.
- **Year + number + reference triplets repeated per document type.** Use one `document_sequences` table.
- **Currency on every row.** A property has one currency.
- **Free-string statuses.** Use backed enums.
- **Storing third-party passwords.** wastays stores OTA extranet usernames and passwords. Never do this.
- **Base64 files in text columns.** Store signatures and scans as media files.

### Code

- **God models.** `Booking` (926 lines, about 45 associations) and `Hotel` (910 lines, 92 associations).
- **God controllers.** `ReportsController` (1,138 lines), `OnboardingController` (761 lines).
- **God services.** `ChannexAdapter` (1,464 lines), `TransitionStatus` (551 lines), `CreateManualBooking` (439 lines, mixing guest matching, rate override, prepayment and audit).
- **Mode branches.** `if sell_mode == "per_person"` spread across pricing code.
- **Repair services.** `repair_completed_nightly_charges` and `resolve_missing_nightly_charges` exist because posting was not idempotent. Design postings so that repair is never needed.
- **Parallel legacy paths behind environment flags.** For example `BookingRedesign`.
- **Separate forms for onboarding and settings.** Use one form component with a different layout.
- **Large frontend controllers.** Stimulus controllers of 1,044 and 761 lines.

### Product

- **Accounting before rooms in onboarding.** Ask in the owner's order: property, rooms, prices, payments, check-in.
- **Blocking checks that need a person at 2 AM.** Resolve by policy and fix problems during the day.
- **Strict step chains with invalidation.** Calculate progress from data instead.
- **Review and training modes that need resets.** Allow practice data before go-live and clear it at go-live.

## jsonb allowlist

jsonb is allowed only for:

- Raw payment gateway payloads.
- Raw channel manager booking revisions.
- Audit diffs (activity log).
- Frozen snapshots on issued documents (registration cards, invoices).
- The framework `notifications.data` column.

## Approved dependencies

Installed versions. All are in `composer.json` and `package.json`.

Composer:

| Package                                 | Version | Setup                                                                                                                            |
| --------------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `laravel/pennant`                       | 1.26    | Database store (`features` table)                                                                                                |
| `laravel/horizon`                       | 5.50    | See [Deployment](#deployment)                                                                                                    |
| `spatie/laravel-permission`             | 8.3     | Teams mode: `teams => true`, `team_foreign_key => property_id` in `config/permission.php`                                        |
| `spatie/laravel-data`                   | 4.23    | Default config                                                                                                                   |
| `spatie/laravel-typescript-transformer` | 3.3     | `TypeScriptTransformerServiceProvider` writes `resources/js/types/generated.d.ts`                                                |
| `spatie/laravel-ciphersweet`            | 1.9     | `CIPHERSWEET_KEY` env, `blind_indexes` table                                                                                     |
| `barryvdh/laravel-dompdf`               | 3.1     | None                                                                                                                             |
| `propaganistas/laravel-phone`           | 6.0     | None                                                                                                                             |
| `spatie/period`                         | 2.4     | None                                                                                                                             |
| `brick/money`                           | 0.15    | None                                                                                                                             |
| `spatie/laravel-medialibrary`           | 11.23   | `media` table. Default disk is the private `local` disk. See [Media storage](01-platform/02-platform-settings.md#media-storage). |
| `league/flysystem-aws-s3-v3`            | 3.35    | Needed by the `r2` disk (s3 driver)                                                                                              |
| `spatie/laravel-query-builder`          | 7.3     | None                                                                                                                             |
| `spatie/laravel-activitylog`            | 5.1     | `activity_log` table                                                                                                             |

npm:

| Package                    | Version    | Note                                                                        |
| -------------------------- | ---------- | --------------------------------------------------------------------------- |
| `@tanstack/react-table`    | 9.2        | v9 API: `useTable` + `tableFeatures`. shadcn examples use v8.               |
| `@tanstack/react-virtual`  | 3.14       | `pms/grid` engine                                                           |
| `date-fns`, `@date-fns/tz` | 4.4 / 1.5  | Property time zone                                                          |
| `signature_pad`            | 5.1        | Registration signing                                                        |
| `canvas-confetti`          | 1.9        | Go-live                                                                     |
| `@base-ui/react`           | 1.8        | Base UI primitives for shadcn                                               |
| `cn`, `shadcn`             | 0.4 / 4.21 | `cn` is used by shadcn components. `shadcn` provides `shadcn/tailwind.css`. |
| `react-day-picker`, `cmdk` | 10.0 / 1.1 | Added by shadcn `calendar` and `command`                                    |

TypeScript types are generated with `php artisan typescript:transform`. `composer run setup` and `composer run dev` run it. The generated file is committed.

Not needed: `spatie/laravel-model-states` (native enums), `lorisleiva/laravel-actions` and `internachi/modular` (native tools and namespaces).

## Deployment

Deployed with Coolify (Docker Compose build pack): `compose.yaml` builds the `Dockerfile` (`serversideup/php` FrankenPHP). PostgreSQL and Redis are external. Set env vars in the Coolify UI; give the domain to `web` on port 8080. Do not publish ports.

| Service     | Command                 | Notes                                                                 |
| ----------- | ----------------------- | --------------------------------------------------------------------- |
| `web`       | FrankenPHP on `:8080`   | Runs migrations on start (`AUTORUN_ENABLED=true`).                    |
| `horizon`   | `artisan horizon`       | Queue workers. Dashboard at `/horizon`.                               |
| `scheduler` | `artisan schedule:work` | Runs `horizon:snapshot` every five minutes and future scheduled jobs. |

Environment variables that need care:

| Variable                                                               | Note                                                                                                            |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `CIPHERSWEET_KEY`                                                      | Encrypts ID numbers. Generate once with `php artisan ciphersweet:generate-key --show`. Never change or lose it. |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` | Optional. Without all four, media can only be stored on the server.                                             |

The `media` volume holds `storage/app/private` (media stored on "This server"). It is shared by all three services. Back it up.

Traffic: Cloudflare → Coolify proxy (Traefik) → `web`. `TRUSTED_PROXIES` (see `config/trustedproxy.php`) sets which hops Laravel trusts for the client IP. Production uses `*` because only Traefik can reach the app. `PRIVATE_SUBNETS,CLOUDFLARE` is the tighter option.

Coolify's Traefik does not trust forwarded headers by default, so it replaces `X-Forwarded-For` with the Cloudflare edge IP. In Coolify → Servers → Proxy → Configuration, add to the Traefik `command` list (comma-separated Cloudflare ranges from `config/trustedproxy.php`):

```yaml
- '--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20,...'
- '--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20,...'
```

Coolify keeps these custom commands when it regenerates the proxy config.

## Glossary

One word per idea, in docs, code and UI.

| Term              | Means                                                                                                    | Do not use                                 |
| ----------------- | -------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| Property          | One hotel, hostel, resort or homestay. Every domain row belongs to one                                   | hotel (in code)                            |
| Staff             | A property employee. Always exactly one property                                                         |                                            |
| Company           | An organisation that books or pays later: a corporate, a travel agent or a government body. One property | agency, corporate account, agent (the org) |
| Company type      | `Corporate`, `TravelAgent`, `Government`. A label only                                                   |                                            |
| Company user      | A person who signs in for a company. One login can be linked to companies at several properties          | agent, agency user, portal user (in code)  |
| Company link      | One `company_users` row: company user ↔ company, Pending, Active or Revoked                              |                                            |
| Company portal    | The pages for company users, at `/portal`                                                                | agent portal                               |
| Company reference | The company's own voucher or file number (`reservations.external_reference`)                             | agent reference                            |
| City ledger       | Money owed to the property by companies. UI name; tables keep `ar_*`                                     | receivables (in UI)                        |

"Travel agent" appears only as a company type. "Portal" appears in UI labels and in `portal_*` columns.

## Module map

Modules are numbered in build order. Each folder in `docs/` holds one module. Each file holds one feature.

The build plan, as one commit per item, is in [checklist.md](checklist.md).

| #   | Module                               | Summary                                                                          |
| --- | ------------------------------------ | -------------------------------------------------------------------------------- |
| 01  | [Platform](01-platform/)             | Platform admins, platform settings, feature flags                                |
| 02  | [Property](02-property/)             | Properties, settings, defaults seeding, age groups                               |
| 03  | [Staff](03-staff/)                   | Staff invitations, roles and permissions                                         |
| 04  | [Notifications](04-notifications/)   | Notification types and opt-out                                                   |
| 05  | [Rooms](05-rooms/)                   | Room types, rooms, beds, groups, blocks                                          |
| 06  | [Catalogues](06-catalogues/)         | Transaction codes, charge items, taxes, payment methods, discounts, room revenue |
| 07  | [Meals](07-meals/)                   | Meal periods and entitlements                                                    |
| 08  | [Rates](08-rates/)                   | Rate plans, daily rates, pricing strategies, derived plans, long-stay discounts  |
| 09  | [Packages](09-packages/)             | Inclusions and package prices                                                    |
| 10  | [Availability](10-availability/)     | Availability calculation                                                         |
| 11  | [Guests](11-guests/)                 | Guest CRM, identity documents, privacy                                           |
| 12  | [Reservations](12-reservations/)     | Reservation shape, groups, beds, add-ons, booking form, reservation page         |
| 13  | [Folio](13-folio/)                   | Folios, ledger lines, routing, posting                                           |
| 14  | [Payments](14-payments/)             | Payments and refunds                                                             |
| 15  | [City ledger](15-city-ledger/)       | Companies, invoices, payments received, statements, aging                        |
| 16  | [Registration](16-registration/)     | Registration cards and terms                                                     |
| 17  | [Onboarding](17-onboarding/)         | Account creation, wizard, go-live                                                |
| 18  | [Front desk](18-front-desk/)         | Check-in, check-out, assignment                                                  |
| 19  | [Housekeeping](19-housekeeping/)     | Room status board                                                                |
| 20  | [Night audit](20-night-audit/)       | Business dates, readiness, audit run                                             |
| 21  | [Channels](21-channels/)             | Channex connection, mapping, sync                                                |
| 22  | [Reports](22-reports/)               | Core reports                                                                     |
| 23  | [Company portal](23-company-portal/) | Company users book and view their own stays across linked properties             |
