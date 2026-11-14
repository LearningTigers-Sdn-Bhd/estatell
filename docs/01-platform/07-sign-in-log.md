# Sign-in log

## Purpose

Answer "who signed in, from where, and on what device?" and spot password guessing. Every sign-in attempt on the web login leaves one row. Only platform admins can see the log.

## Rows

Table `sign_in_events`. One row per attempt.

| Column         | Meaning                                                                                                   |
| -------------- | --------------------------------------------------------------------------------------------------------- |
| `user_id`      | The account. Empty when the typed email has no account. Set to empty if the user is deleted.              |
| `result`       | `SignInResult`: `succeeded`, `failed`, `two_factor_failed`, `locked_out`.                                 |
| `ip_address`   | The client IP (`$request->ip()`). Encrypted (CipherSweet) with an exact-match blind index.                |
| `country_code` | Cloudflare's `CF-IPCountry` header. Empty when Cloudflare sends `XX` (unknown) or `T1` (Tor), or nothing. |
| `os`           | Operating system from the user agent, for example `Windows`, `iOS`, `Android`. Empty when unknown.        |
| `browser`      | Browser from the user agent, for example `Chrome`, `Safari`, `Edge`. Empty when unknown.                  |
| `occurred_at`  | When the attempt happened (UTC).                                                                          |

Not stored: the typed email, the password and the full user agent. City-level location is not looked up, because it would need a GeoIP database.

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

### Client IP and country

Production runs Cloudflare → Traefik → app. The IP is right only when the proxies are trusted (see [Production environment](../deployment/production_environment.md)). The country comes from Cloudflare. Without Cloudflare in front, for example locally, it stays empty.

### Device names

`DeviceDescriber` matches the user agent against two short ordered lists. The first match wins. Browsers built on Chrome (Edge, Opera, Samsung Internet) are listed before Chrome, and Chrome before Safari. An iPad on iPadOS 13 or later reports itself as a Mac, so it shows as `macOS`.

## Admin page

`/admin/sign-in-log` (`admin.sign-in-events.index`), platform admins only (`can:admin`). It is read-only, so it has no `support.2fa` middleware.

- Counts in the header, a result filter, a property filter (the account's property), a date range, sorting by time and pagination, all in the URL.
- Search by an exact IP address (blind index), an exact account email, or part of the account name.
- Columns: time (UTC), account (name, email and property, partner or "Platform admin"), result, IP address, country, device (OS and browser).
- The browser turns the country code into a name with `Intl.DisplayNames`.
- The sidebar item is "Sign-in log".

## Retention

`PruneSignInEvents` deletes rows older than 90 days (`SignInEvent::RETENTION_DAYS`). It runs daily at 03:40 from `routes/console.php`, on one server. Running it twice deletes nothing more. The encrypted IP and its blind index rows go with the row.

## Privacy (PDPA)

The log holds personal data (an IP address linked to an account), so it follows the Personal Data Protection Act 2010 principles:

| Principle          | How the log meets it                                                                                         |
| ------------------ | ------------------------------------------------------------------------------------------------------------ |
| Purpose            | Account security only: spotting unknown devices and password guessing. It is not used for staff monitoring.  |
| Data minimisation  | Only result, IP, country, OS and browser. No typed email, password or full user agent.                       |
| Security           | The IP is encrypted at rest. Only platform admins can open the page.                                         |
| Retention          | Deleted after 90 days.                                                                                       |
| Notice             | The privacy policy (`/privacy`) says IP, browser and device details are processed to protect against misuse. |
| Access and erasure | Deleting a user empties `user_id`, and the rest of the row goes within 90 days.                              |

## Before this log

Sign-ins from before this log were not recorded in the app. Cloudflare analytics for `estatell.com` keep about 30 days of requests. Filter by path `/login` and method `POST` to see IP, country and user agent, but not the account.
