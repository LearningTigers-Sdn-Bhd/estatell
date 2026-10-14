# Impersonation (view as)

## Purpose

Let platform support see and fix exactly what a property's owner or staff member sees, without asking for their password. Most useful during onboarding and support calls.

Two roles can view as:

| Role    | Properties                                                                                        |
| ------- | ------------------------------------------------------------------------------------------------- |
| Admin   | Any property                                                                                      |
| Partner | Only properties with `properties.partner_id` = their partner, during onboarding and after go-live |

"Support user" below means an admin or a partner user. `PropertyPolicy::viewAs` decides who can view which property.

## Principles

- **Easy for the support user**: 2 clicks and Enter, or paste any staff URL and press Enter.
- **Never silent**: every session is consented, reasoned, time-limited, logged and notified to the owner.
- **One flow**: every entry point leads to the same view-as page and the same POST.

## Entry points

| Entry point          | Where                                                              | Result                                                                |
| -------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------- |
| **[View as]** button | Onboarding board row, admin property page, partner properties list | Opens the view-as page for that property                              |
| Admin ⌘K             | Type a property code, name, city, or a reservation reference       | "View as owner" / "View as owner and open"                            |
| Copied link          | "Copy view-as link" next to [View as]                              | `/view-as/{code}?to=…&reason=…`                                       |
| **Pasted staff URL** | Any `/p/{code}/…` URL opened by a support user who may view it     | Intercepted, redirected to the view-as page with `to` set to that URL |

Admin ⌘K and the copied link are parked. They come in a later commit, after ⌘K exists.

## Flow

```
Support user pastes  app.example.com/p/ES10142/reservations/881
                         │
                         ▼
          Staff route group, middleware: property.access
                         │
   ┌─────────────────────┼──────────────────────────────┐
   │ Staff of ES10142, or impersonating ES10142?            │──► page opens normally
   ├─────────────────────┼──────────────────────────────┤
   │ GET by an admin, or by the property's partner?     │──► /view-as/ES10142
   │                                                    │      ?to=/p/ES10142/reservations/881
   ├─────────────────────┼──────────────────────────────┤
   │ Neither                                            │──► normal staff login
   └────────────────────────────────────────────────────┘
```

View-as page (all fields pre-filled):

```
┌ View as Pulau Inn (ES10142) ───────────────────── ✕ ┐
│ You're signed in as support (admin or partner).   │
│ User    [ Aisha (Owner)                     ⌄ ]   │
│ Reason  [Onboarding help] [●Support] [Setup check]│
│ Note    [ optional __________________________ ]   │
│ Opens   Reservations › RES-2026-00881             │
│                    [ Cancel ]  [ View as Aisha ]  │  ← Enter
└───────────────────────────────────────────────────┘
          │ POST (CSRF-protected)
          ▼
app.example.com/p/ES10142/reservations/881   with the support banner
```

- **User**: the Owner is pre-selected. Other active staff of the property can be chosen.
- **Reason**: chips, one pre-selected by entry point (onboarding board → Onboarding help, everywhere else → Support, or `?reason=`). Note is optional.
- **Opens**: from `to`. Without `to`, the property dashboard (or onboarding, if not live).

