# Impersonation (view as)

## Purpose

Let platform support see and fix exactly what a property's owner or staff member sees, without asking for their password. Most useful during onboarding and support calls.

## Principles

- **Easy for the admin**: 2 clicks and Enter, or paste any staff URL and press Enter.
- **Never silent**: every session is consented, reasoned, time-limited, logged and notified to the owner.
- **One flow**: every entry point leads to the same view-as page and the same POST.

## Entry points

| Entry point          | Where                                                        | Result                                                                |
| -------------------- | ------------------------------------------------------------ | --------------------------------------------------------------------- |
| **[View as]** button | Onboarding board row, property page                          | Opens the view-as page for that property                              |
| Admin ⌘K             | Type a property code, name, city, or a reservation reference | "View as owner" / "View as owner and open"                            |
| Copied link          | "Copy view-as link" next to [View as]                        | `/admin/properties/{code}/view-as?to=…&reason=…`                      |
| **Pasted staff URL** | Any `/p/{code}/…` URL opened while signed in as admin        | Intercepted, redirected to the view-as page with `to` set to that URL |

## Flow

```
Admin pastes  app.example.com/p/10142/reservations/881
                         │
                         ▼
          Staff route group, middleware: property.access
                         │
   ┌─────────────────────┼──────────────────────────────┐
   │ Staff of 10142, or impersonating 10142?            │──► page opens normally
   ├─────────────────────┼──────────────────────────────┤
   │ No staff session, but signed in as admin (GET)?    │──► /admin/properties/10142/view-as
   │                                                    │      ?to=/p/10142/reservations/881
   ├─────────────────────┼──────────────────────────────┤
   │ Neither                                            │──► normal staff login
   └────────────────────────────────────────────────────┘
```

View-as page (all fields pre-filled):

```
┌ View as Pulau Inn (10142) ───────────────────── ✕ ┐
│ You're signed in as an estatell admin.            │
│ User    [ Aisha (Owner)                     ⌄ ]   │
│ Reason  [Onboarding help] [●Support] [Setup check]│
│ Note    [ optional __________________________ ]   │
│ Opens   Reservations › RES-2026-00881             │
│                    [ Cancel ]  [ View as Aisha ]  │  ← Enter
└───────────────────────────────────────────────────┘
          │ POST (CSRF-protected)
          ▼
app.example.com/p/10142/reservations/881   with the support banner
```

- **User**: the Owner is pre-selected. Other active staff of the property can be chosen.
- **Reason**: chips, one pre-selected by entry point (onboarding board → Onboarding help, everywhere else → Support, or `?reason=`). Note is optional.
- **Opens**: from `to`. Without `to`, the property dashboard (or onboarding, if not live).

