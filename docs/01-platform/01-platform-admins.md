# Platform admins

## Purpose

Platform admins run estatell itself. They create properties for owners, watch onboarding progress, toggle features and decide platform-wide settings. They are not property staff.

## Data model

Admins are rows in `users` with `is_admin = true`. There is no `admins` table and no `admin` guard.

```
users    id, property_id (nullable), is_admin (bool, default false), name, email, password, two_factor columns, timestamps

CHECK ((is_admin AND property_id IS NULL) OR (NOT is_admin AND property_id IS NOT NULL))
```

- An admin never has a property. A staff user always has one.
- `is_admin` is not fillable. Only `AdminSeeder` (reads `ADMIN_SEED_EMAIL` and `ADMIN_SEED_PASSWORD`) or a console command sets it. Fortify registration cannot.
- Admins never belong to a property permission team.
- Emails are unique across all users. An admin who also owns a property needs a second email.
- Built: `is_admin`, `property_id` (restrict on delete) and the CHECK `users_property_or_admin_check`. Registration creates the property and the owner together (see [Account creation](../17-onboarding/01-account-creation.md)).

## Why one table

Fortify authenticates one guard (`fortify.guard = web`). A separate `admins` table needs a second guard, and then a second login, 2FA challenge, password reset broker, password confirmation and rate limits, all written by hand. One table gets all of these from Fortify. Impersonation works by a session swap (see [Impersonation](04-impersonation.md)).

## Sign-in

```
/login (Fortify, one page)
   │
   ├─ is_admin ──► /admin
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

| Where                                              | Rule                                                                                  |
| -------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Local and testing                                  | Not required                                                                          |
| Production (`ADMIN_REQUIRE_2FA=true`, the default) | Admins can sign in and view `/admin` without 2FA. Admin actions stay locked until set |
| Staff                                              | Optional, as Fortify provides                                                         |

- Locked admin actions: impersonate, platform settings, feature flags, create or suspend a property, dorm strategy.
- Middleware `admin.2fa` (`RequireAdminTwoFactor`) guards the locked routes. It redirects back with the error toast "Two-factor required for this action." and returns `403` to JSON requests. The UI hides the same buttons.
- An admin without 2FA sees a banner on every app-layout page: "Set up two-factor to use admin actions" with **Set up** (goes to account security). Shared Inertia prop `adminNeedsTwoFactor`.
- Passkeys (not built yet): enable Fortify `Features::passkeys()`. A confirmed passkey will count as 2FA. Until then, 2FA means `two_factor_confirmed_at` is set. Staff may use passkeys too.
- Config: `config('estatell.admin_require_two_factor')`.

## Capabilities

- Create a property and invite its owner (see [Account creation](../17-onboarding/01-account-creation.md)).
- View the onboarding board (see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md)).
- Toggle features per property (see [Feature flags](03-feature-flags.md)).
- Edit [Platform settings](02-platform-settings.md).
- Change the channel dorm strategy (see [Dorm strategies](../21-channels/03-dorm-strategies.md)).
- View a property as its owner or staff (see [Impersonation](04-impersonation.md)).
- Suspend a property.

## Rules

- Admin routes live under `/admin` with `auth` + `can:admin`.
- Every admin write is recorded in the activity log with the admin as causer.
