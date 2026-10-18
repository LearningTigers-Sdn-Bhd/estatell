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

| Property              | Type       | Shows                                                                   |
| --------------------- | ---------- | ----------------------------------------------------------------------- |
| Pearlside View Resort | Resort     | About 20 rooms (chalets, villas), packages and meals, tourism tax       |
| Boreno Hotel          | City hotel | About 30 rooms (standard, deluxe, suite), corporate guests, city ledger |

The name is **Boreno**, not Borneo. Both are Live. Boats are not in the demo. `SandbaySeeder` stays the local development seeder.

## Data

`DemoSeeder` builds both properties through the real Actions only (no direct inserts), so the ledger, AR balances and reports add up. It runs at every [reset](../deployment/demo_environment.md#reset).

1. Setup: room types, rooms, taxes, payment methods, rates, charge items, discounts, companies.
2. Open the business date on today.
3. Create today's state: bookings, check-ins, charges, payments, city ledger invoices.

Fixed names and amounts, no random Faker data, so every reset looks the same and support can say "open the booking for Ahmad Razak". Dates are relative to today.

| Area         | Seeded on the business date                                                           |
| ------------ | ------------------------------------------------------------------------------------- |
| Reservations | Future bookings, a group booking, a cancelled booking, a no-show                      |
| Front desk   | 3 arrivals, 2 in-house stays, 1 due out, 1 room to inspect                            |
| Folio        | Room charges, F&B, a discount, a reversed line, a split folio with a routing rule     |
| Payments     | Cash, card and bank transfer, one deposit                                             |
| City ledger  | 2 companies (one near its credit limit), AR invoices (paid, part paid), 1 credit note |
| Registration | Signed cards for the in-house guests                                                  |
| Housekeeping | Mixed statuses, a remark, one room that needs maintenance                             |
| Night audit  | No closed days yet. Visitors can run the audit by hand after midnight.                |

Later: 14 days of history with closed night audits, overdue AR invoices and real report numbers. See [Checklist](../checklist.md#later).

## Login

- One shared login, `demo@estatell.com`, with the Owner role on both properties. The password comes from `DEMO_PASSWORD` on the demo environment.
- The partner portal in production shows a **Demo access** card with the demo link and the login, and **Copy login**. Production reads `DEMO_URL`, `DEMO_EMAIL` and `DEMO_PASSWORD`. Partners can't change it.
- To change the password: set `DEMO_PASSWORD` on both environments, then reset the demo.

## Guards

Only on the demo environment (`APP_ENV=demo`):

| Guard                    | Effect                                                                                  |
| ------------------------ | --------------------------------------------------------------------------------------- |
| Banner                   | A fixed banner: "Demo · Resets every night · Don't enter real guest details."           |
| No signup or invitations | Self-signup and partner invitations are off, so nobody creates a real account there.    |
| Account security locked  | Email, password and two-factor pages are read-only, so no visitor locks the others out. |

Everything else works as in production. Mail goes to the log, and the nightly reset undoes every change.

## Tests

- `DemoSeeder` runs and produces the counts above (rooms, bookings, in-house stays, AR invoices). A changed Action signature breaks this test, not the demo.
- With `APP_ENV=demo`: signup and partner invitations are refused, and account security can't be changed.
- With any other `APP_ENV`: the banner is hidden and the guards are off.

## Build status

Not built. See [Checklist: 17 Onboarding](../checklist.md#17-onboarding).
