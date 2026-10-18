# Production environment

The live app at `app.estatell.com`. The marketing site is a separate site on another domain. For the demo copy, see [Demo environment](demo_environment.md).

## Stack

Deployed with Coolify (Docker Compose build pack): `compose.yaml` builds the `Dockerfile` (`serversideup/php` FrankenPHP). PostgreSQL and Redis are external. Set env vars in the Coolify UI; give the domain to `web` on port 8080. Do not publish ports.

| Service     | Command                 | Notes                                              |
| ----------- | ----------------------- | -------------------------------------------------- |
| `web`       | FrankenPHP on `:8080`   | Runs migrations on start (`AUTORUN_ENABLED=true`). |
| `horizon`   | `artisan horizon`       | Queue workers. Dashboard at `/horizon`.            |
| `scheduler` | `artisan schedule:work` | Runs the scheduled tasks below.                    |

`APP_ENV=production` turns on `DB::prohibitDestructiveCommands` (`AppServiceProvider::configureDefaults`), so `migrate:fresh`, `db:wipe` and similar commands refuse to run.

## Scheduled tasks

From `routes/console.php`.

| Task                             | When           | Does                                       |
| -------------------------------- | -------------- | ------------------------------------------ |
| `horizon:snapshot`               | Every 5 min    | Horizon metrics                            |
| `purge-expired-identity-scans`   | Daily at 03:30 | Deletes ID scans past their retention      |
| `night-audit:dispatch-scheduled` | Every 5 min    | Starts automatic night audits that are due |
| `onboarding:send-reminders`      | Hourly         | Reminds stalled owners                     |

## Environment variables that need care

| Variable                                                               | Note                                                                                                            |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `CIPHERSWEET_KEY`                                                      | Encrypts ID numbers. Generate once with `php artisan ciphersweet:generate-key --show`. Never change or lose it. |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` | Optional. Without all four, media can only be stored on the server.                                             |
| `SUPPORT_REQUIRE_2FA`                                                  | Keep `true` in production. See [Platform admins](../01-platform/01-platform-admins.md).                         |
| `DEMO_URL`, `DEMO_PASSWORD`                                            | Shown on the partner **Demo access** card. Must match the demo environment.                                     |

The `media` volume holds `storage/app/private` (media stored on "This server"). It is shared by all three services. Back it up.

## Postgres

- The `btree_gist` extension must be available. The day use migration enables it (`Schema::ensureExtensionExists`). Check once: `select * from pg_available_extensions where name = 'btree_gist';`. No row means the Postgres install lacks the contrib package, and the `web` container fails on start.
- The demo database `estatell_demo` lives on the same server, with its own user. See [Demo environment](demo_environment.md#database).

## Traffic

Cloudflare → Coolify proxy (Traefik) → `web`. `TRUSTED_PROXIES` (see `config/trustedproxy.php`) sets which hops Laravel trusts for the client IP. Production uses `*` because only Traefik can reach the app. `PRIVATE_SUBNETS,CLOUDFLARE` is the tighter option.

Coolify's Traefik does not trust forwarded headers by default, so it replaces `X-Forwarded-For` with the Cloudflare edge IP. In Coolify → Servers → Proxy → Configuration, add to the Traefik `command` list (comma-separated Cloudflare ranges from `config/trustedproxy.php`):

```yaml
- '--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20,...'
- '--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20,...'
```

Coolify keeps these custom commands when it regenerates the proxy config.

## Open

- To verify: `compose.yaml` passes no `MAIL_*`, `SUPPORT_REQUIRE_2FA` or `ADMIN_SEED_*` variables to the containers. If Coolify only passes variables that the compose file names, production mail falls back to `log` (`config/mail.php`).
