# Routing

URL shape, route groups, names and middleware for estatell. Wayfinder generates frontend route functions (`@/routes/...`) from route names, so names are part of the contract.

## Principles

- **Property code in the URL.** Staff routes live under `/p/{property:code}`, for example `app.example.com/p/ES10142/front-desk/stay-view`.
- **One app domain.** Staff app, admin and guest links share `app.example.com`, so impersonation works within one session. The marketing site is `example.com`. The app is `app.estatell.com`. `/` is the public landing page (`Route::inertia('/', 'landing')`, name `home`), with `/terms-and-conditions` (`terms`) and `/privacy-policy` (`privacy`) beside it. They are guest pages with no layout, no auth and no property scope. Sign-in is `auth`, and signed-in users reach their home through `home.redirect` (`/dashboard`).
- **Names match folders.** The URL prefix, route name prefix, page folder and `pms/{feature}` folder use the same word, for example `front-desk`.
- Error-page previews use `GET /_preview/errors/{status}` (`errors.preview`) for 403, 404, 419, 500 and 503. They are available only when `APP_ENV=local`; other environments return 404.
- **Dot names per level.** Resource routes use Laravel's verbs: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`.
- **One route file per module**, required from `routes/web.php`.

## Property code

- `properties.code`: a 2-letter prefix and a number (`ES10142`), unique and immutable, from one counter per prefix. See [Properties](02-property/01-properties.md#property-code).
- Easy to say on the phone and to search in admin ⌘K.
- The route group is `Route::prefix('p/{property:code}')->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware([EnsurePropertyAccess::class, 'verified'])`. Use the class, not the `property.access` alias: `wayfinder:generate` only reads `URL::defaults` from middleware it can resolve, and aliases are not resolved in the console. Admin routes with `{property}` use the same pattern.
- `AppServiceProvider` binds `{property}` by the upper-cased code explicitly (`Route::bind`), because `Route::inertia` routes have no typed parameter for implicit binding. `EnsurePropertyAccess` runs after `SubstituteBindings` (middleware priority).
- `EnsurePropertyAccess` sets `URL::defaults(['property' => $property->code])`, so server code never passes the code by hand. Wayfinder then makes `property` optional in the generated functions. `AppLayout` calls Wayfinder's `setUrlDefaults({ property })` from the shared `property` prop during render, so the sidebar and pages call `dashboard()` without the code. Code that runs outside a render (module-level constants, layout callbacks) passes the code explicitly.
- A code that does not match the user's property returns `404` (never `403`).
- Company users (`users.is_company_user`) get `404` on every `/p/{code}` route.
- Partners (`users.partner_id`) reach `/p/{code}` only through view as, and only for their own properties (see [Impersonation](01-platform/04-impersonation.md)).

## Middleware

| Alias                 | Does                                                                                                                                                                                                                                                                        | Applies to                            |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| `property.access`     | `EnsurePropertyAccess`: authenticates staff for the code, allows impersonation, redirects admins and the property's partner to the view-as page, sets the permission team, URL defaults and Pennant scope. See [Impersonation](01-platform/04-impersonation.md#middleware). | All `/p/{code}` routes                |
| `web` group           | `LogoutDeactivatedUsers`: signs out deactivated staff and suspended partners while signed in. See [Staff and invitations](03-staff/01-staff-and-invitations.md)                                                                                                             | Every web request                     |
| `web` group           | `AuthenticateSession` (`auth.session`): ends a session when the user's password changes. See [Sessions](#sessions)                                                                                                                                                          | Every web request                     |
| `web` group           | `EnsurePasswordChanged`: sends users with `must_change_password` to `/account/set-password`. Exempt: set-password routes and `logout`. See [Temp passwords](03-staff/03-temp-passwords.md)                                                                                  | Every web request                     |
| `verified`            | Email verified (starter kit)                                                                                                                                                                                                                                                | All `/p/{code}` routes                |
| `live`                | `EnsurePropertyIsLive`: redirects to the current onboarding section until the property is Live. See [The fence](17-onboarding/02-wizard-steps.md#the-fence)                                                                                                                 | Every PMS route file                  |
| `onboarding.only`     | `EnsureOnboarding`: redirects Live properties to the dashboard and staff other than the Owner (and view as) to the waiting page                                                                                                                                             | `/p/{code}/onboarding`                |
| `block.impersonation` | `BlockDuringImpersonation`                                                                                                                                                                                                                                                  | Blocked routes (see Impersonation)    |
| `channel-manager`     | `EnsureChannelManagerIsActive`: 404 when the property's `ChannelManager` flag is off, like `boats`. See [Connections](21-channels/01-connections-and-feature-gate.md#feature-gate)                                                                                          | Channels owner and admin routes       |
| `can:admin`           | Platform admin gate (`users.is_admin`), used with `auth`                                                                                                                                                                                                                    | `/admin`                              |
| `can:partner`         | Partner gate (`users.partner_id` set and the partner Active), used with `auth`                                                                                                                                                                                              | `/partner`                            |
| `support.2fa`         | `RequireSupportTwoFactor`: admin and partner actions need confirmed 2FA only when `SUPPORT_REQUIRE_2FA` is true (default false). See [Platform admins](01-platform/01-platform-admins.md#two-factor-for-admins)                                                             | Admin writes, partner writes, view as |
| `portal`              | `EnsurePortalAccess`: the user is a company user with an Active link at the property in the URL, and the property is Live. 404 otherwise. Sets URL defaults for `property`.                                                                                                 | `/portal/{code}`                      |
| `signed`              | Temporary signed URLs                                                                                                                                                                                                                                                       | Guest-facing links                    |

## Route groups

| Group              | URL prefix                                                                    | Name prefix           | Middleware                                                                               | Route file         |
| ------------------ | ----------------------------------------------------------------------------- | --------------------- | ---------------------------------------------------------------------------------------- | ------------------ |
| Public and auth    | `/` (redirect), Fortify routes                                                | `home`, Fortify names | –                                                                                        | `web.php`          |
| Onboarding         | `/p/{code}/onboarding`                                                        | `onboarding.`         | property.access, verified, onboarding.only                                               | `onboarding.php`   |
| Dashboard          | `/p/{code}/dashboard`                                                         | `dashboard`           | + live                                                                                   | `web.php`          |
| Suspension notice  | `/p/{code}/suspended`                                                         | `property.suspended`  | property.access, verified                                                                | `web.php`          |
| Front desk         | `/p/{code}/front-desk`                                                        | `front-desk.`         | + live                                                                                   | `front-desk.php`   |
| Reservations       | `/p/{code}/reservations`                                                      | `reservations.`       | + live                                                                                   | `reservations.php` |
| Guests             | `/p/{code}/guests`                                                            | `guests.`             | + live                                                                                   | `guests.php`       |
| Registration card  | `/p/{code}/registration`                                                      | `registration.`       | + live (the guest screen and thank-you screen use the bare guest layout)                 | `registration.php` |
| Rates              | `/p/{code}/rates`                                                             | `rates.`              | + live                                                                                   | `rates.php`        |
| Finance            | `/p/{code}/finance` (folios, payments, `city-ledger/…`)                       | `finance.`            | + live                                                                                   | `finance.php`      |
| Housekeeping       | `/p/{code}/housekeeping`                                                      | `housekeeping.`       | + live                                                                                   | `housekeeping.php` |
| Night audit        | `/p/{code}/night-audit`                                                       | `night-audit.`        | + live                                                                                   | `night-audit.php`  |
| Reports            | `/p/{code}/reports`                                                           | `reports.`            | + live                                                                                   | `reports.php`      |
| Channels           | `/p/{code}/channels`                                                          | `channels.`           | + live, channel-manager, can:channel.view                                                | `channels.php`     |
| Property settings  | `/p/{code}/settings`                                                          | `settings.`           | + live, then page permission                                                             | `settings.php`     |
| Account (user)     | `/account` (`profile`, `security`, `password`, `appearance`, `notifications`) | `account.`            | auth (+ verified for security, password, appearance, notifications and profile deletion) | `account.php`      |
| Impersonation stop | `/impersonation/stop`                                                         | `impersonation.stop`  | web session                                                                              | `web.php`          |
| View as            | `/view-as/{code}`                                                             | `view-as.`            | auth, policy `viewAs`, support.2fa on POST                                               | `web.php`          |
| Platform admin     | `/admin`                                                                      | `admin.`              | auth, can:admin                                                                          | `admin.php`        |
| Partner portal     | `/partner`                                                                    | `partner.`            | auth, can:partner                                                                        | `partner.php`      |
| Partner signup     | `/join/{signupCode}`                                                          | `join`                | guest                                                                                    | `web.php`          |
| Company portal     | `/portal`, `/portal/properties`, `/portal/{code}`                             | `portal.`             | auth, verified (+ portal under `/portal/{code}`)                                         | `portal.php`       |
| Guest-facing       | `/g`                                                                          | `guest.`              | signed                                                                                   | `guest.php`        |
| Webhooks           | `/webhooks`                                                                   | `webhooks.`           | no CSRF, provider signature check                                                        | `webhooks.php`     |

After login, an Owner of a non-live property is redirected to `/p/{their code}/onboarding/basics`. Other staff and live Owners go to the property dashboard.

- Fortify's `home` is `/dashboard` (`home.redirect`, `HomeRedirectController`). It sends admins to `/admin`, partners to `/partner`, company users to `/portal`, non-live Owners to Basics, and other staff to their property dashboard.
- `live` wraps every PMS route file, including the dashboard, settings, staff and rates. A property in Onboarding is sent to the current onboarding section. A property in PendingApproval is sent to Review, which shows a waiting notice. Only onboarding and the suspension notice stay outside `live`. The night audit sheet routes add `live` themselves. See [The fence](17-onboarding/02-wizard-steps.md#the-fence).
- `onboarding.only` wraps `onboarding.php`. A Live property is sent to the dashboard. Staff other than the Owner (and view as) see the waiting page.
- Go live is `GET` and `POST /p/{code}/onboarding/go-live` (`onboarding.go-live.show` and `.store`), plus `DELETE /p/{code}/onboarding/go-live/request` (`onboarding.go-live.withdraw`). All need `settings.manage`. `store` takes no date: it runs `GoLive` (today) or `RequestGoLive` when approval is on. Admins review at `/admin/properties/{code}/go-live` (see [Go live](17-onboarding/03-go-live.md#approval)).
- The first Basics route is `/p/{code}/onboarding/basics` (`onboarding.basics.edit` and `.update`). Settings tabs have their own GET routes: `/p/{code}/settings/profile` (`settings.profile`, the first tab, saved by `PUT` to the same URL as `settings.profile.update`), `/p/{code}/settings/business` (`settings.business`, saved by `PUT` to the same URL as `settings.business.update`), `/p/{code}/settings` (`settings.edit`, General), `/settings/operations`, `/settings/age-groups`, `/settings/meal-periods` and `/settings/support`. Each section saves with `PUT /p/{code}/settings/{section}` (`settings.update`; sections general, operations and support) and redirects to its tab. Age groups saves with `PUT /p/{code}/settings/age-groups` (`settings.age-groups.update`). The registration card has its own pages: `GET /settings/registration-card` (`settings.registration-card.general`, saved by `PUT` to the same URL as `settings.registration-card.update`) and `GET /settings/registration-card/layout` (`settings.registration-card.layout`, saved by `PUT` to the same URL as `settings.registration-card.fields.update`). All four need `settings.manage`. The Terms tab is `GET /settings/registration-card/terms` (`settings.registration-card.terms`, with `?document=` and `?locale=`), with `POST …/terms/documents` (`terms.documents.store`), `DELETE …/terms/documents/{document}` (`terms.documents.destroy`), `POST …/terms/documents/{document}/versions` (`terms.versions.store`) and `POST …/terms/documents/{document}/preset` (`terms.preset.apply`). They need `registration-card.manage-terms`. The Settings page links to Basics until the settings row exists.
- Printing settings use `GET`/`PUT /p/{code}/settings/printing` (`settings.printing.general`, `.update`), `GET`/`PUT /settings/printing/header` (`settings.printing.header`, `.header.update`), and `GET /settings/printing/sample` (`settings.printing.sample`). The logo belongs to the Profile tab: `POST`/`DELETE /settings/logo` (`settings.logo.store`, `.destroy`) and `GET /settings/logo` (`settings.logo`, streams the private logo). All need `settings.manage`. See [Printing settings](02-property/05-printing-settings.md).
- Reports: one `GET /p/{code}/reports/{slug}` page per report, plus shared routes that take `{report}` as a `ReportKey`: `PUT`/`DELETE …/{report}/layout` (`reports.layout.update`, `.destroy`) and `POST …/{report}/layout/preview` (`reports.layout.preview`), which also need `settings.manage`, and `POST …/{report}/pdf` (`reports.pdf`) and `POST …/{report}/spreadsheet` (`reports.spreadsheet`), and `GET …/reports/night-audit-history/{date}/packet` (`reports.night-audit-packet`). All need `reports.view`. See [Core reports](22-reports/01-core-reports.md#routes).
- Housekeeping: `GET /p/{code}/housekeeping` (`housekeeping.index`, `housekeeping.view`) with `PUT …/housekeeping/rooms/{room}/status`, `/assignee`, `/priority`, `/dnd`, `/remark` and `/maintenance` (`housekeeping.rooms.*`, `housekeeping.update`; the status route also needs `housekeeping.inspect` to reach Inspected), `POST …/housekeeping/bulk` (`housekeeping.bulk`) and `POST …/housekeeping/pdf` and `/spreadsheet` (`housekeeping.pdf`, `housekeeping.spreadsheet`, `housekeeping.view`). The pen box items are configuration: `GET`/`PUT /p/{code}/settings/housekeeping` (`settings.housekeeping.edit`, `.update`, `housekeeping.manage-checklist`). See [Print, export and layout](19-housekeeping/02-print-and-layout.md).
- OTA lists: `GET` and `PUT /p/{code}/settings/ota-lists` (`settings.ota-lists.edit`, `.update`) need `settings.ota-lists`, which only the Owner role holds by default. The page saves the same table as onboarding through `SaveOtaCredentials`. See [Channel manager table](17-onboarding/02-wizard-steps.md#channel-manager-table).
- Booking sources: `GET /p/{code}/settings/booking-sources` (`settings.booking-sources.index`), `POST` to the same URL (`.store`), `PUT …/booking-sources/order` (`.reorder`), and `PUT` and `DELETE …/booking-sources/{bookingSource}` (`.update`, `.destroy`). All need `settings.manage` and use `scopeBindings`. See [Booking sources](12-reservations/11-booking-sources.md#settings-page).
- Property notification settings use `GET /p/{code}/settings/notifications` and `PUT /p/{code}/settings/notifications/{type}/{channel}` (`settings.notifications.index` and `.update`). Both require `notification.manage`.
- Personal notification preferences use `GET /account/notifications` and `PUT /account/notifications/{type}/{channel}` (`account.notifications.edit` and `.update`). Both require verified property staff; platform admins receive 404.
- Platform settings use separate GET and PUT pairs for `/admin/settings` (General), `/admin/settings/channels` and `/admin/settings/storage`. Every write needs `support.2fa` and updates only its section. Saving returns to the same tab. See [Platform settings](01-platform/02-platform-settings.md#routes-and-page).
- `EnsurePropertyAccess` redirects suspended staff to the suspension notice after checking property ownership. The notice redirects to the dashboard when the property is active again. Account routes stay available.
- `GET /p/{code}/logo` (`property.logo`) streams the private property logo for the staff navbar. It uses property.access, verified and live, with no settings permission. Upload and removal remain on the settings routes with `settings.manage`.
- `GET /p/{code}/dashboard/reservations/{reservation}` (`dashboard.reservation`) supplies data for dashboard check-in/out dialogs. It uses scoped bindings and `reservations.view`, without audit permission.
- Shared prop `property` (`PropertySummaryData`: code, name, status, logoUrl): the property in the URL, or the staff user's own property on pages without one (account pages). `logoUrl` is null without a logo and includes the media ID as a cache version otherwise. Null for admins outside `/p`.

## Examples

Guest bulk actions use `POST /p/{code}/guests/bulk/{operation}`: `mark-vip`, `unmark-vip`, `blacklist`, `unblacklist` and `anonymize` (`guests.bulk.*`). Each checks its existing guest permission and resolves the whole selection within the property before writes. See [Bulk selection](11-guests/01-guest-profiles.md#bulk-selection-and-actions).

| URL                                                                | Name                                           | Page                                             |
| ------------------------------------------------------------------ | ---------------------------------------------- | ------------------------------------------------ |
| `GET /p/ES10142/front-desk/stay-view`                              | `front-desk.stay-view`                         | `pages/front-desk/stay-view.tsx`                 |
| `GET /p/ES10142/front-desk/stay-view/version`                      | `front-desk.stay-view.version`                 | – (JSON)                                         |
| `GET /p/ES10142/front-desk/room-blocks`                            | `front-desk.room-blocks.index`                 | `pages/front-desk/room-blocks/index.tsx`         |
| `POST /p/ES10142/front-desk/room-blocks/{roomBlock}/end`           | `front-desk.room-blocks.end`                   | – (Action)                                       |
| `GET /p/ES10142/reservations/create`                               | `reservations.create`                          | `pages/reservations/create.tsx`                  |
| `POST /p/ES10142/reservations/{reservation}/units/{unit}/check-in` | `reservations.units.check-in`                  | – (Action)                                       |
| `GET /p/ES10142/finance/folios/{folio}`                            | `finance.folios.show`                          | `pages/finance/folios/show.tsx`                  |
| `GET /p/ES10142/finance/city-ledger/companies`                     | `finance.city-ledger.companies.index`          | `pages/finance/city-ledger/companies/index.tsx`  |
| `GET /p/ES10142/finance/city-ledger/aging`                         | `finance.city-ledger.aging`                    | `pages/finance/city-ledger/aging.tsx`            |
| `GET /p/ES10142/settings/room-types`                               | `settings.room-types.index`                    | `pages/settings/room-types/index.tsx`            |
| `GET /p/ES10142/settings/room-types/{roomType}`                    | `settings.room-types.edit`                     | `pages/settings/room-types/edit.tsx`             |
| `GET /p/ES10142/settings/room-types/{roomType}/rooms`              | `settings.room-types.rooms.edit`               | `pages/settings/room-types/rooms.tsx`            |
| `PUT /p/ES10142/settings/room-types/{roomType}/rooms`              | `settings.room-types.rooms.update`             | Save the room type's rooms and groups            |
| `GET /p/ES10142/settings/ota-lists`                                | `settings.ota-lists.edit`                      | `pages/settings/ota-lists.tsx`                   |
| `GET /p/ES10142/onboarding/rooms`                                  | `onboarding.rooms.edit`                        | `pages/onboarding/rooms.tsx`                     |
| `GET /p/ES10142/onboarding/taxes`                                  | `onboarding.taxes.edit`                        | `pages/onboarding/taxes.tsx`                     |
| `POST /p/ES10142/onboarding/taxes/preview`                         | `onboarding.taxes.preview`                     | Example night for the unsaved table (JSON)       |
| `PUT /p/ES10142/onboarding/taxes`                                  | `onboarding.taxes.update`                      | Save the Taxes table                             |
| `DELETE /p/ES10142/onboarding/taxes/{tax}`                         | `onboarding.taxes.destroy`                     | Remove a custom tax                              |
| `GET /p/ES10142/onboarding/payment-methods`                        | `onboarding.payment-methods.edit`              | `pages/onboarding/payment-methods.tsx`           |
| `PUT /p/ES10142/onboarding/payment-methods`                        | `onboarding.payment-methods.update`            | Save the Payment methods table                   |
| `GET /p/ES10142/onboarding/companies`                              | `onboarding.companies.edit`                    | `pages/onboarding/companies.tsx`                 |
| `PUT /p/ES10142/onboarding/companies`                              | `onboarding.companies.update`                  | Save the Companies table                         |
| `DELETE /p/ES10142/onboarding/companies/{company}`                 | `onboarding.companies.destroy`                 | Remove a company nothing refers to               |
| `POST /p/ES10142/onboarding/companies/import`                      | `onboarding.companies.import`                  | Read a CSV or XLSX file into rows (JSON)         |
| `GET /p/ES10142/onboarding/companies/import-template.csv`          | `onboarding.companies.template`                | Download the empty Simple file                   |
| `GET /p/ES10142/onboarding/basic-rate`                             | `onboarding.basic-rate.edit`                   | `pages/onboarding/basic-rate.tsx`                |
| `PUT /p/ES10142/onboarding/basic-rate`                             | `onboarding.basic-rate.update`                 | Save the Basic rate table                        |
| `POST /p/ES10142/onboarding/basic-rate/preview`                    | `onboarding.basic-rate.preview`                | What the guest pays for the rows (JSON)          |
| `GET /admin`                                                       | `admin.dashboard`                              | `pages/admin/dashboard.tsx`                      |
| `GET /admin/settings`                                              | `admin.settings.edit`                          | `pages/admin/settings/edit.tsx`                  |
| `GET /admin/properties`                                            | `admin.properties.index`                       | `pages/admin/properties/index.tsx`               |
| `POST /admin/properties`                                           | `admin.properties.store`                       | –                                                |
| `GET /admin/properties/ES10142`                                    | `admin.properties.show`                        | `pages/admin/properties/show.tsx`                |
| `GET /admin/properties/ES10142/ota-lists`                          | `admin.properties.ota-lists`                   | `pages/admin/properties/show.tsx`                |
| `POST /admin/properties/ES10142/ota-lists/3/reveal`                | `admin.properties.ota-lists.reveal`            | –                                                |
| `PUT /admin/properties/ES10142/ota-lists/3/processed`              | `admin.properties.ota-lists.processed`         | –                                                |
| `POST /admin/properties/ES10142/owner/setup-link`                  | `admin.properties.owner.setup-link`            | –                                                |
| `GET /admin/partners`                                              | `admin.partners.index`                         | `pages/admin/partners/index.tsx`                 |
| `GET /admin/partners/invited`                                      | `admin.partners.invited`                       | `pages/admin/partners/index.tsx`                 |
| `GET /admin/partners/bulk`                                         | `admin.partners.bulk.create`                   | `pages/admin/partners/bulk.tsx`                  |
| `GET /admin/properties/AB00001/channels`                           | `admin.properties.channels`                    | `pages/admin/properties/show.tsx` (Channels tab) |
| `POST /admin/properties/AB00001/channels/sync`                     | `admin.properties.channels.sync`               | –                                                |
| `DELETE /admin/properties/AB00001/channels/rate-plans/5`           | `admin.properties.channels.rate-plans.destroy` | –                                                |
| `DELETE /admin/properties/AB00001/channels/room-types/5`           | `admin.properties.channels.room-types.destroy` | –                                                |
| `GET /admin/channels`                                              | `admin.channels.index`                         | `pages/admin/channels/index.tsx`                 |
| `POST /admin/channels`                                             | `admin.channels.store`                         | –                                                |
| `PUT /admin/channels/order`                                        | `admin.channels.reorder`                       | –                                                |
| `PUT /admin/channels/3`                                            | `admin.channels.update`                        | –                                                |
| `PUT /admin/channels/3/active`                                     | `admin.channels.active.update`                 | –                                                |
| `GET /partner-invitations/{token}`                                 | `partner-invitations.show`                     | `pages/partner-invitations/accept.tsx`           |
| `GET /command-search?q=ah`                                         | `command-search`                               | – (JSON, grouped palette results)                |
| `GET /view-as/ES10142`                                             | `view-as.show`                                 | `pages/view-as/show.tsx`                         |
| `GET /partner`                                                     | `partner.properties.index`                     | `pages/partner/properties/index.tsx`             |
| `GET /join/K7Q2MX`                                                 | `join`                                         | Redirects to `/register`                         |
| `GET /g/cards/5821?expires=…&signature=…`                          | `guest.cards.show`                             | `pages/guest/cards/show.tsx`                     |
| `GET /g/owners/82/setup?expires=…&signature=…`                     | `guest.owner-setup.show`                       | `pages/auth/owner-setup.tsx`                     |
| `POST /g/owners/82/setup?expires=…&signature=…`                    | `guest.owner-setup.store`                      | –                                                |
| `GET /p/ES10142/channels`                                          | `channels.index`                               | `pages/channels/index.tsx`                       |
| `GET /admin/properties/ES10142/channels`                           | `admin.properties.channels`                    | `pages/admin/properties/show.tsx`                |
| `GET /admin/properties/ES10142/channels/connect/agoda`             | `admin.properties.channels.connect`            | `pages/admin/properties/channels/connect.tsx`    |
| `POST /webhooks/channex`                                           | `webhooks.channex`                             | –                                                |

The full list of admin channel routes is in [Admin Channels tab](21-channels/07-admin-channels-tab.md#routes).

Frontend import example:

```ts
import { stayView } from '@/routes/front-desk';
```

## Configuration vs operations (decided)

- **Configuration** lives under `/p/{code}/settings/...` with names `settings.*`: property details, room types, rooms and beds, room groups, rate plans, packages, taxes, charge items, payment methods, discounts, meal periods, age groups, boats, housekeeping checklist items, terms documents, registration card fields, staff and roles, notification settings.
- **Daily operations** live under module prefixes: front desk, reservations, guests, housekeeping, night audit, finance (folios, payments, city ledger), reports, channels.
- **Exception:** the rate calendar is used daily, so it lives at `/p/{code}/rates/calendar` (`rates.calendar`, with `rates.calendar.bulk` for bulk edit).
- Onboarding sections have their own pages under `pages/onboarding/` and save through the same Actions as settings.
- Guest links outside `/p`: staff invitations at `/invitations/{token}` (`invitations.*`, `routes/staff.php`, guest only). See [Staff and invitations](03-staff/01-staff-and-invitations.md). Partner invitations at `/partner-invitations/{token}` (`partner-invitations.*`, `routes/partner.php`, guest only, accept throttled 6 per minute). See [Partners](17-onboarding/05-partners.md#invite-by-email).
- Owner setup uses signed guest GET and POST routes under `/g/owners/{user}/setup`. Both are guest-only. The POST is throttled, and the GET supplies the exact signed POST URL because the browser must not reconstruct its signature. Links expire after seven days.
- Each settings page group carries its own `can:` permission middleware (property details: `settings.manage`; staff: `staff.view`, invitations: `staff.manage`), because staff and roles pages under `settings.*` need other permissions. Staff without it get 403.

## Account vs settings (decided)

- **Account** is about the signed-in person, has no property code, and lives in the user menu: `/account/profile`, `/account/security`, `/account/appearance`, `/account/notifications` (names under `account.*`). Code: `App\Http\Controllers\Account`, `pages/account/*`, `layouts/account/layout.tsx`, `routes/account.php`.
- Account pages have their own [account shell](design-system/09-app-shell.md#account-shell), not the staff, admin or partner sidebar. **Back to workspace** uses `home.redirect` to return to the appropriate home.
- **Settings** always means the property, under `/p/{code}/settings`, and appears in the sidebar.
- Account also has `GET` and `PUT /account/set-password` (`account.set-password.edit` and `.update`, `auth` only) for users with a temp password, and `DELETE /account/sessions` (`account.sessions.destroy`, auth and verified) for **Log out other devices**.

## Company portal routes

| URL                                   | Name                                            |
| ------------------------------------- | ----------------------------------------------- |
| `GET /portal`                         | `portal.index` (dashboard)                      |
| `GET /portal/properties`              | `portal.properties`                             |
| `GET /portal/{code}`                  | `portal.show`                                   |
| `GET /portal/{code}/logo`             | `portal.logo` (property logo, for the switcher) |
| `GET /portal/{code}/bookings`         | `portal.bookings.index`                         |
| `GET /portal/{code}/bookings/new`     | `portal.bookings.create`                        |
| `POST /portal/{code}/bookings/offers` | `portal.bookings.offers` (throttled)            |
| `POST /portal/{code}/bookings`        | `portal.bookings.store` (throttled)             |
| `GET /portal/{code}/bookings/{id}`    | `portal.bookings.show` (`whereNumber`)          |

`new` and `offers` do not clash with `{reservation}`, which only matches digits. The old `/portal/{code}/book` URLs return 404.

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
| **Reports**    | `reports.*`                                                                                             | Front desk, Revenue, Finance and Kitchen reports (see Core reports), Night audit history, Activity log      |
| **Settings**   | `settings.*`, `channels.*`                                                                              | Property, Rooms, Rates, Billing setup, Guests, Team, Notifications, Channels (feature flag), Support access |

- The active context is derived from the current route name.
- Account is not a context. It lives in the user menu.
- Onboarding, admin and guest pages use their own layouts without contexts.
- Details: [App shell](design-system/09-app-shell.md).

## Later

- **Short guest links**: `example.com/c/K7Q2MX` redirecting to the signed URL, backed by a `short_links` table, when cards are sent by WhatsApp or SMS.
- **Public booking engine**: `example.com/stay/{slug}` or `book.example.com/{slug}`, using a `properties.slug` added at that time.
