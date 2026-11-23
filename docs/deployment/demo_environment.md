# Demo environment

A second copy of the app at `demo.estatell.com`. Prospects and owners try the product there. Its data is kept between days, and the night audit runs by itself. What it shows is in [Demo properties](../01-platform/06-demo-properties.md). For the live app, see [Production environment](production_environment.md).

## Decision

Decided on 2026-10-18. It replaces the plan for demo properties inside production.

| Reason                         | Detail                                                                                                                                        |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| The ledger can't be deleted    | `folio_lines_immutable` and `payments_immutable` block `DELETE`. Dropping the whole database avoids a ledger exception.                       |
| No purge list                  | A row-by-row rebuild needs a delete order across every `property_id` table. That breaks when a module adds a table, as the wastays reset did. |
| No demo code in production     | No `is_demo` flags and no guards on mail, channels, night audit or admin lists.                                                               |
| Visitors can't reach real data | It is a separate database. A wrecked demo comes back with a [manual reset](#reset).                                                           |
| Small cost                     | Three containers from one image. It shares the Postgres server.                                                                               |

### No nightly reset

Changed on 2026-11-23. The demo used to reset every night at 03:00. Demo users set up their own rate codes and room types and lost them the next day. Now the data stays, and the night audit closes each business date by itself.

| Effect                      | Detail                                                                                                     |
| --------------------------- | ---------------------------------------------------------------------------------------------------------- |
| The business date moves     | The `scheduler` service runs `night-audit:dispatch-scheduled`, as in production.                           |
| Seeded bookings age         | Dates are relative to the last reset. Over weeks, fewer upcoming arrivals remain unless visitors add them. |
| What visitors enter stays   | The banner says "Don't enter real guest details". ID scans are purged by the scheduled task.               |
| A reset is a deliberate act | Someone runs the command by hand. It wipes every visitor's changes.                                        |

## Stack

`compose.demo.yaml` in Coolify, as its own app. It builds the same `Dockerfile` from `main`. Give the domain to `web` on port 8080.

| Service     | Command                                                                   | Notes                                                            |
| ----------- | ------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `web`       | FrankenPHP on `:8080`                                                     | Runs migrations on deploy (`AUTORUN_ENABLED=true`).              |
| `worker`    | `queue:work --queue=default,loyverse --tries=1 --timeout=60 --memory=128` | The same queues and limits as the production Horizon supervisor. |
| `scheduler` | `schedule:work`                                                           | Every task in `routes/console.php`, as in production.            |

| Production                       | Demo                                            | Why                                                                                                                      |
| -------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `web`, `horizon`, `scheduler`    | `web`, `worker`, `scheduler`                    | A plain worker on the `jobs` table instead of Horizon, so no Redis.                                                      |
| `APP_ENV=production`             | `APP_ENV=demo`                                  | Production blocks `migrate:fresh`. The demo must allow it.                                                               |
| Redis for queue, cache, sessions | `database` queue, cache and sessions            | No Redis.                                                                                                                |
| Real mailer                      | `MAIL_MAILER=log`                               | Nothing leaves the server. No SMS keys.                                                                                  |
| Curlec live keys                 | Curlec test keys, `DEMO_BOOKING_API_TOKEN`      | Developers test the booking API with fake payments. See [Test mode](../29-booking-engine/09-api-only-mode.md#test-mode). |
| R2 or the `media` volume         | The `demo-media` volume, shared by all services | A manual reset clears it.                                                                                                |
| Own `APP_KEY`, `CIPHERSWEET_KEY` | Own `APP_KEY`, `CIPHERSWEET_KEY`                | Never reuse the production keys.                                                                                         |

Scheduled tasks on the demo:

- All production tasks run. Reminders go to the mail log. The Channex sync finds no connected property, so it does nothing.
- `horizon:snapshot` is skipped when `APP_ENV=demo`, because there is no Redis.
- The automatic audit runs only for properties with **Automatic audit** on in their settings. `DemoSeeder` does not turn it on, so turn it on by hand after a reset.

## Database

- `estatell_demo` on the production Postgres server, with the `estatell_demo` user. That user has rights only on `estatell_demo`, so a reset can't touch production.
- `btree_gist` must be available, as in production.

## Reset

Manual only. There is no scheduled task (removed 2026-11-23). Run this in the Coolify terminal of the `web` container:

```bash
php artisan down && rm -rf storage/app/private/* && php artisan migrate:fresh --force --seeder=DemoSeeder && php artisan up
```

| Step                           | Does                                                                                               |
| ------------------------------ | -------------------------------------------------------------------------------------------------- |
| `down`                         | Visitors see the maintenance page.                                                                 |
| `rm -rf storage/app/private/*` | Deletes uploads (ID scans, signatures, photos). `migrate:fresh` does not touch files.              |
| `migrate:fresh`                | Drops every table, including the ledger triggers, and runs every migration.                        |
| `--seeder=DemoSeeder`          | Builds the demo data. See [Demo properties](../01-platform/06-demo-properties.md#data).            |
| `up`                           | Opens the demo again. If a step fails, the chain stops and the demo stays on the maintenance page. |

- Reset only when the boss or sales asks. It wipes every visitor's rate codes, room types and bookings.
- Maintenance mode uses the `file` driver, so only `web` sees it. While the tables are rebuilt, `worker` and `scheduler` may log errors for a few seconds. They carry on once the tables exist (expected, to verify at the first reset).
- After a reset, turn **Automatic audit** on again for each demo property.
- A deploy runs a normal `migrate` on the current data. Seeder changes show only after a reset.
- Everyone is signed out at each reset.

## Rules

- `DemoSeeder` is never called from `DatabaseSeeder`, so it can't run in production.
- A Pest test runs `DemoSeeder` (`tests/Feature/Seeders/DemoSeederTest.php`). A changed Action signature fails the test, not the reset.
