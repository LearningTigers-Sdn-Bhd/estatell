# Platform admins

## Purpose

Platform admins run estatell itself. They create properties for owners, watch onboarding progress, toggle features and decide platform-wide settings. They are not property staff.

## Data model

Admins are rows in `users` with `is_admin = true`. There is no `admins` table and no `admin` guard.

```
users    id, property_id (nullable), partner_id (nullable), is_admin (bool, default false), name, email, password, two_factor columns, timestamps

CHECK (num_nonnulls(property_id, partner_id) + is_admin::int = 1)
```

- A user is exactly one of: an admin, a staff user (`property_id`) or a partner user (`partner_id`, see [Partners](../17-onboarding/05-partners.md)). An admin never has a property. A staff user always has one.
- Company users (`is_company_user`, module 23) extend the same CHECK when they are built.
- `is_admin` is not fillable. Only `AdminSeeder` (reads `ADMIN_SEED_EMAIL` and `ADMIN_SEED_PASSWORD`) or a console command sets it. Fortify registration cannot.
- Admins never belong to a property permission team.
- Emails are unique across all users. An admin who also owns a property needs a second email.
- Built: `is_admin`, `property_id` (restrict on delete) and the CHECK `users_property_or_admin_check` (replaced by `users_one_kind_check` when partners land). Registration creates the property and the owner together (see [Account creation](../17-onboarding/01-account-creation.md)).

## Why one table

Fortify authenticates one guard (`fortify.guard = web`). A separate `admins` table needs a second guard, and then a second login, 2FA challenge, password reset broker, password confirmation and rate limits, all written by hand. One table gets all of these from Fortify. Impersonation works by a session swap (see [Impersonation](04-impersonation.md)).

## Sign-in

```
/login (Fortify, one page)
   │
   ├─ is_admin ──► /admin
   ├─ partner ───► /partner
   └─ staff ─────► /p/{code}/dashboard   (or onboarding)
```

Custom `LoginResponse` and `TwoFactorLoginResponse` (`app/Http/Responses`) choose the destination. A stored intended URL wins. A signed-in admin who opens a guest page (for example `/login`) is sent to `/admin`.

## Access

| Item         | Rule                                                                                                                |
| ------------ | ------------------------------------------------------------------------------------------------------------------- |
| Gate         | `admin`: `is_admin` (and not deactivated, once `deactivated_at` exists). Others get `404`, so `/admin` stays hidden |
| Admin routes | `/admin`, middleware `auth` + `can:admin`                                                                           |
| Horizon      | `viewHorizon` uses the `admin` gate                                                                                 |
| Permissions  | No Spatie roles for admins. Every admin can do every admin action. Add roles when needed                            |

## Two-factor for admins

Two-factor is **optional by default** for admins and partners. One switch can make it required.

| Where                                            | Rule                                                                                                                 |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| `SUPPORT_REQUIRE_2FA=false` (default, every env) | No locks. Admins and partners without 2FA see a dismissible reminder: "Two-factor is recommended for support access" |
| `SUPPORT_REQUIRE_2FA=true`                       | Admin and partner writes and view as stay locked until 2FA is confirmed. The banner cannot be dismissed              |
| Staff                                            | Optional, as Fortify provides                                                                                        |

- Decided 2026-10-14: the business wants 2FA optional for admins too. The switch stays, so turning it on later is one env change.
- Locked actions when required: view as, platform settings, feature flags, create or suspend a property, partner management, dorm strategy, and partner writes (create a property, regenerate the signup link).
- Middleware `support.2fa` (`RequireSupportTwoFactor`, replaces `admin.2fa`) guards those routes. It does nothing while the switch is off. When on, it redirects back with the error toast "Two-factor required for this action." and returns `403` to JSON requests.
- Shared Inertia prop `supportTwoFactor`: `null` when the user has 2FA or is not an admin or partner, otherwise `{ required: bool }`. The banner shows **Set up** (goes to account security). Dismissing it is remembered in `localStorage` per browser; it shows again on a new device.
- Passkeys (not built yet): enable Fortify `Features::passkeys()`. A confirmed passkey will count as 2FA. Until then, 2FA means `two_factor_confirmed_at` is set. Staff may use passkeys too.
- Config: `config('estatell.support_require_two_factor')`.

## Capabilities

- Find and view any property (see [Admin properties](05-admin-properties.md)).
- Create a property and invite its owner (see [Account creation](../17-onboarding/01-account-creation.md)).
- View the onboarding board (see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md)).
- Toggle features per property (see [Feature flags](03-feature-flags.md)).
- Edit [Platform settings](02-platform-settings.md).
- Change the channel dorm strategy (see [Dorm strategies](../21-channels/03-dorm-strategies.md)).
- View a property as its owner or staff (see [Impersonation](04-impersonation.md)).
- Create, suspend and reactivate partners, and move a property to another partner (see [Partners](../17-onboarding/05-partners.md)).
- Suspend and reactivate a property. Each transition is logged with the admin and the old and new statuses.

## Rules

- Admin routes live under `/admin` with `auth` + `can:admin`.
- Every admin write is recorded in the activity log with the admin as causer.
