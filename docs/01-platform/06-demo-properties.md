# Demo properties

## Purpose

Let owners and partners' prospects try the full product on realistic data, without a practice mode inside real properties.

## Decision

Test drive was removed from onboarding on 2026-10-16. Two demo properties replace it. On 2026-10-18 they moved out of production into a separate [demo environment](../deployment/demo_environment.md), reset every night.

| Reason                               | Detail                                                                                                                            |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| Owners want to go live, not rehearse | A practice step adds no setup data and makes the wizard longer.                                                                   |
| Practice mode was the riskiest item  | A practice business date, a route exception in the fence, `ClearPracticeData`, and every new module had to know about it.         |
| wastays failed at it                 | Its training reset job broke whenever a new module wrote data the job did not know.                                               |
| The go-live check is already covered | `require_go_live_approval` in [Platform settings](02-platform-settings.md) lets an admin review setup before go-live.             |
| A separate environment is simpler    | No demo flags or guards in production, and no ledger deletes. See [Demo environment](../deployment/demo_environment.md#decision). |

## Properties

| Property              | Type       | Seeder            | Shows                                                                                                                                    |
| --------------------- | ---------- | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Pearlside View Resort | Resort     | `PearlsideSeeder` | 30 rooms (garden, sea view, family suites, water villas), packages and meals, day use, a dive group, SST, tourism tax and service charge |
| Boreno Hotel          | City hotel | `BorenoSeeder`    | 30 rooms (standard, deluxe, executive suites), corporate guests, a conference block, the city ledger                                     |

The name is **Boreno**, not Borneo. Both are Live. Boats are not in the demo.

A separate Boreno copy can run in production for internal use through `BorenoProductionSeeder`. It uses `owner@boreno.com` and `BORENO_SEED_PASSWORD`, not the public demo logins. It is a normal property without a flag or nightly reset. See [Production environment](../deployment/production_environment.md#boreno-hotel-for-internal-use).

`PearlsideSeeder` extends `SandbaySeeder` and changes only the name, address and logins, so the dev seeder and the demo resort stay the same data. `SandbaySeeder` stays the local development seeder. The shared front desk and folio steps live in `Database\Seeders\Concerns\SeedsThroughActions`.

## Data

`DemoSeeder` runs `OtaChannelSeeder`, `PearlsideSeeder` and `BorenoSeeder` through the real Actions only, so the ledger, AR balances and reports add up. It runs at every [reset](../deployment/demo_environment.md#reset) and takes about 3.5 s (measured locally).

1. Setup: room types, rooms, taxes, rates, charge items, discounts, companies.
2. Open the business date on today.
3. Create today's state: bookings, check-ins, charges, payments, city ledger invoices.

Fixed names and amounts, no random Faker data (Faker is not in the production image), so every reset looks the same and support can say "open the booking for Ahmad Razak". Dates are relative to today.

| Area         | Pearlside View Resort                                                                  | Boreno Hotel                                                                                                        |
| ------------ | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Reservations | 22: future bookings, a dive group (MasterPays), a day use, a no-show, a cancellation   | 18: future and company bookings, a conference block (MasterPays), a no-show, a cancellation                         |
| Front desk   | Arrivals today, a walk-in and a water villa in house                                   | 2 arrivals today, 2 in house (Ahmad Razak, VIP, on the Sabah Energy account)                                        |
| Folio        | Charges, a reversed line, a transfer to a third-party folio, routing, a closed invoice | Charges in house, company charges moved to company folios                                                           |
| Payments     | Cash, card and bank transfer, an advance, a refund                                     | Card deposit, cash advance, bank transfers from companies                                                           |
| City ledger  | None                                                                                   | 3 companies, 3 AR invoices: paid, part paid (the travel agent, near its RM 1,200 limit), and one with a credit note |
| Housekeeping | Default statuses                                                                       | Dirty and inspected rooms, remarks, one room that needs maintenance                                                 |
| Night audit  | No closed days. Room charges post at the first night audit.                            | Same                                                                                                                |

Later: 14 days of history with closed night audits, room charges, overdue AR invoices and real report numbers. See [Checklist](../checklist.md#demo).

## Logins

A user belongs to one property (`users.property_id`), so each demo property has its own logins. Every demo staff account uses `DEMO_PASSWORD`. `DemoSeeder` refuses to run without it (at least 8 characters).

| Property              | Owner (the demo login)        | Other staff                                                                              |
| --------------------- | ----------------------------- | ---------------------------------------------------------------------------------------- |
| Pearlside View Resort | `pearlside@demo.estatell.com` | `pearlside.manager@`, `pearlside.frontdesk@`, `pearlside.housekeeping@demo.estatell.com` |
| Boreno Hotel          | `boreno@demo.estatell.com`    | `boreno.manager@`, `boreno.frontdesk@`, `boreno.housekeeping@demo.estatell.com`          |

- The partner portal in production shows a **Demo access** card with the demo link, both Owner logins and **Copy login**. Production reads `DEMO_URL` and `DEMO_PASSWORD`. Partners can't change it.
- To change the password: set `DEMO_PASSWORD` on both environments, then reset the demo.

## Guards

Only on the demo environment (`APP_ENV=demo`):

| Guard                   | Effect                                                                                                                 |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Banner                  | A fixed banner: "Demo · Resets every night · Don't enter real guest details."                                          |
| No signup               | Fortify registration is off. The demo has no admins or partners, so no partner invitation or signup link exists there. |
| Account security locked | Profile (email), password, two-factor and delete account can't be changed, so no visitor locks the others out.         |

Everything else works as in production. Mail goes to the log, and the nightly reset undoes every change.

## Tests

- `DemoSeeder` runs and produces the counts above (rooms, bookings, in-house stays, AR invoices), both Owner logins use `DEMO_PASSWORD`, and it refuses to run without one (`tests/Feature/Seeders/DemoSeederTest.php`). A changed Action signature breaks this test, not the demo.
- With `APP_ENV=demo`: signup is refused, and account security can't be changed.
- With any other `APP_ENV`: the banner is hidden and the guards are off.

## Build status

Built: the seeders, the guards (`BlockOnDemo` in the web group, `Not available in the demo` toast), the banner (`isDemo` shared prop, `DemoBanner` in the staff and account layouts) and the partner card (`DemoAccessData::fromConfig()` from `estatell.demo`). See [Checklist: 17 Onboarding](../checklist.md#17-onboarding).