While impersonating, every page shows a banner that cannot be dismissed:

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠ Viewing as Aisha (Owner) · Pulau Inn (ES10142) · 28 min left [ Stop ]│
└──────────────────────────────────────────────────────────────────────┘
```

**Stop** ends the session and returns to the admin or partner page the support user started from, with a toast "Support session ended (6 min)".

## Cases

| Support user's state when opening `/p/{code}/…`    | Result                                                                                                                                                                                 |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Not impersonating                                  | View-as page for that property, then the requested page                                                                                                                                |
| Already impersonating the **same** property        | Opens directly, in any tab                                                                                                                                                             |
| Impersonating **another** property                 | View-as page reads "Switch to Pulau Inn (ES10142)? This ends your session with KL Backpackers (ES10150)." Confirming stops the old session and starts the new one (two logged events). |
| Requested page is blocked during support access    | Warning "This page isn't available during support access", with **Open dashboard instead**                                                                                             |
| Owner disabled support access                      | "Owner has disabled support access". Start button disabled.                                                                                                                            |
| No confirmed 2FA while `SUPPORT_REQUIRE_2FA` is on | "Set up two-factor to view properties as their owner". Start button disabled                                                                                                           |
| Code does not exist                                | "Property ES10142 not found" (admins only)                                                                                                                                             |
| Non-GET request without a staff session            | `403`. Writes are never replayed after confirming.                                                                                                                                     |
| Link opened after the 30-minute session ended      | View-as page again. New session, new log entry.                                                                                                                                        |
| Unknown `reason` in the link                       | First chip is used                                                                                                                                                                     |
| Link opened by someone who may not view it         | `404`, so other partners cannot tell the code exists                                                                                                                                   |

## Guardrails

| Guardrail       | Rule                                                                                                                                                                                    |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Consent         | `property_settings.allow_support_access`, default true. The owner can switch it off in Settings. Until the roles module exists, any staff member of the property can.                   |
| Reason          | Required (chip). Stored in the log.                                                                                                                                                     |
| Time limit      | 30 minutes, then the session ends automatically and returns to the admin or partner page.                                                                                               |
| Permission      | `PropertyPolicy::viewAs`: admins for any property, partner users for their partner's properties while the partner is Active. 2FA only when `SUPPORT_REQUIRE_2FA` is on (`support.2fa`). |
| Scope           | Only staff users of one property. Never an admin or a partner user. One property per session.                                                                                           |
| Blocked actions | See below. Hidden in the UI and denied on the server.                                                                                                                                   |
| Audit           | Every write records the support user as the real causer and the impersonated user.                                                                                                      |
| Notification    | Owner receives `SupportAccessUsed`: who (and the partner name for a partner), when, reason, duration.                                                                                   |

### Blocked during impersonation

The `BlockDuringImpersonation` middleware (alias `block.impersonation`) denies these routes, and the UI hides them:

| Blocked                             | Route or place                                                                                                                                 |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Password, two-factor, sessions      | `account.password.update`, `account.security.*`, `account.sessions.destroy`, `account.set-password.*`                                          |
| Delete account                      | `account.profile.destroy`                                                                                                                      |
| Notification preferences            | `account.notifications.update`                                                                                                                 |
| Identity document scans             | `guests.documents.scan`, `guests.documents.reveal`                                                                                             |
| Refunds and voids                   | `reservations.folios.payments.refunds.store`, `reservations.folios.void-invoice-and-reopen`, `registration.void`, `finance.city-ledger.*.void` |
| Go live                             | `onboarding.go-live.store`                                                                                                                     |
| `allow_support_access`              | The `support` section of `UpdatePropertySettingsRequest` refuses with `403`. Other settings sections stay allowed.                             |
| Manage staff, roles and permissions | No routes yet. Add `block.impersonation` when the roles module builds them.                                                                    |
| Force close the night audit         | No route yet. Add `block.impersonation` when it is built.                                                                                      |

Denied requests show a toast: "Not available during support access".

Everything else is allowed, including fixing setup (rooms, rates, taxes, terms), which is the main reason to impersonate.

## One guard

Admins, partner users and staff share the `web` guard (see [Platform admins](01-platform-admins.md#why-one-table)). Impersonation swaps the signed-in user in the same session:

```
Start:  session['impersonation'] = {support_user_id, user_id, property_id, reason, started_at, return_url}
        Auth::login($staff)
