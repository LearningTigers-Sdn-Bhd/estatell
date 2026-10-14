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

| Entry point          | Where                                                                                                | Result                                                                |
| -------------------- | ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| **[View as]** button | Admin property page, partner properties list, onboarding board row (comes with the onboarding board) | Opens the view-as page for that property                              |
| Admin ⌘K             | Type a property code, name, city, or a reservation reference                                         | "View as owner" / "View as owner and open"                            |
| Copied link          | "Copy view-as link" next to [View as]                                                                | `/view-as/{code}?to=…&reason=…`                                       |
| **Pasted staff URL** | Any `/p/{code}/…` URL opened by a support user who may view it                                       | Intercepted, redirected to the view-as page with `to` set to that URL |

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
│ Money   ✓ Refunds and voids allowed by the owner  │
│                    [ Cancel ]  [ View as Aisha ]  │  ← Enter
└───────────────────────────────────────────────────┘
          │ POST (CSRF-protected)
          ▼
app.example.com/p/ES10142/reservations/881   with the support banner
```

- **User**: the Owner is pre-selected. Other active staff of the property can be chosen.
- **Reason**: chips, one pre-selected by entry point (onboarding board → Onboarding help, everywhere else → Support, or `?reason=`). Note is optional.
- **Money**: one read-only line from `allow_support_money_corrections`: "Refunds and voids allowed by the owner" or "Refunds and voids blocked by the owner". See [Money corrections](#money-corrections).
- **Opens**: the path from `to`. Without a valid `to`, the property dashboard (or Basics, while the property is in Onboarding).
- **Cancel** and ✕ go to the admin or partner property page.

While impersonating, every page shows a banner that cannot be dismissed:

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠ Viewing as Aisha (Owner) · Pulau Inn (ES10142) · 28 min left [ Stop ]│
└──────────────────────────────────────────────────────────────────────┘
```

**Stop** ends the session and returns to the admin or partner property page, with a toast "Support session ended (6 min)". The banner sticks to the top of the staff content area. Minutes left refresh on each page load.

## Cases

| Support user's state when opening `/p/{code}/…`    | Result                                                                                                                                                                                                                                                                     |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Not impersonating                                  | View-as page for that property, then the requested page                                                                                                                                                                                                                    |
| Already impersonating the **same** property        | Opens directly, in any tab                                                                                                                                                                                                                                                 |
| Impersonating **another** property                 | View-as page reads "Switch to Pulau Inn (ES10142)? This ends your session with KL Backpackers (ES10150)." Confirming stops the old session and starts the new one (two logged events, and the old owner is notified). Cancel returns to the dashboard of the open session. |
| Requested page is blocked during support access    | Warning "This page isn't available during support access", with **Open dashboard instead**                                                                                                                                                                                 |
| Owner disabled support access                      | "Owner has disabled support access". Start button disabled.                                                                                                                                                                                                                |
| No confirmed 2FA while `SUPPORT_REQUIRE_2FA` is on | "Set up two-factor to view properties as their owner". Start button disabled                                                                                                                                                                                               |
| Code does not exist                                | `404`. On the view-as page an admin is sent to the properties list with a toast "Property ES10142 not found." Partners and staff still get the plain `404`.                                                                                                                |
| Non-GET request without a staff session            | `403`. Writes are never replayed after confirming.                                                                                                                                                                                                                         |
| Link opened after the 30-minute session ended      | View-as page again. New session, new log entry.                                                                                                                                                                                                                            |
| Unknown `reason` in the link                       | First chip is used                                                                                                                                                                                                                                                         |
| Link opened by someone who may not view it         | `404`, so other partners cannot tell the code exists                                                                                                                                                                                                                       |

## Guardrails

| Guardrail         | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Consent           | `property_settings.allow_support_access`, default true. The owner can switch it off in Settings. Until the roles module exists, any staff member of the property can.                                                                                                                                                                                                                                                                                                        |
| Reason            | Required (chip). Stored in the log.                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Time limit        | 30 minutes, then the session ends automatically and returns to the admin or partner page.                                                                                                                                                                                                                                                                                                                                                                                    |
| Permission        | `PropertyPolicy::viewAs`: admins for any property, partner users for their partner's properties while the partner is Active. 2FA only when `SUPPORT_REQUIRE_2FA` is on (`support.2fa`).                                                                                                                                                                                                                                                                                      |
| Scope             | Only staff users of one property. Never an admin or a partner user. One property per session.                                                                                                                                                                                                                                                                                                                                                                                |
| Blocked actions   | See below. Hidden in the UI and denied on the server.                                                                                                                                                                                                                                                                                                                                                                                                                        |
| Money corrections | `property_settings.allow_support_money_corrections`, default true. Refunds, voids and credit notes follow the owner's switch. See [Money corrections](#money-corrections).                                                                                                                                                                                                                                                                                                   |
| Audit             | Every write records the support user as the real causer and the impersonated user.                                                                                                                                                                                                                                                                                                                                                                                           |
| Notification      | Owner receives `SupportAccessUsed` when the session ends (so the duration is known): who (and the partner name for a partner), when, reason, note, duration. It also lists the money corrections made in the session (refunds, voids, credit notes, allocation reversals, with amount and reference), read from the activity log. Without any, the list is left out. If nobody makes another request, the session stays open until the next one, so the notice waits for it. |

