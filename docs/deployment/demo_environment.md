# Demo environment

A second copy of the app at `demo.estatell.com`, reset every night. Prospects and owners try the product there. What it shows is in [Demo properties](../01-platform/06-demo-properties.md). For the live app, see [Production environment](production_environment.md).

## Decision

Decided on 2026-10-18. It replaces the plan for demo properties inside production.

| Reason                         | Detail                                                                                                                                        |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| The ledger can't be deleted    | `folio_lines_immutable` and `payments_immutable` block `DELETE`. Dropping the whole database avoids a ledger exception.                       |
| No purge list                  | A row-by-row rebuild needs a delete order across every `property_id` table. That breaks when a module adds a table, as the wastays reset did. |
| No demo code in production     | No `is_demo` flags and no guards on mail, channels, night audit or admin lists.                                                               |
| Visitors can't reach real data | It is a separate database. A wrecked demo comes back at the next reset.                                                                       |
| Small cost                     | One container, about 0.3–0.5 GB of RAM (estimate, not measured). It shares the Postgres server.                                               |

## Stack

`compose.demo.yaml` in Coolify, as its own app. It builds the same `Dockerfile` from `main`. Give the domain to `web` on port 8080.

| Service | Command               | Notes                                                                 |
| ------- | --------------------- | --------------------------------------------------------------------- |
| `web`   | FrankenPHP on `:8080` | Runs migrations on deploy (`AUTORUN_ENABLED=true`). The only service. |

| Production                       | Demo                                        | Why                                                                             |
| -------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------- |
| `web`, `horizon`, `scheduler`    | `web` only                                  | No automatic night audits, so the business date stays on today until the reset. |
| `APP_ENV=production`             | `APP_ENV=demo`                              | Production blocks `migrate:fresh`. The demo must allow it.                      |
| Redis for queue, cache, sessions | `sync` queue, `database` cache and sessions | No Redis. The reset wipes sessions and cache with the tables.                   |
| Real mailer                      | `MAIL_MAILER=log`                           | Nothing leaves the server. No SMS keys.                                         |
| R2 or the `media` volume         | The `demo-media` volume                     | The reset clears it.                                                            |
| Own `APP_KEY`, `CIPHERSWEET_KEY` | Own `APP_KEY`, `CIPHERSWEET_KEY`            | Never reuse the production keys.                                                |

None of the production scheduled tasks are needed: the reset clears ID scans, the demo has no onboarding owners, and Horizon is not running.

With the `sync` queue, a manual night audit, PDFs and exports run inside the visitor's request. To verify: how long a manual night audit takes. If it is too slow, add the `horizon` service and Redis back.

## Database

- `estatell_demo` on the production Postgres server, with the `estatell_demo` user. That user has rights only on `estatell_demo`, so a reset can't touch production.
- `btree_gist` must be available, as in production.

## Reset

A Coolify Scheduled Task on `web`, daily at 03:00 Asia/Kuala_Lumpur:

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

- To reset by hand, click **Run** on the scheduled task in Coolify.
- A deploy runs a normal `migrate` on the current data. Seeder changes show at the next reset.
- Everyone is signed out at each reset.

## Rules

- `DemoSeeder` is never called from `DatabaseSeeder`, so it can't run in production.
- A Pest test runs `DemoSeeder`. A changed Action signature fails the test, not the nightly reset.
