# Routing

URL shape, route groups, names and middleware for estatell. Wayfinder generates frontend route functions (`@/routes/...`) from route names, so names are part of the contract.

## Principles

- **Property code in the URL.** Staff routes live under `/p/{property:code}`, for example `app.example.com/p/10142/front-desk/stay-view`.
- **One app domain.** Staff app, admin and guest links share `app.example.com`, so impersonation works within one session. The marketing site is `example.com`.
- **Names match folders.** The URL prefix, route name prefix, page folder and `pms/{feature}` folder use the same word, for example `front-desk`.
- **Dot names per level.** Resource routes use Laravel's verbs: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`.
- **One route file per module**, required from `routes/web.php`.

## Property code

- `properties.code`: bigint, unique, immutable, issued from a Postgres sequence starting at **10101**. See [Properties](02-property/01-properties.md).
- Easy to say on the phone and to search in admin ⌘K.
- The route group is `Route::prefix('p/{property:code}')->whereNumber('property')->middleware([EnsurePropertyAccess::class, 'verified'])`. Use the class, not the `property.access` alias: `wayfinder:generate` only reads `URL::defaults` from middleware it can resolve, and aliases are not resolved in the console.
- `AppServiceProvider` binds `{property}` by code explicitly (`Route::bind`), because `Route::inertia` routes have no typed parameter for implicit binding. `EnsurePropertyAccess` runs after `SubstituteBindings` (middleware priority).
- `EnsurePropertyAccess` sets `URL::defaults(['property' => $property->code])`, so server code never passes the code by hand. Wayfinder then makes `property` optional in the generated functions. `AppLayout` calls Wayfinder's `setUrlDefaults({ property })` from the shared `property` prop during render, so the sidebar and pages call `dashboard()` without the code. Code that runs outside a render (module-level constants, layout callbacks) passes the code explicitly.
- A code that does not match the user's property returns `404` (never `403`).

## Middleware

