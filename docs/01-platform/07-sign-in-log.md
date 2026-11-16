# Sign-in log

## Purpose

Answer "who signed in, from where, on which network and device, and are they still signed in?" and spot password guessing. Every sign-in attempt on the web login leaves one row. A successful sign-in's row also follows that browser session until it signs out or expires. Only platform admins can see the log.

## Rows

Table `sign_in_events`. One row per attempt.

| Column           | Meaning                                                                                                    |
| ---------------- | ---------------------------------------------------------------------------------------------------------- |
| `user_id`        | The account. Empty when the typed email has no account. Set to empty if the user is deleted.               |
| `result`         | `SignInResult`: `succeeded`, `failed`, `two_factor_failed`, `locked_out`.                                  |
| `ip_address`     | The client IP (`$request->ip()`). Encrypted (CipherSweet) with an exact-match blind index.                 |
| `country_code`   | Cloudflare's `CF-IPCountry` header. Empty when Cloudflare sends `XX` (unknown) or `T1` (Tor), or nothing.  |
| `city`           | Cloudflare's `cf-ipcity` header, up to 80 characters. Empty when missing.                                  |
| `region`         | Cloudflare's `cf-region` header (the state, for example `Sabah`), up to 80 characters. Empty when missing. |
| `asn`            | The network number from the `X-Client-ASN` header. Empty unless it is a positive whole number.             |
| `os`             | Operating system from the user agent, for example `Windows`, `iOS`, `Android`. Empty when unknown.         |
| `browser`        | Browser from the user agent, for example `Chrome`, `Safari`, `Edge`. Empty when unknown.                   |
| `occurred_at`    | When the attempt happened (UTC).                                                                           |
| `last_active_at` | Successful sign-ins only: the last request from that browser session (UTC). Empty for failed attempts.     |
| `signed_out_at`  | When that session signed out (UTC). Empty while it is open or after it expired.                            |

Not stored: the typed email, the password and the full user agent. No GeoIP database is used; city, region and network come from Cloudflare.

## How rows are written

`AppServiceProvider::configureSignInLog()` listens to auth events and calls the `RecordSignIn` Action.

| Event                                   | Result              | Account                                       |
| --------------------------------------- | ------------------- | --------------------------------------------- |
| `Login` on the Fortify guard            | `succeeded`         | The signed-in user                            |
| `Failed` on the Fortify guard           | `failed`            | The user whose email was typed, if one exists |
| Fortify `TwoFactorAuthenticationFailed` | `two_factor_failed` | The user in the challenge                     |
| `Lockout`                               | `locked_out`        | The user whose email was typed, if one exists |

- `Login` also fires for a "remember me" cookie, an accepted invitation and the owner setup link. Each counts as a sign-in.
- The app's `authenticateUsing` returns null on a wrong password, so Fortify's `Failed` event has no user. The listener finds the account by the typed email instead.
- A deactivated, suspended or expired account is refused with a validation message before any event, so it leaves no row.
- Login is throttled by the `login` rate limiter (5 a minute per email and IP) as route middleware, so Fortify never fires `Lockout` itself. The limiter's response callback fires it, then throws the same 429 as before. Each blocked request leaves one `locked_out` row.
- Support access (view as) switches the user with `Auth::login`. `StartImpersonation` and `StopImpersonation` wrap that call in `Context::scope` with `RecordSignIn::SUPPORT_SWITCH_CONTEXT_KEY`, and `RecordSignIn` skips it. Support sessions are in the activity log (see [Impersonation](04-impersonation.md)).

### Client IP, location and network

Production runs Cloudflare → Traefik → app. The IP is right only when the proxies are trusted (see [Production environment](../deployment/production_environment.md)). The location and network headers are trusted only because the app cannot be reached without Cloudflare, so keep Traefik's port unpublished. Without Cloudflare in front, for example locally, these columns stay empty.

Cloudflare settings on `estatell.com` (Free plan):

| Setting                                                      | Sends                                                       |
| ------------------------------------------------------------ | ----------------------------------------------------------- |
| Network → IP Geolocation                                     | `CF-IPCountry`                                              |
| Rules → Managed Transforms → Add visitor location headers    | `cf-ipcity`, `cf-region` and other location headers         |
| Request Header Transform Rule "Add client ASN", all requests | `X-Client-ASN` set dynamically to `to_string(ip.src.asnum)` |