### Blocked during impersonation

The `BlockDuringImpersonation` middleware (alias `block.impersonation`) denies these routes, and the UI hides them. The middleware runs in the web group, not on the routes, so the Fortify routes the vendor registers are covered too. The list lives in `Impersonation::isBlockedRoute()`:

| Blocked                        | Route or place                                                                                                                                                                                                      |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Password, two-factor, sessions | `account.password.update`, `account.security.*`, `account.sessions.destroy`, `account.set-password.*`                                                                                                               |
| Fortify two-factor             | `two-factor.enable`, `two-factor.confirm`, `two-factor.disable`, `two-factor.qr-code`, `two-factor.secret-key`, `two-factor.recovery-codes`, `two-factor.regenerate-recovery-codes`                                 |
| Confirm password               | `password.confirm`, `password.confirm.store`                                                                                                                                                                        |
| Delete account                 | `account.profile.destroy`                                                                                                                                                                                           |
| Notification preferences       | `account.notifications.update`                                                                                                                                                                                      |
| Identity document scans        | `guests.documents.scan`, `guests.documents.reveal`                                                                                                                                                                  |
| Go live                        | `onboarding.go-live.store`                                                                                                                                                                                          |
| Support access switches        | The `support` section of `UpdatePropertySettingsRequest` (`allow_support_access` and `allow_support_money_corrections`) refuses with `403`. Other settings sections stay allowed.                                   |
| Manage staff and roles         | Every non-GET `settings.staff.*` route: add, bulk add, import, invite, cancel and resend invites, temporary passwords (reset, export, message), deactivate, reactivate, change role. The staff lists stay readable. |
| Permissions                    | No routes yet. Add `block.impersonation` when the roles module builds them.                                                                                                                                         |
| Force close the night audit    | No route yet. Add `block.impersonation` when it is built.                                                                                                                                                           |

Denied requests show a toast: "Not available during support access".

### Money corrections

Owners in wastays asked support to make refunds and voids for them, and support did. So these actions are not always blocked. They follow the owner's switch `allow_support_money_corrections` (default true), shown under the access switch in Settings → Support:

```
Support access
  [●] Allow support to view the property as its staff        allow_support_access
  [●] Allow support to refund, void and issue credit notes   allow_support_money_corrections
```

| Money correction            | Route or place                                                                                                                                              |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Refunds                     | `reservations.folios.payments.refunds.store`                                                                                                                |
| Voids                       | `reservations.folios.void-invoice-and-reopen`, `registration.void`, `finance.city-ledger.*.void`                                                            |
| Credit notes and reversals  | `finance.city-ledger.invoices.credit-notes.store`, `finance.city-ledger.companies.payments.allocations.reverse`                                             |
| Deposit refund at check-out | `reservations.units.check-out` (`refund_amount`) and `reservations.check-out.store` (a row with choice `refund`). Check-out without a refund stays allowed. |

| Switch | During a session                                                                                                                                                                          |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| On     | The actions run. The activity log records the support user as causer, and the owner's notice lists them.                                                                                  |
| Off    | The routes are denied with the toast "The owner has not allowed refunds and voids during support access". The check-out requests refuse a refund the same way. The UI hides the controls. |

The list lives in `Impersonation::isMoneyCorrectionRoute()`, next to `isBlockedRoute()`, and the same middleware checks it. Folio line reversals (`reservations.folios.lines.reverse`) are not money corrections. They stay allowed, because fixing charges is setup work.

When e-invoicing (LHDN) arrives, voiding a submitted e-invoice becomes an external step that cannot be undone. Revisit then whether those voids leave this switch and become always blocked.

Everything else is allowed, including fixing setup (rooms, rates, taxes, terms), which is the main reason to impersonate.

## One guard

