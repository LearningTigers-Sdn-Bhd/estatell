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
- Company users (`users.is_company_user`) get `404` on every `/p/{code}` route.

## Middleware

| Alias                 | Does                                                                                                                                                                                                                                             | Applies to                         |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------- |
| `property.access`     | `EnsurePropertyAccess`: authenticates staff for the code, allows impersonation, redirects admins to the view-as page, sets the permission team, URL defaults and Pennant scope. See [Impersonation](01-platform/04-impersonation.md#middleware). | All `/p/{code}` routes             |
| `web` group           | `LogoutDeactivatedUsers`: signs out users deactivated while signed in. See [Staff and invitations](03-staff/01-staff-and-invitations.md)                                                                                                         | Every web request                  |
| `web` group           | `AuthenticateSession` (`auth.session`): ends a session when the user's password changes. See [Sessions](#sessions)                                                                                                                               | Every web request                  |
| `web` group           | `EnsurePasswordChanged`: sends users with `must_change_password` to `/account/set-password`. Exempt: set-password routes and `logout`. See [Temp passwords](03-staff/03-temp-passwords.md)                                                       | Every web request                  |
| `verified`            | Email verified (starter kit)                                                                                                                                                                                                                     | All `/p/{code}` routes             |
| `live`                | `EnsurePropertyIsLive`: redirects to onboarding until the property is Live                                                                                                                                                                       | Operational modules                |
| `block.impersonation` | `BlockDuringImpersonation`                                                                                                                                                                                                                       | Blocked routes (see Impersonation) |
| `features:…`          | Pennant `EnsureFeaturesAreActive`                                                                                                                                                                                                                | Channels                           |
| `can:admin`           | Platform admin gate (`users.is_admin`), used with `auth`                                                                                                                                                                                         | `/admin`                           |
| `admin.2fa`           | `RequireAdminTwoFactor`: admin actions need confirmed 2FA when `ADMIN_REQUIRE_2FA` is true. See [Platform admins](01-platform/01-platform-admins.md#two-factor-for-admins)                                                                       | Locked admin actions               |
| `portal`              | `EnsurePortalAccess`: the user is a company user with an Active link to a company of the property in the URL, the company has `portal_enabled`, and the property is Live. 404 otherwise. Sets URL defaults for `property`.                       | `/portal/{code}`                   |
| `signed`              | Temporary signed URLs                                                                                                                                                                                                                            | Guest-facing links                 |

## Route groups

| Group              | URL prefix                                                                    | Name prefix           | Middleware                                                                               | Route file         |
| ------------------ | ----------------------------------------------------------------------------- | --------------------- | ---------------------------------------------------------------------------------------- | ------------------ |
| Public and auth    | `/`, Fortify routes                                                           | `home`, Fortify names | –                                                                                        | `web.php`          |
| Onboarding         | `/p/{code}/onboarding`                                                        | `onboarding.`         | property.access, verified                                                                | `onboarding.php`   |
| Dashboard          | `/p/{code}/dashboard`                                                         | `dashboard`           | + live (from module 17)                                                                  | `web.php`          |
| Suspension notice  | `/p/{code}/suspended`                                                         | `property.suspended`  | property.access, verified                                                                | `web.php`          |
| Front desk         | `/p/{code}/front-desk`                                                        | `front-desk.`         | + live (from module 17)                                                                  | `front-desk.php`   |
| Reservations       | `/p/{code}/reservations`                                                      | `reservations.`       | + live                                                                                   | `reservations.php` |
| Guests             | `/p/{code}/guests`                                                            | `guests.`             | + live                                                                                   | `guests.php`       |
| Registration card  | `/p/{code}/registration`                                                      | `registration.`       | + live (the guest screen and thank-you screen use the bare guest layout)                 | `registration.php` |
| Rates              | `/p/{code}/rates`                                                             | `rates.`              | property.access (open before go-live)                                                    | `rates.php`        |
| Finance            | `/p/{code}/finance` (folios, payments, `city-ledger/…`)                       | `finance.`            | + live                                                                                   | `finance.php`      |
| Housekeeping       | `/p/{code}/housekeeping`                                                      | `housekeeping.`       | + live                                                                                   | `housekeeping.php` |
| Night audit        | `/p/{code}/night-audit`                                                       | `night-audit.`        | + live                                                                                   | `night-audit.php`  |
| Reports            | `/p/{code}/reports`                                                           | `reports.`            | + live                                                                                   | `reports.php`      |
| Channels           | `/p/{code}/channels`                                                          | `channels.`           | + live, features:ChannelManager                                                          | `channels.php`     |
| Property settings  | `/p/{code}/settings`                                                          | `settings.`           | property.access (open before go-live), then page permission                              | `settings.php`     |
| Account (user)     | `/account` (`profile`, `security`, `password`, `appearance`, `notifications`) | `account.`            | auth (+ verified for security, password, appearance, notifications and profile deletion) | `account.php`      |
| Impersonation stop | `/impersonation/stop`                                                         | `impersonation.stop`  | web session                                                                              | `web.php`          |
| Platform admin     | `/admin`                                                                      | `admin.`              | auth, can:admin                                                                          | `admin.php`        |
| Company portal     | `/portal`, `/portal/{code}`                                                   | `portal.`             | auth, verified (+ portal under `/portal/{code}`)                                         | `portal.php`       |
| Guest-facing       | `/g`                                                                          | `guest.`              | signed                                                                                   | `guest.php`        |
| Webhooks           | `/webhooks`                                                                   | `webhooks.`           | no CSRF, provider signature check                                                        | `webhooks.php`     |

After login, an Owner of a non-live property is redirected to `/p/{their code}/onboarding/basics`. Other staff and live Owners go to the property dashboard.

- Fortify's `home` is `/dashboard` (`home.redirect`, `HomeRedirectController`). It sends admins to `/admin`, company users to `/portal`, non-live Owners to Basics, and other staff to their property dashboard.
- `live` wraps the reservations, front-desk, finance, guests and registration route files. A property in Onboarding is sent to Basics. A property in PendingApproval is sent to the Go live page, which shows a waiting notice. Settings, rates, staff, the dashboard, onboarding and `business-date.show` stay open. The night audit sheet routes add `live` themselves, and `business-date.show` stays open.
- Go live is `GET` and `POST /p/{code}/onboarding/go-live` (`onboarding.go-live.show` and `.store`). Both need `settings.manage`. `store` takes `opening_date` (`Y-m-d`, not after today in the property time zone).
- The first Basics route is `/p/{code}/onboarding/basics` (`onboarding.basics.edit` and `.update`). Settings tabs have their own GET routes: `/p/{code}/settings` (`settings.edit`, General), `/settings/operations`, `/settings/age-groups`, `/settings/meal-periods` and `/settings/support`. Each section saves with `PUT /p/{code}/settings/{section}` (`settings.update`; sections general, operations and support) and redirects to its tab. Age groups saves with `PUT /p/{code}/settings/age-groups` (`settings.age-groups.update`). The registration card has its own pages: `GET /settings/registration-card` (`settings.registration-card.general`, saved by `PUT` to the same URL as `settings.registration-card.update`) and `GET /settings/registration-card/layout` (`settings.registration-card.layout`, saved by `PUT` to the same URL as `settings.registration-card.fields.update`). All four need `settings.manage`. The Terms tab is `GET /settings/registration-card/terms` (`settings.registration-card.terms`, with `?document=` and `?locale=`), with `POST …/terms/documents` (`terms.documents.store`), `DELETE …/terms/documents/{document}` (`terms.documents.destroy`), `POST …/terms/documents/{document}/versions` (`terms.versions.store`) and `POST …/terms/documents/{document}/preset` (`terms.preset.apply`). They need `registration-card.manage-terms`. The Settings page links to Basics until the settings row exists.
- Property notification settings use `GET /p/{code}/settings/notifications` and `PUT /p/{code}/settings/notifications/{type}/{channel}` (`settings.notifications.index` and `.update`). Both require `notification.manage`.
- Personal notification preferences use `GET /account/notifications` and `PUT /account/notifications/{type}/{channel}` (`account.notifications.edit` and `.update`). Both require verified property staff; platform admins receive 404.
- `EnsurePropertyAccess` redirects suspended staff to the suspension notice after checking property ownership. The notice redirects to the dashboard when the property is active again. Account routes stay available.
- Shared prop `property` (`PropertySummaryData`: code, name, status): the property in the URL, or the staff user's own property on pages without one (account pages). Null for admins outside `/p`.

## Examples

| URL                                                              | Name                                  | Page                                            |
| ---------------------------------------------------------------- | ------------------------------------- | ----------------------------------------------- |
| `GET /p/10142/front-desk/stay-view`                              | `front-desk.stay-view`                | `pages/front-desk/stay-view.tsx`                |
| `GET /p/10142/front-desk/stay-view/version`                      | `front-desk.stay-view.version`        | – (JSON)                                        |
| `GET /p/10142/front-desk/room-blocks`                            | `front-desk.room-blocks.index`        | `pages/front-desk/room-blocks/index.tsx`        |
| `POST /p/10142/front-desk/room-blocks/{roomBlock}/end`           | `front-desk.room-blocks.end`          | – (Action)                                      |
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
| `POST /admin/properties`                                         | `admin.properties.store`              | –                                               |
| `GET /admin/properties/10142`                                    | `admin.properties.show`               | `pages/admin/properties/show.tsx`               |
| `POST /admin/properties/10142/owner/setup-link`                  | `admin.properties.owner.setup-link`   | –                                               |
| `GET /admin/properties/10142/view-as`                            | `admin.properties.view-as`            | `pages/admin/properties/view-as.tsx`            |
| `GET /g/cards/5821?expires=…&signature=…`                        | `guest.cards.show`                    | `pages/guest/cards/show.tsx`                    |
| `GET /g/owners/82/setup?expires=…&signature=…`                   | `guest.owner-setup.show`              | `pages/auth/owner-setup.tsx`                    |
| `POST /g/owners/82/setup?expires=…&signature=…`                  | `guest.owner-setup.store`             | –                                               |
| `POST /webhooks/channex`                                         | `webhooks.channex`                    | –                                               |

Frontend import example:

```ts
import { stayView } from '@/routes/front-desk';
```

## Configuration vs operations (decided)

- **Configuration** lives under `/p/{code}/settings/...` with names `settings.*`: property details, room types, rooms and beds, room groups, rate plans, packages, taxes, charge items, payment methods, discounts, meal periods, age groups, terms documents, registration card fields, staff and roles, notification settings.
- **Daily operations** live under module prefixes: front desk, reservations, guests, housekeeping, night audit, finance (folios, payments, city ledger), reports, channels.
- **Exception:** the rate calendar is used daily, so it lives at `/p/{code}/rates/calendar` (`rates.calendar`, with `rates.calendar.bulk` for bulk edit).
- Onboarding steps render the same settings forms in the wizard layout.
- Guest links outside `/p`: staff invitations at `/invitations/{token}` (`invitations.*`, `routes/staff.php`, guest only). See [Staff and invitations](03-staff/01-staff-and-invitations.md).
- Owner setup uses signed guest GET and POST routes under `/g/owners/{user}/setup`. Both are guest-only. The POST is throttled, and the GET supplies the exact signed POST URL because the browser must not reconstruct its signature. Links expire after seven days.
- Each settings page group carries its own `can:` permission middleware (property details: `settings.manage`; staff: `staff.view`, invitations: `staff.manage`), because staff and roles pages under `settings.*` need other permissions. Staff without it get 403.

## Account vs settings (decided)

- **Account** is about the signed-in person, has no property code, and lives in the user menu: `/account/profile`, `/account/security`, `/account/appearance`, `/account/notifications` (names under `account.*`). Code: `App\Http\Controllers\Account`, `pages/account/*`, `layouts/account/layout.tsx`, `routes/account.php`.
- **Settings** always means the property, under `/p/{code}/settings`, and appears in the sidebar.
- Account also has `GET` and `PUT /account/set-password` (`account.set-password.edit` and `.update`, `auth` only) for users with a temp password, and `DELETE /account/sessions` (`account.sessions.destroy`, auth and verified) for **Log out other devices**.

## Sessions

- Sessions live in Redis. `SESSION_LIFETIME` stays 120 minutes. "Remember me" stays (Laravel's 400-day cookie).
- `AuthenticateSession` is in the `web` group. A session (including a remember-me login) ends when the user's password changes: a password change, a reset by email, a temp password reset or setting a new password after a temp one. The session that made the change stays signed in.
- **Log out other devices** on `/account/security` asks for the current password and calls `Auth::logoutOtherDevices`.
- There is no list of signed-in devices. Redis cannot list one user's sessions; that would need the `database` session driver.
- `migrate:fresh` does not clear Redis. Old sessions fail the password hash check and sign out.

## Sidebar contexts (decided)

The staff app has four contexts, chosen with links in the sidebar footer; the other areas open in a new tab. The sidebar lists the active context. Each context maps to route groups, so navigation follows the routes.

| Context        | Route groups                                                                                            | Items                                                                                                       |
| -------------- | ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| **Front desk** | `dashboard`, `front-desk.*`, `reservations.*`, `guests.*`, `housekeeping.*`, `rates.*`, `night-audit.*` | Dashboard, Stay View, Reservations, Guests, Housekeeping, Rate calendar, Night audit                        |
| **Finance**    | `finance.*`                                                                                             | Folios, Payments, City ledger (Companies, Invoices, Payments received, Statements, Aging)                   |
| **Reports**    | `reports.*`                                                                                             | Daily summary, Revenue, Payments by method, Tax, Kitchen meals, Night audit history, Activity log           |
| **Settings**   | `settings.*`, `channels.*`                                                                              | Property, Rooms, Rates, Billing setup, Guests, Team, Notifications, Channels (feature flag), Support access |

- The active context is derived from the current route name.
- Account is not a context. It lives in the user menu.
- Onboarding, admin and guest pages use their own layouts without contexts.
- Details: [App shell](design-system/09-app-shell.md).

## Later

- **Short guest links**: `example.com/c/K7Q2MX` redirecting to the signed URL, backed by a `short_links` table, when cards are sent by WhatsApp or SMS.
- **Public booking engine**: `example.com/stay/{slug}` or `book.example.com/{slug}`, using a `properties.slug` added at that time.