| Alias                 | Does                                                                                                                                                                                                                                             | Applies to                         |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------- |
| `property.access`     | `EnsurePropertyAccess`: authenticates staff for the code, allows impersonation, redirects admins to the view-as page, sets the permission team, URL defaults and Pennant scope. See [Impersonation](01-platform/04-impersonation.md#middleware). | All `/p/{code}` routes             |
| `verified`            | Email verified (starter kit)                                                                                                                                                                                                                     | All `/p/{code}` routes             |
| `live`                | `EnsurePropertyIsLive`: redirects to onboarding until the property is Live                                                                                                                                                                       | Operational modules                |
| `block.impersonation` | `BlockDuringImpersonation`                                                                                                                                                                                                                       | Blocked routes (see Impersonation) |
| `features:…`          | Pennant `EnsureFeaturesAreActive`                                                                                                                                                                                                                | Channels                           |
| `can:admin`           | Platform admin gate (`users.is_admin`), used with `auth`                                                                                                                                                                                         | `/admin`                           |
| `admin.2fa`           | `RequireAdminTwoFactor`: admin actions need confirmed 2FA when `ADMIN_REQUIRE_2FA` is true. See [Platform admins](01-platform/01-platform-admins.md#two-factor-for-admins)                                                                       | Locked admin actions               |
| `signed`              | Temporary signed URLs                                                                                                                                                                                                                            | Guest-facing links                 |

## Route groups

| Group              | URL prefix                                                   | Name prefix           | Middleware                                                   | Route file         |
| ------------------ | ------------------------------------------------------------ | --------------------- | ------------------------------------------------------------ | ------------------ |
| Public and auth    | `/`, Fortify routes                                          | `home`, Fortify names | –                                                            | `web.php`          |
| Onboarding         | `/p/{code}/onboarding`                                       | `onboarding.`         | property.access, verified                                    | `onboarding.php`   |
| Dashboard          | `/p/{code}/dashboard`                                        | `dashboard`           | + live (from module 17)                                      | `web.php`          |
| Suspension notice  | `/p/{code}/suspended`                                        | `property.suspended`  | property.access, verified                                    | `web.php`          |
| Front desk         | `/p/{code}/front-desk`                                       | `front-desk.`         | + live                                                       | `front-desk.php`   |
| Reservations       | `/p/{code}/reservations`                                     | `reservations.`       | + live                                                       | `reservations.php` |
| Guests             | `/p/{code}/guests`                                           | `guests.`             | + live                                                       | `guests.php`       |
| Rates              | `/p/{code}/rates`                                            | `rates.`              | property.access (open before go-live)                        | `rates.php`        |
| Finance            | `/p/{code}/finance` (folios, payments, `city-ledger/…`)      | `finance.`            | + live                                                       | `finance.php`      |
| Housekeeping       | `/p/{code}/housekeeping`                                     | `housekeeping.`       | + live                                                       | `housekeeping.php` |
| Night audit        | `/p/{code}/night-audit`                                      | `night-audit.`        | + live                                                       | `night-audit.php`  |
| Reports            | `/p/{code}/reports`                                          | `reports.`            | + live                                                       | `reports.php`      |
| Channels           | `/p/{code}/channels`                                         | `channels.`           | + live, features:ChannelManager                              | `channels.php`     |
| Property settings  | `/p/{code}/settings`                                         | `settings.`           | property.access (open before go-live)                        | `settings.php`     |
| Account (user)     | `/account` (`profile`, `security`, `password`, `appearance`) | `account.`            | auth (+ verified for security, password, appearance, delete) | `account.php`      |
| Impersonation stop | `/impersonation/stop`                                        | `impersonation.stop`  | web session                                                  | `web.php`          |
| Platform admin     | `/admin`                                                     | `admin.`              | auth, can:admin                                              | `admin.php`        |
| Guest-facing       | `/g`                                                         | `guest.`              | signed                                                       | `guest.php`        |
| Webhooks           | `/webhooks`                                                  | `webhooks.`           | no CSRF, provider signature check                            | `webhooks.php`     |

After login, staff are redirected to `/p/{their code}/dashboard` (or onboarding if not live).

- Fortify's `home` is `/dashboard` (`home.redirect`, `HomeRedirectController`). It sends admins to `/admin` and staff to their property dashboard, so register, email verification, password reset and 2FA all land in the right place.
- `live` is not applied yet. It is added with the onboarding wizard (module 17), so new owners are not sent to a page that does not exist.
- `EnsurePropertyAccess` redirects suspended staff to the suspension notice after checking property ownership. The notice redirects to the dashboard when the property is active again. Account routes stay available.
- Shared prop `property` (`PropertySummaryData`: code, name, status): the property in the URL, or the staff user's own property on pages without one (account pages). Null for admins outside `/p`.

## Examples

| URL                                                              | Name                                  | Page                                            |
| ---------------------------------------------------------------- | ------------------------------------- | ----------------------------------------------- |
| `GET /p/10142/front-desk/stay-view`                              | `front-desk.stay-view`                | `pages/front-desk/stay-view.tsx`                |
| `GET /p/10142/front-desk/stay-view/version`                      | `front-desk.stay-view.version`        | – (JSON)                                        |
| `GET /p/10142/reservations/create`                               | `reservations.create`                 | `pages/reservations/create.tsx`                 |
| `POST /p/10142/reservations/{reservation}/units/{unit}/check-in` | `reservations.units.check-in`         | – (Action)                                      |
| `GET /p/10142/finance/folios/{folio}`                            | `finance.folios.show`                 | `pages/finance/folios/show.tsx`                 |
| `GET /p/10142/finance/city-ledger/companies`                     | `finance.city-ledger.companies.index` | `pages/finance/city-ledger/companies/index.tsx` |
| `GET /p/10142/finance/city-ledger/aging`                         | `finance.city-ledger.aging`           | `pages/finance/city-ledger/aging.tsx`           |
| `GET /p/10142/settings/room-types`                               | `settings.room-types.index`           | `pages/settings/room-types/index.tsx`           |
| `GET /p/10142/onboarding/rooms`                                  | `onboarding.rooms`                    | Onboarding step (reuses the settings form)      |
| `GET /admin`                                                     | `admin.dashboard`                     | `pages/admin/dashboard.tsx`                     |
| `GET /admin/settings`                                            | `admin.settings.edit`                 | `pages/admin/settings/edit.tsx`                 |
| `GET /admin/properties`                                          | `admin.properties.index`              | `pages/admin/properties/index.tsx`              |
| `GET /admin/properties/10142`                                    | `admin.properties.show`               | `pages/admin/properties/show.tsx`               |
| `GET /admin/properties/10142/view-as`                            | `admin.properties.view-as`            | `pages/admin/properties/view-as.tsx`            |
| `GET /g/cards/5821?expires=…&signature=…`                        | `guest.cards.show`                    | `pages/guest/cards/show.tsx`                    |
| `POST /webhooks/channex`                                         | `webhooks.channex`                    | –                                               |

Frontend import example:

```ts
import { stayView } from '@/routes/front-desk';
```

## Configuration vs operations (decided)

- **Configuration** lives under `/p/{code}/settings/...` with names `settings.*`: property details, room types, rooms and beds, room groups, rate plans, packages, taxes, charge items, payment methods, discounts, meal periods, terms documents, registration card fields, staff and roles, notification settings.
- **Daily operations** live under module prefixes: front desk, reservations, guests, housekeeping, night audit, finance (folios, payments, city ledger), reports, channels.
- **Exception:** the rate calendar is used daily, so it lives at `/p/{code}/rates/calendar` (`rates.calendar`).
- Onboarding steps render the same settings forms in the wizard layout.

## Account vs settings (decided)

- **Account** is about the signed-in person, has no property code, and lives in the user menu: `/account/profile`, `/account/security`, `/account/appearance` (names `account.profile.edit`, `account.profile.update`, `account.profile.destroy`, `account.security.edit`, `account.password.update`, `account.appearance.edit`). Code: `App\Http\Controllers\Account`, `pages/account/*`, `layouts/account/layout.tsx`, `routes/account.php`.
- **Settings** always means the property, under `/p/{code}/settings`, and appears in the sidebar.

## Sidebar contexts (decided)

The staff sidebar has four contexts, chosen with a switcher at the top. Each context maps to route groups, so navigation follows the routes.

| Context        | Route groups                                                                                                   | Items                                                                                               |
| -------------- | -------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| **Front desk** | `dashboard`, `front-desk.*`, `reservations.*`, `guests.*`, `housekeeping.*`, `rates.calendar`, `night-audit.*` | Dashboard, Stay View, Reservations, Guests, Housekeeping, Rate calendar, Night audit                |
| **Finance**    | `finance.*`                                                                                                    | Folios, Payments, City ledger (Companies, Invoices, Payments received, Statements, Aging)           |
| **Reports**    | `reports.*`                                                                                                    | Daily summary, Revenue, Payments by method, Tax, Kitchen meals, Night audit history, Activity log   |
| **Settings**   | `settings.*`, `channels.*`                                                                                     | Property, Rooms, Rates, Billing setup, Team, Notifications, Channels (feature flag), Support access |

- The active context is derived from the current route name.
- Account is not a context. It lives in the user menu.
- Onboarding, admin and guest pages use their own layouts without contexts.
- Details: [App shell](design-system/09-app-shell.md).

## Later

- **Short guest links**: `example.com/c/K7Q2MX` redirecting to the signed URL, backed by a `short_links` table, when cards are sent by WhatsApp or SMS.
- **Public booking engine**: `example.com/stay/{slug}` or `book.example.com/{slug}`, using a `properties.slug` added at that time.
