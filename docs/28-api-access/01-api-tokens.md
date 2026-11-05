# API tokens

## Purpose

One credential layer for everything that calls Estatell without a staff session. It uses Laravel Sanctum API tokens. Staff pages stay Inertia and use the session. [Kiosks](02-kiosks.md) are the first token owner. The [booking engine microsite](04-booking-engine-microsite.md) is planned as the next.

## Decision

Sanctum replaced a custom `api_tokens` table on 2026-11-04, so one token system serves kiosks and the planned `/api/v1`. The user approved the package on 2026-11-03.

## Setup

Tokens live in Sanctum's `personal_access_tokens`. A kiosk owns its token through `HasApiTokens` and its contract. It stays a plain Eloquent model because the current API only needs token ownership, not Laravel's full authenticated-user contract. This is a local integration choice, not a Sanctum requirement or a security boundary. The separate guards and authorization checks keep kiosk access apart from staff access.

| `config/sanctum.php` | Value       | Why                                                                      |
| -------------------- | ----------- | ------------------------------------------------------------------------ |
| `guard`              | `[]`        | A staff session never stands in for a token                              |
| `token_prefix`       | `est_live_` | Secret scanners can spot a leaked token                                  |
| `routes`             | `false`     | No `/sanctum/csrf-cookie`, because only tokens are used                  |
| `last_used_at`       | `false`     | Sanctum would write on every request. The owner records it once a minute |

New tokens look like `12|est_live_` plus 40 random characters and a checksum. Clients must treat a token as an opaque string. Only the SHA-256 hash is stored. A token without the `id|` part is found by its hash, so the tokens moved from `api_tokens` keep working.

## Requests

| Item        | Rule                                                                                                                                                                                                                                                     |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Middleware  | `api.token:{scope}` (`AuthenticateApiToken`): the Sanctum guard finds the token, then this class checks the owner, its property and ability                                                                                                              |
| Ability     | The route names the ability. A kiosk token has `kiosk:*`, so it never passes a `v1:*` route, and the reverse                                                                                                                                             |
| Property    | Read from the owner (`$request->attributes->get('kiosk')->property`). Never from the URL or body                                                                                                                                                         |
| Rate limits | `api-pair`: 10 per minute per IP. `api-token`: 120 per minute per kiosk. Walk-in quote and book also share 20 per minute per kiosk and 40 per IP (`api-walk-in`), after authentication. Failed token checks share 120 per minute per IP (`rate_limited`) |
| Format      | JSON, snake_case, Eloquent API Resources without the `data` wrapper                                                                                                                                                                                      |

## Traps

- **Keep `api.token:{scope}` on the current API routes.** This app's catch-all exception handler in `bootstrap/app.php` maps authentication exceptions to `500 server_error`. This is not Sanctum's default behavior. Laravel converts a missing-ability exception to HTTP 403, which also differs from the kiosk's `401 kiosk_unauthorized` contract. Switching to `auth:sanctum` or `abilities:` requires explicit exception mappings that preserve the kiosk error contract.
- **Do not call `id()` on the Sanctum guard for a kiosk.** `Kiosk` does not implement `Authenticatable`, so it throws. Use `user()`. If future callers need Laravel's full guard API, revisit that model contract with tests for staff and kiosk separation.
- **Always pass abilities to `createToken`.** The default is `['*']`, which passes every route.
- **Kiosk authentication does not sign in a staff user.** The middleware leaves the default `web` guard unchanged and puts the kiosk in request attributes. Sanctum's empty `guard` list prevents a staff session from substituting for a bearer token; staff routes still enforce their own authentication and authorization.
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