While impersonating, every page shows a banner that cannot be dismissed:

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠ Viewing as Aisha (Owner) · Pulau Inn (10142) · 28 min left [ Stop ]│
└──────────────────────────────────────────────────────────────────────┘
```

**Stop** ends the session and returns to the admin page the admin started from, with a toast "Support session ended (6 min)".

## Cases

| Admin's state when opening `/p/{code}/…`        | Result                                                                                                                                                                             |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Not impersonating                               | View-as page for that property, then the requested page                                                                                                                            |
| Already impersonating the **same** property     | Opens directly, in any tab                                                                                                                                                         |
| Impersonating **another** property              | View-as page reads "Switch to Pulau Inn (10142)? This ends your session with KL Backpackers (10150)." Confirming stops the old session and starts the new one (two logged events). |
| Requested page is blocked during support access | Warning "This page isn't available during support access", with **Open dashboard instead**                                                                                         |
| Owner disabled support access                   | "Owner has disabled support access". Start button disabled.                                                                                                                        |
| Admin lacks `admin.impersonate`                 | "You don't have permission to view properties as their owner"                                                                                                                      |
| Code does not exist                             | "Property 10142 not found" (admins only)                                                                                                                                           |
| Non-GET request without a staff session         | `403`. Writes are never replayed after confirming.                                                                                                                                 |
| Link opened after the 30-minute session ended   | View-as page again. New session, new log entry.                                                                                                                                    |
| Unknown `reason` in the link                    | First chip is used                                                                                                                                                                 |
| Link opened by a non-admin                      | `/admin/...` returns `404`                                                                                                                                                         |

## Guardrails

| Guardrail       | Rule                                                                                             |
| --------------- | ------------------------------------------------------------------------------------------------ |
| Consent         | `property_settings.allow_support_access`, default true. The owner can switch it off in Settings. |
| Reason          | Required (chip). Stored in the log.                                                              |
| Time limit      | 30 minutes, then the session ends automatically and returns to the admin page.                   |
| Permission      | Only admins with `admin.impersonate` see [View as] and can start a session.                      |
| Scope           | Only staff users of one property. Never another admin. One property per session.                 |
| Blocked actions | See below. Hidden in the UI and denied on the server.                                            |
| Audit           | Every write records the admin as the real causer and the impersonated user.                      |
| Notification    | Owner receives `SupportAccessUsed`: who, when, reason, duration.                                 |

### Blocked during impersonation

The `BlockDuringImpersonation` middleware denies these routes, and the UI hides them:

- Change password, email or two-factor settings.
- Delete account.
- Manage staff, roles and permissions.
- Change notification preferences.
- Change `allow_support_access`.
- View identity document scans.
- Refunds and voids.
- Force close the night audit.
- `GoLive`.

Denied requests show a toast: "Not available during support access".

Everything else is allowed, including fixing setup (rooms, rates, taxes, terms), which is the main reason to impersonate.

## `to` validation

The `to` path is used only when all are true:

1. It is a relative path starting with `/` (not a full URL). Prevents open redirects.
2. It matches a real staff route.
3. Its property code equals the property being viewed.
4. It is not a blocked route.

Otherwise the dashboard is used (with the blocked-page warning for case 4).

## Middleware

`EnsurePropertyAccess` (alias `property.access`) replaces plain `auth` on the staff route group `p/{property:code}`. See [Routing](../routing.md).

In order:

1. Allow a web-guard user whose `property_id` matches the code.
2. Allow an active impersonation session for this property.
3. For a **GET** by an authenticated **admin**: redirect to `admin.properties.view-as` with `to` = requested path and query.
4. Otherwise: behave like `auth` (redirect to login) for guests, or `404` for a staff user using another property's code.

Then set, from the resolved property:

- `setPermissionsTeamId($property->id)`
- `URL::defaults(['property' => $property->code])`
- `Feature::resolveScopeUsing(...)` (Pennant)

## Services and Actions

- `Impersonation` service: reads the session (admin id, user id, property id, reason, started at, return URL) and answers `isActive()`, `forProperty()`, `remainingMinutes()`.
- `StartImpersonation`: checks permission and consent, logs in on the web guard as the chosen user (the admin stays signed in on the `admin` guard), stores the session, logs `impersonation.started`, notifies the owner, redirects to `to`.
- `StopImpersonation`: logs out of the web guard only, logs `impersonation.ended` with duration, redirects to the stored return URL.
- `EndExpiredImpersonation`: runs from the middleware when the 30 minutes have passed.

## Routes

| Method | URL                                         | Name                             | Purpose                                   |
| ------ | ------------------------------------------- | -------------------------------- | ----------------------------------------- |
| GET    | `/admin/properties/{property:code}/view-as` | `admin.properties.view-as`       | View-as page (reads `to`, `reason`)       |
| POST   | `/admin/properties/{property:code}/view-as` | `admin.properties.view-as.start` | Start the session, redirect to `to`       |
| POST   | `/impersonation/stop`                       | `impersonation.stop`             | End the session, return to the admin page |

## Data

- `property_settings.allow_support_access` (bool, default true).
- No new table. Sessions are recorded in the activity log with events `impersonation.started` and `impersonation.ended` (admin, user, property, reason, note, duration).
- Notification type `SupportAccessUsed` (see [Notification types](../04-notifications/01-notification-types.md)).
- Admin permission `admin.impersonate` (admin guard, not team-scoped).

## UI

- View-as page: a small full-screen page in the admin layout with the form above. It is a page (not a Dialog) because it must work from a pasted URL.
- Banner: shared Inertia prop `impersonation` (admin name, user, property, minutes left, stop URL). Uses the `warning` status token.
- Blocked actions are hidden using the same prop.

## Build choice

Custom, about four small classes. `lab404/laravel-impersonate` (v1.7.8 supports Laravel 13) was considered. It saves little, because consent, reasons, blocking, the URL interstitial and auditing still need writing.

## Tests

- Admin opening a staff URL is redirected to the view-as page with the correct `to`.
- Starting redirects to `to` and shows the banner prop.
- Blocked routes return an error during impersonation.
- Consent off prevents starting.
- Switching properties ends the old session and starts a new one.
- A non-GET request from an admin without a session returns 403.
- An external `to` URL is ignored.
- Writes during impersonation record the admin as causer.
- The session ends after 30 minutes.
