# Handoff: Tanini build and demo (2026-11-24)

Context for the next agent run. Read this first, then only the docs the task needs.

## Where things stand

| Item            | State                                                                                                                                                                                           |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Branch          | `feat/dorm-beds-on-channels`, 27 commits ahead of `main`, **not pushed, no PR**                                                                                                                 |
| Full suite      | 8373 / 8373 passed on `e0b2fec5` (`php artisan test --compact`, about 1 hour)                                                                                                                   |
| Next step       | The owner demos to Tanini soon. Push and open a PR only when the user asks                                                                                                                      |
| Feature tracker | `../docs/estatell/tanini/2026-11-21-tanini-milestones.md` (outside the repo, not in git). Synced 2026-11-24: features 1, 2, 4 to 11 and both small reports built, waiting for the owner's check |
| Requirements    | `../docs/estatell/tanini/2026-11-18-tanini-system-requirements-plan.md`                                                                                                                         |

## Built on this branch

| Feature                                                        | Doc                                            |
| -------------------------------------------------------------- | ---------------------------------------------- |
| Sales person, market segments, remarks, arrival report columns | `docs/12-reservations/12-sales-person.md`      |
| History & forecast, Rooms on books                             | `docs/22-reports/04-history-and-forecast.md`   |
| Multi-property access and group mode (`/g/{propertyGroup}`)    | `docs/03-staff/05-group-mode.md`               |
| Dorm beds to channels as one unit per bed (BedAsUnit)          | `docs/21-channels/`                            |
| Bed-level housekeeping                                         | `docs/19-housekeeping/`                        |
| Private facility charters, whole-dorm buyout                   | `docs/33-facilities/`, `docs/12-reservations/` |
| Land transfers (own module, built like Boats)                  | `docs/34-transfers/01-land-transfers.md`       |

### Group mode, latest round

- **Dashboard:** KPIs against last year, occupancy outlook, revenue split, "Today by property", and alerts.
- **Pages:** Reservations, Timeline (Month by default, compact), Forecast (3/6/12/18/24 months or custom, up to 24), Performance, and property reports by category.
- **Exports:** an Export menu (CSV and landscape PDF) on every group table: `GroupTableExport`, `resources/views/pdf/group-table.blade.php`.
- **Headers:** no page header on group pages; the breadcrumb names the page.

### Dropped or decided

- Group-size price tiers were dropped: Tanini never asked for them.
- The status text stays on the compact stay bars, because colour never carries meaning alone. Hiding it is an open option.

## Demo data (local DB `estatell_sandbay`, port 5433)

| Seeder                 | What it does                                                                                                                                                            |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DemoYearSeeder`       | A year of seasonal bookings and history for Boreno Hotel (ES1101) and Kopi Lane Hostel (ES1107). Rates extended to 400 days. Re-runs fill gaps only. Refuses production |
| `DemoOperationsSeeder` | Features on, segments, companies and agent bookings, transfers (routes, vans, drivers, flights), tours, Kopi boats, dorm buyouts and each-pays groups, extras           |

- **Sign in:** Adrian Wong (`kopilane@demo.estatell.com`, Kopi Owner + Boreno Manager) and Melissa Tan (`kopilane.manager@demo.estatell.com`) see both properties. Linda Chin owns Boreno only, so she sees no group.
- **Password:** in `.env` as `DEMO_PASSWORD`. The user signs in themselves; never type it.
- **OTP:** `email_otps` stores a hash only. Read the code from the `mailbox` connection, table `mailbox_messages` (subject "NNNNNN is your estatell sign-in code").
- **Mailbox page:** `/mailbox` returns a 500 because `public/vendor/mailbox/manifest.json` is missing. It is not fixed; ask before publishing the package assets.
- **Backups:** made before the seeders ran, in a temp scratchpad (`before-demo-year.dump`, `before-demo-operations.dump`); they may be gone. Use `/opt/homebrew/opt/postgresql@18/bin/pg_dump` because the server is 18.4.

## Open items

1. Push the branch and open the PR when asked.
2. Get the owner's check on each built feature, then mark each Done in the tracker.
3. Transfers, not yet built: a booking-form field, the company portal, and a printed run sheet.
4. Feature 3 (non-guest / event folio) is built by a teammate. Tanini question D (event tax, e-invoicing) is still open.
5. Optional suggestions, not requested:
    - Pace against last year; budgets; channel mix.
    - Scheduled email reports.
    - A group transfers run sheet.
    - Staff access across properties.
    - Last-year folios for revenue comparisons.
    - Hiding the status text on compact stay bars.

## Traps

- **One test run at a time:** two Pest processes deadlock on the test DB. Do not edit files while the full suite runs; it reads half-edited files.
- **Build first:** run `npm run build` before tests that render new pages, or they fail on a missing Vite manifest.
- **Seeder schema:**
    - Charge item codes live on `transaction_codes.code`, not `charge_items`.
    - Beds use `archived_at`, not `active`.
- **Client text:** never name Channex; say "Channel Manager".
- **Real client properties:** do not touch Y Hotel, Sand Bay, E'Ferringhi or Summer Holiday.
- **Ask first** before adding dependencies, creating new top-level folders, changing a recorded decision, or doing anything destructive.
- **Commit format:** `type(scope): summary`, a plain-English body, then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
