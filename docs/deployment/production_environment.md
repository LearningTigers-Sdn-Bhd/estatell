# Production environment

The live app at `app.estatell.com`. The marketing site is a separate site on another domain. For the demo copy, see [Demo environment](demo_environment.md).

## Stack

Deployed with Coolify (Docker Compose build pack): `compose.yaml` builds the `Dockerfile` (`serversideup/php` FrankenPHP). PostgreSQL and Redis are external. Set env vars in the Coolify UI; give the domain to `web` on port 8080. Do not publish ports.

The shared PHP base stage installs `exif` for Spatie Media Library and `intl` for country names (`ResourceBundle` and `Locale`). Both the build and runtime stages inherit these extensions.

| Service     | Command                     | Notes                                                                          |
| ----------- | --------------------------- | ------------------------------------------------------------------------------ |
| `web`       | FrankenPHP on `:8080`       | Runs migrations on start (`AUTORUN_ENABLED=true`).                             |
| `horizon`   | `artisan horizon`           | Queue workers. Dashboard at `/horizon`.                                        |
| `scheduler` | `artisan schedule:work`     | Runs the scheduled tasks below.                                                |
| `ssr`       | `node bootstrap/ssr/app.js` | Renders the public pages to HTML. See [Search visibility](#search-visibility). |

`APP_ENV=production` turns on `DB::prohibitDestructiveCommands` (`AppServiceProvider::configureDefaults`), so `migrate:fresh`, `db:wipe` and similar commands refuse to run.

## Amenity list

Deploy first, so the amenity migrations run. Then run this command once in the production `web` container:

```sh
php artisan db:seed --class=AmenitySeeder --force --no-interaction
```

- Data: the shared list from `database/seeders/data/amenities.json`. See [Amenities](../29-booking-engine/06-content-registries.md#amenities).
- It is safe to run again. Existing amenities keep the changes admins made, and no row is removed.
- An amenity an admin renamed comes back under its old name if the command runs again. Retire the extra row if this happens.
- Do not run plain `db:seed` in production. `DatabaseSeeder` also creates a `test@example.com` user.

## Search visibility

The public pages (home, `/services/*`, terms, privacy) must reach crawlers and AI answer engines as finished HTML. Most of them do not run JavaScript, so a browser-only render shows them an empty page.

| Part                        | What it does                                                                                                                                                                                                   |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ssr` service               | Runs the Inertia server-render bundle (`bootstrap/ssr`, built by `npm run build:ssr`). The bundle holds its own dependencies, so the image only adds the Node binary. It needs no secrets, database or volume. |
| `INERTIA_SSR_URL`           | Set on `web`. Default `http://ssr:13714`. Leave it as is.                                                                                                                                                      |
| `INERTIA_SSR_ENABLED`       | Default `true`. The demo sets `false` because it has no `ssr` service.                                                                                                                                         |
| Which pages                 | Only the public pages are server-rendered (`AppServiceProvider::configureInertiaSsr`). The staff app is behind a login and is not.                                                                             |
| If `ssr` is down            | Pages still work. Laravel falls back to the browser render, and crawlers lose the benefit until `ssr` is back. Watch the service health in Coolify.                                                            |
| `/robots.txt`               | Built per request. Production allows the public pages and names the sitemap. Every other `APP_ENV` (demo, staging) blocks all crawlers.                                                                        |
| `/sitemap.xml`, `/llms.txt` | Built from `resources/js/components/landing/services.json` and `APP_URL`. Adding a service there adds it to both.                                                                                              |

`APP_URL` must be the domain that serves the public pages, with `https`. Canonical links, Open Graph URLs, the sitemap and `llms.txt` all come from it, never from the request host.

To check after a deploy, read the raw HTML without JavaScript and look for the page heading and the `application/ld+json` block:

```sh
curl -s https://<domain>/services/hotel-pms | grep -c '<h1'
```

## Boreno Hotel for internal use

Boreno can also exist in production as a normal property with the full sample data. It has no internal flag or schema changes. The public demo remains separate.

Set `BORENO_SEED_PASSWORD` as a runtime variable in Coolify, then redeploy. Use a strong password, different from `DEMO_PASSWORD`. All four Boreno staff accounts use this password initially.

Run this command once in the production `web` container:

```sh
php artisan db:seed --class=BorenoProductionSeeder --force --no-interaction
```

- Owner: `owner@boreno.com`.
- Other staff: `manager@boreno.com`, `frontdesk@boreno.com`, `housekeeping@boreno.com`.
- Data: 30 rooms, rates, taxes, guests, 18 reservations, payments and city ledger invoices.
- The seeder uses one transaction. A failed step leaves no partial property.
- If a property named Boreno Hotel already exists, the seeder skips it without changing data or passwords.
- The seeder does not reset production. Never use `migrate:fresh` for this property.

This property has normal production behavior. Demo account restrictions and the nightly demo reset do not apply. No mail, jobs or reports exclude it automatically.

## Scheduled tasks

From `routes/console.php`.

| Task                             | When           | Does                                       |
| -------------------------------- | -------------- | ------------------------------------------ |
| `horizon:snapshot`               | Every 5 min    | Horizon metrics                            |
| `purge-expired-identity-scans`   | Daily at 03:30 | Deletes ID scans past their retention      |
| `night-audit:dispatch-scheduled` | Every 5 min    | Starts automatic night audits that are due |
| `onboarding:send-reminders`      | Hourly         | Reminds stalled owners                     |

## Environment variables that need care

| Variable                                                               | Note                                                                                                                                                                                                                                                                |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CIPHERSWEET_KEY`                                                      | Encrypts ID numbers. Generate once with `php artisan ciphersweet:generate-key --show`. Never change or lose it.                                                                                                                                                     |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` | Optional. Without all four, media can only be stored on the server.                                                                                                                                                                                                 |
| `MEDIA_PUBLIC_DISK`, `R2_PUBLIC_BUCKET`, `R2_PUBLIC_URL`               | Images that guests see. Set `MEDIA_PUBLIC_DISK=r2-public`, a public bucket and its custom domain. Without them, these images stay on the `public` disk. See [Room photos](../29-booking-engine/06-content-registries.md#room-photos).                               |
| `SUPPORT_REQUIRE_2FA`                                                  | Keep `true` in production. See [Platform admins](../01-platform/01-platform-admins.md).                                                                                                                                                                             |
| `DEMO_URL`, `DEMO_PASSWORD`                                            | Shown on the partner **Demo access** card. Must match the demo environment.                                                                                                                                                                                         |
| `CURLEC_KEY_ID`, `CURLEC_KEY_SECRET`, `CURLEC_WEBHOOK_SECRET`          | Curlec **live** keys only. Without them, online payment answers `payment_unavailable`. In Curlec's live dashboard, add the webhook `https://app.estatell.com/webhooks/curlec` with the same secret. See [Payment](../29-booking-engine/04-payment.md#environments). |
| `BOOKING_SITE_URL`, `BOOKING_API_DOCS_URL`                             | Optional. Default to `https://booking.estatell.com` and the developer guide on `docs.estatell.com`.                                                                                                                                                                 |

The `media` volume holds `storage/app/private` (media stored on "This server"). It is shared by all three services. Back it up.

## Booking microsite token

The microsite app reaches the API with one token. Run this once in the `web` container, and put the printed token in the microsite app's environment in Coolify. It is shown once.

```sh
php artisan booking-engine:create-microsite-client
```

If the token is lost or leaked, replace it. The old token stops at once, so the microsite fails until its environment has the new token and it restarts. Holds and bookings stay reachable, because the client stays the same.

```sh
php artisan booking-engine:create-microsite-client --replace
```

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

- `compose.yaml` passes `MAIL_MAILER`, `MAIL_FROM_*`, `MILLIONSEND_BASE_URL`, `MILLIONSEND_API_KEY` and `MILLIONSEND_WEBHOOK_SECRET`. Set them in Coolify. Without `MAIL_MAILER=millionsend` mail falls back to `log` (`config/mail.php`). See [Sending mail in production](../04-notifications/01-notification-types.md#sending-mail-in-production).