The location is an estimate from the IP address, not GPS. Fibre lines (Unifi, TIME) usually show the right city. Mobile networks share addresses across a wide area, so a phone often shows Kuala Lumpur or Selangor wherever it is.

`NetworkNames` names the main Malaysian networks by ASN: 4788 TM / Unifi, 9534 Maxis, 10030 Celcom, 4818 Digi, 38466 U Mobile, 9930 TIME. Any other number shows as `AS{number}`. TM home (Unifi) and TM business lines share AS4788, so they cannot be told apart.

### Session activity

- A successful sign-in sets `last_active_at` and puts the row id in the session (`SignInEvent::SESSION_KEY`).
- The `TrackSignInActivity` web middleware calls `TouchSignInSession`, which moves `last_active_at` forward at most once a minute. Inertia partial reloads (`X-Inertia-Partial-Data`) do not count, because background polls such as the business date would make an unattended screen look active.
- `Logout` on the Fortify guard calls `RecordSignOut`, which sets `signed_out_at`. This covers the sign-out button and `LogoutDeactivatedUsers`.
- Support access keeps the admin's session, so the admin's row stays active while they view as someone else.

`SignInSessionStatus` works out the status when the page loads:

| Status          | Rule                                                                      |
| --------------- | ------------------------------------------------------------------------- |
| Active          | Last active within 5 minutes                                              |
| Idle            | Last active within the session lifetime (`SESSION_LIFETIME`, 120 minutes) |
| Signed out      | `signed_out_at` is set                                                    |
| Session expired | Anything older                                                            |

A closed browser tab does not sign out, so its session shows Idle and then Session expired.

### Device names

`DeviceDescriber` matches the user agent against two short ordered lists. The first match wins. Browsers built on Chrome (Edge, Opera, Samsung Internet) are listed before Chrome, and Chrome before Safari. An iPad on iPadOS 13 or later reports itself as a Mac, so it shows as `macOS`.

## Admin page

`/admin/sign-in-log` (`admin.sign-in-events.index`), platform admins only (`can:admin`). It is read-only, so it has no `support.2fa` middleware.

- Counts in the header, a result filter, a property filter (the account's property), a date range, sorting by time and pagination, all in the URL.
- Search by an exact IP address (blind index), an exact account email, or part of the account name.
- Times show in Malaysia time (MYT, `SignInLogDirectory::TIME_ZONE`), and the date range filter reads whole Malaysian days.
- Columns: time (MYT), account (name, email and property, partner or "Platform admin"), result, session (status and last active), IP address, location (city, region and country), network, device (OS and browser).
- The browser turns the country code into a name with `Intl.DisplayNames`.
- The sidebar item is "Sign-in log".

## Retention

`PruneSignInEvents` deletes rows older than 90 days (`SignInEvent::RETENTION_DAYS`). It runs daily at 03:40 from `routes/console.php`, on one server. Running it twice deletes nothing more. The encrypted IP and its blind index rows go with the row.

## Privacy (PDPA)

The log holds personal data (an IP address linked to an account), so it follows the Personal Data Protection Act 2010 principles:

| Principle          | How the log meets it                                                                                                                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Purpose            | Account security and support: spotting unknown devices and password guessing, and seeing who is signed in, from where, and whether a session is still open. It is not used to measure staff work.       |
| Data minimisation  | Only result, IP, country, city, region, network, OS, browser and session times. No typed email, password or full user agent. Activity is kept as one last-active time per session, not a list of pages. |
| Security           | The IP is encrypted at rest. Only platform admins can open the page.                                                                                                                                    |
| Retention          | Deleted after 90 days.                                                                                                                                                                                  |
| Notice             | The privacy policy (`/privacy`) says IP, browser and device details are processed to protect against misuse.                                                                                            |
| Access and erasure | Deleting a user empties `user_id`, and the rest of the row goes within 90 days.                                                                                                                         |

## Before this log

Sign-ins from before this log were not recorded in the app. Cloudflare analytics for `estatell.com` keep about 30 days of requests. Filter by path `/login` and method `POST` to see IP, country and user agent, but not the account.