Admins, partner users and staff share the `web` guard (see [Platform admins](01-platform-admins.md#why-one-table)). Impersonation swaps the signed-in user in the same session:

```
Start:  session['impersonation'] = {support_user_id, user_id, property_id, reason, note, started_at, return_url}
        Auth::login($staff)
Stop:   Auth::login($supportUser); forget the session key; log impersonation.ended
```

| Concern                            | Handling                                                                                                                                                                                                                    |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Support routes while impersonating | The `admin` and `partner` gates check the signed-in user only, so a support session never reaches `/admin` or `/partner`. `viewAs` checks `Impersonation::supportUser() ?? auth()->user()`, so the view-as flow still works |
| Unfinished owner sign-up           | `verified` and `EnsurePasswordChanged` are skipped during a session, so support can help an owner who has not verified their email or still has a temp password                                                             |
| Target                             | Must have `property_id` equal to the property (so never an admin or a partner user)                                                                                                                                         |
| Audit                              | A `LogActivityAction::beforeLogging` callback in `AppServiceProvider` swaps the staff user for the real support user as causer and adds `impersonated_user_id` to the log properties on every entry                         |
| Staff 2FA                          | Skipped during impersonation (intended). Confirm-password routes are blocked                                                                                                                                                |
| Logout while impersonating         | Ends the whole session. A `Logout` listener logs `impersonation.ended` and notifies the owner before the session is invalidated                                                                                             |
| Session ID                         | `Auth::login` regenerates the ID and keeps the session data                                                                                                                                                                 |
| Remember-me cookie                 | Stays the support user's. An expired session returns as the support user, never as the staff user                                                                                                                           |

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

- `Impersonation` service (an injected instance, not static): reads the session and answers `isActive()` (the signed-in user must be the session's user), `forProperty()`, `supportUser()`, `remainingMinutes()`, `elapsedMinutes()`, `returnUrl()`, `accepts()` (the `to` rules) and `destination()` (`to` or the start page).
- `StartImpersonation`: checks `viewAs`, consent and that the target is active staff of the property (2FA is the route middleware), stores the session, calls `Auth::login($staff)` on the web guard, logs `impersonation.started`, returns the path to open. An open session is stopped first, which is how switching works. It returns null when refused. The return URL is the admin property page for an admin and the partner property page for a partner.
- `StopImpersonation`: calls `Auth::login($supportUser)` with the stored support user id, forgets the session key, calls `RecordImpersonationEnd` (logs `impersonation.ended` with the duration and notifies the owner), redirects to the stored return URL.
- `RecordImpersonationEnd`: logs `impersonation.ended` and sends `SupportAccessUsed`. It does not end the session, so Stop and the logout listener share it.
- `EndExpiredImpersonation`: runs StopImpersonation when the 30 minutes have passed. The `EndExpiredImpersonationSession` web middleware calls it on every request, so `/account` pages expire too, and redirects to the return URL with the toast. The duration is capped at 30.

## Routes

| Method | URL                        | Name                 | Purpose                                              |
| ------ | -------------------------- | -------------------- | ---------------------------------------------------- |
| GET    | `/view-as/{property:code}` | `view-as.show`       | View-as page (reads `to`, `reason`)                  |
| POST   | `/view-as/{property:code}` | `view-as.store`      | Start the session, redirect to `to` (`support.2fa`)  |
| POST   | `/impersonation/stop`      | `impersonation.stop` | End the session, return to the admin or partner page |

## Data

- `property_settings.allow_support_access` (bool, default true).
- `property_settings.allow_support_money_corrections` (bool, default true).
- No new table. Sessions are recorded in the activity log with events `impersonation.started` and `impersonation.ended` (support user, partner, user, property, reason, note, duration).
- Notification type `SupportAccessUsed` (see [Notification types](../04-notifications/01-notification-types.md)).
- No permission rows. `PropertyPolicy::viewAs` and the `support.2fa` middleware decide who can start.

## UI

- View-as page: a small full-screen page with the form above, in the admin layout for admins and the partner layout for partners. It is a page (not a Dialog) because it must work from a pasted URL.
- Banner: shared Inertia prop `impersonation` (`ImpersonationData`: support user name, partner name or null, user name and role, property name and code, minutes left, stop URL, `canCorrectMoney`). A partner session reads "… · Pulau Inn (AS10101) · via Andaman Stays · 28 min left". Uses the `warning` status token.
- Blocked actions are hidden using the same prop, read through `useIsImpersonating()` (`resources/js/hooks/use-is-impersonating.ts`). Money corrections are hidden only when the prop's `canCorrectMoney` is false (from `allow_support_money_corrections`), read through the same hook. Where a whole page is a write form (bulk add staff, notification preferences, the support tab), the page shows one line, "Not available during support access", instead of the form.

## Build choice

Custom, about four small classes. `lab404/laravel-impersonate` (v1.7.8 supports Laravel 13) was considered. It saves little, because consent, reasons, blocking, the URL interstitial and auditing still need writing.

| Piece            | Location                                                                                                    |
| ---------------- | ----------------------------------------------------------------------------------------------------------- |
| `PropertyPolicy` | `app/Policies/PropertyPolicy.php` (the first policy in the app)                                             |
| Controllers      | `app/Http/Controllers/Platform/ViewAsController.php` (show, store), `ImpersonationController.php` (stop)    |
| View-as page     | `ViewAsStaff` lists the staff, `ImpersonationReason` holds the chips, `StartImpersonationRequest` validates |
| `Impersonation`  | `app/Services/Platform/Impersonation.php`                                                                   |
| Actions          | `app/Actions/Platform/StartImpersonation.php`, `StopImpersonation.php`, `EndExpiredImpersonation.php`       |
| Blocking         | `app/Http/Middleware/BlockDuringImpersonation.php`                                                          |

Built in four commits: view as (start, stop, banner, entry points), then logging and limits (audit causer, log events, `SupportAccessUsed`, 30-minute expiry, switching properties), then blocking, then the owner's money corrections switch.

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
- With the money switch on, a refund during a session runs and is listed in the owner's notice.
- With the money switch off, a refund route and a check-out with a refund are refused; a check-out without a refund still works.
- The money switch cannot be changed during a session.
