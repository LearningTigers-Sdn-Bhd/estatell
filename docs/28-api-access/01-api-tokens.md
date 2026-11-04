# API tokens

## Purpose

One credential layer for everything that calls Estatell without a staff session. It uses Laravel Sanctum API tokens. Staff pages stay Inertia and use the session. [Kiosks](02-kiosks.md) are the first token owner. The [booking engine microsite](04-booking-engine-microsite.md) is planned as the next.

## Decision

Sanctum replaced a custom `api_tokens` table on 2026-11-04, so one token system serves kiosks and the planned `/api/v1`. The user approved the package on 2026-11-03.

## Setup

Tokens live in Sanctum's `personal_access_tokens`. An owner is any model with `HasApiTokens`. Owners are not users.

| `config/sanctum.php` | Value       | Why                                                                      |
| -------------------- | ----------- | ------------------------------------------------------------------------ |
| `guard`              | `[]`        | A staff session never stands in for a token                              |
| `token_prefix`       | `est_live_` | Secret scanners can spot a leaked token                                  |
| `routes`             | `false`     | No `/sanctum/csrf-cookie`, because only tokens are used                  |
| `last_used_at`       | `false`     | Sanctum would write on every request. The owner records it once a minute |

New tokens look like `12|est_live_` plus 40 random characters and a checksum. Clients must treat a token as an opaque string. Only the SHA-256 hash is stored. A token without the `id|` part is found by its hash, so the tokens moved from `api_tokens` keep working.

## Requests

| Item        | Rule                                                                                                                                        |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Middleware  | `api.token:{scope}` (`AuthenticateApiToken`): the Sanctum guard finds the token, then this class checks the owner, its property and ability |
| Ability     | The route names the ability. A kiosk token has `kiosk:*`, so it never passes a `v1:*` route, and the reverse                                |
| Property    | Read from the owner (`$request->attributes->get('kiosk')->property`). Never from the URL or body                                            |
| Rate limits | `api-pair`: 10 per minute per IP. `api-token`: 120 per minute per kiosk                                                                     |
| Format      | JSON, snake_case, Eloquent API Resources without the `data` wrapper                                                                         |

## Traps

- **Do not use `auth:sanctum` or `abilities:` on `/api`.** Their exceptions reach the `/api/*` handler and answer `500 server_error`. A kiosk would then show "see the front desk" instead of "out of service".
- **Do not call `id()` on the Sanctum guard.** An owner is not `Authenticatable`, so it throws. Use `user()`.
- **Always pass abilities to `createToken`.** The default is `['*']`, which passes every route.
- **`$request->user()` is empty on `/api`.** The default guard is `web`, so an owner is never mistaken for staff.
- **The guard remembers its user** as long as the application lives. `AuthenticateApiToken` clears it for each request.

## Errors

Shape: `{ "error": { "code": "...", "message": "..." } }`. Rendered by `ApiError` and the exception handler for every `/api/*` path.

| Code                 | Status | When                                                                          |
| -------------------- | ------ | ----------------------------------------------------------------------------- |
| `kiosk_unauthorized` | 401    | Token or pairing code missing, wrong, revoked or expired, or wrong ability    |
| `unauthorized`       | 401    | Same as `kiosk_unauthorized` for non-kiosk scopes (future `/api/v1`)          |
| `rate_limited`       | 429    | A limit above. `Retry-After` is kept                                          |
| `server_error`       | 500    | Any exception that is not an HTTP or validation error. The message is generic |

## Code

| Piece                              | Where                                          |
| ---------------------------------- | ---------------------------------------------- |
| `AuthenticateApiToken`, `ApiError` | `app/Http/`                                    |
| Sanctum settings                   | `config/sanctum.php`                           |
| Rate limiters                      | `AppServiceProvider::configureApiRateLimiting` |
| Routes                             | `routes/api.php` (prefix `/api`, `api` group)  |