Stop:   Auth::login($supportUser); forget the session key; log impersonation.ended
```

| Concern                            | Handling                                                                                                                                        |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Support routes while impersonating | The `admin` and `partner` gates and `viewAs` check `Impersonation::supportUser() ?? auth()->user()`, so the view-as and switch flow still works |
| Target                             | Must have `property_id` equal to the property (so never an admin or a partner user)                                                             |
| Audit                              | `CauserResolver::resolveUsing` returns the real support user, with `impersonated_user_id` in the log properties                                 |
| Staff 2FA                          | Skipped during impersonation (intended). Confirm-password routes are blocked                                                                    |
| Logout while impersonating         | Ends the whole session and logs `impersonation.ended`                                                                                           |
| Session ID                         | `Auth::login` regenerates the ID and keeps the session data                                                                                     |
| Remember-me cookie                 | Stays the support user's. An expired session returns as the support user, never as the staff user                                               |

## `to` validation

The `to` path is used only when all are true:

1. It is a relative path starting with `/` (not a full URL). Prevents open redirects.
2. It matches a real staff route.
3. Its property code equals the property being viewed.
4. It is not a blocked route.

Otherwise the dashboard is used (with the blocked-page warning for case 4).

## Middleware

`EnsurePropertyAccess` (alias `property.access`) replaces plain `auth` on the staff route group `p/{property:code}`. See [Routing](../routing.md).

In order (steps 2 and 3 are built with impersonation; until then admins and partners get `404` on `/p/{code}` routes):

1. Allow a web-guard user whose `property_id` matches the code.
2. Allow an active impersonation session for this property.
3. For a **GET** by a user who passes `PropertyPolicy::viewAs` for this property (an admin, or the property's partner): redirect to `view-as.show` with `to` = requested path and query.
4. Otherwise: behave like `auth` (redirect to login) for guests, or `404` for anyone else (another property's staff, another partner).

Then set, from the resolved property:

- `setPermissionsTeamId($property->id)`
- `URL::defaults(['property' => $property->code])`
- `Feature::resolveScopeUsing(...)` (Pennant)

## Services and Actions

- `Impersonation` service: reads the session (support user id, user id, property id, reason, started at, return URL) and answers `isActive()`, `forProperty()`, `remainingMinutes()`.
- `StartImpersonation`: checks `viewAs`, 2FA (when required) and consent, stores the session, calls `Auth::login($staff)` on the web guard, logs `impersonation.started`, notifies the owner, redirects to `to`.
- `StopImpersonation`: calls `Auth::login($supportUser)` with the stored support user id, forgets the session key, logs `impersonation.ended` with duration, redirects to the stored return URL.
- `EndExpiredImpersonation`: runs from the middleware when the 30 minutes have passed.

## Routes

| Method | URL                        | Name                 | Purpose                                              |
| ------ | -------------------------- | -------------------- | ---------------------------------------------------- |
| GET    | `/view-as/{property:code}` | `view-as.show`       | View-as page (reads `to`, `reason`)                  |
| POST   | `/view-as/{property:code}` | `view-as.store`      | Start the session, redirect to `to` (`support.2fa`)  |
| POST   | `/impersonation/stop`      | `impersonation.stop` | End the session, return to the admin or partner page |

## Data

- `property_settings.allow_support_access` (bool, default true).
- No new table. Sessions are recorded in the activity log with events `impersonation.started` and `impersonation.ended` (support user, partner, user, property, reason, note, duration).
- Notification type `SupportAccessUsed` (see [Notification types](../04-notifications/01-notification-types.md)).
- No permission rows. `PropertyPolicy::viewAs` and the `support.2fa` middleware decide who can start.

## UI

- View-as page: a small full-screen page with the form above, in the admin layout for admins and the partner layout for partners. It is a page (not a Dialog) because it must work from a pasted URL.
- Banner: shared Inertia prop `impersonation` (support user name, partner name or null, user, property, minutes left, stop URL). A partner session reads "… · Pulau Inn (AS10101) · via Andaman Stays · 28 min left". Uses the `warning` status token.
- Blocked actions are hidden using the same prop.

## Build choice

Custom, about four small classes. `lab404/laravel-impersonate` (v1.7.8 supports Laravel 13) was considered. It saves little, because consent, reasons, blocking, the URL interstitial and auditing still need writing.

| Piece            | Location                                                                                              |
| ---------------- | ----------------------------------------------------------------------------------------------------- |
| `PropertyPolicy` | `app/Policies/PropertyPolicy.php` (the first policy in the app)                                       |
| `Impersonation`  | `app/Services/Platform/Impersonation.php`                                                             |
| Actions          | `app/Actions/Platform/StartImpersonation.php`, `StopImpersonation.php`, `EndExpiredImpersonation.php` |
| Blocking         | `app/Http/Middleware/BlockDuringImpersonation.php`                                                    |

Built in three commits: view as (start, stop, banner, entry points), then logging and limits (audit causer, log events, `SupportAccessUsed`, 30-minute expiry, switching properties), then blocking.

## Tests

- Admin opening a staff URL is redirected to the view-as page with the correct `to`.
- A partner opening their own property's staff URL is redirected the same way; another partner's property returns 404.
- A suspended partner cannot start a session.
- Starting redirects to `to` and shows the banner prop.
- Blocked routes return an error during impersonation.
- Consent off prevents starting.
- Switching properties ends the old session and starts a new one.
- A non-GET request from a support user without a session returns 403.
- An external `to` URL is ignored.
- Writes during impersonation record the support user as causer.
- The session ends after 30 minutes.
