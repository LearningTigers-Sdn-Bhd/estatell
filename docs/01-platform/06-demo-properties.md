# Demo properties

## Purpose

Let owners and partners' prospects try the full product on realistic data, without a practice mode inside real properties.

## Decision

Test drive was removed from onboarding on 2026-10-16. Two shared demo properties replace it.

| Reason                               | Detail                                                                                                                    |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| Owners want to go live, not rehearse | A practice step adds no setup data and makes the wizard longer.                                                           |
| Practice mode was the riskiest item  | A practice business date, a route exception in the fence, `ClearPracticeData`, and every new module had to know about it. |
| wastays failed at it                 | Its training reset job broke whenever a new module wrote data the job did not know.                                       |
| The go-live check is already covered | `require_go_live_approval` in [Platform settings](02-platform-settings.md) lets an admin review setup before go-live.     |
| B2B makes a shared demo good enough  | Traffic is low, and an admin can rebuild the data at any time.                                                            |

## Properties

| Property              | Type       | Shows                                                                   |
| --------------------- | ---------- | ----------------------------------------------------------------------- |
| Pearlside View Resort | Resort     | About 20 rooms (chalets, villas), packages and meals, tourism tax       |
| Boreno Hotel          | City hotel | About 30 rooms (standard, deluxe, suite), corporate guests, city ledger |

The name is **Boreno**, not Borneo. Both are Live, with `properties.is_demo = true`. Boats are not in the demo. `SandbaySeeder` stays the local development seeder.

## Data

`BuildDemoProperty` builds one demo property through the real Actions only (no direct inserts), so the ledger, AR balances and reports add up.

1. Setup: room types, rooms, taxes, payment methods, rates, charge items, discounts, companies.
2. Open the business date 14 days ago.
3. Each day: create bookings, check in, post charges, take payments, check out, settle some folios to the city ledger, then `RunNightAudit`.
4. Stop at today, with the business date open.

Fixed names and amounts, no random Faker data, so every rebuild looks the same and support can say "open the booking for Ahmad Razak". Dates are relative to today.

| Area         | Seeded on the business date                                                                    |
| ------------ | ---------------------------------------------------------------------------------------------- |
| Reservations | Future bookings, a group booking, a cancelled booking, a no-show                               |
| Front desk   | 3 arrivals, 2 in-house stays, 1 due out, 1 room to inspect                                     |
| Folio        | Room charges, F&B, a discount, a reversed line, a split folio with a routing rule              |
| Payments     | Cash, card and bank transfer, one deposit                                                      |
| City ledger  | 2 companies (one near its credit limit), AR invoices (paid, part paid, overdue), 1 credit note |
| Registration | Signed cards for the in-house guests                                                           |
| Housekeeping | Mixed statuses, a remark, one room that needs maintenance                                      |
| Night audit  | 14 closed days. Visitors can run the audit by hand. The nightly rebuild sets the date back.    |
| Reports      | 14 days of real numbers: occupancy, revenue, daily summary, AR aging                           |

## Guards

| Flag                 | Effect                                                                                                                                                                                                                                                                                                |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `properties.is_demo` | No outgoing email or SMS. No channel manager connection. No automatic night audit (`DueAudits` skips it). Hidden from the admin properties list, the onboarding board and the partner list, except on the **Demo** tab. A fixed banner: "Demo · Shared with other visitors. Data resets every night." |
| `users.is_demo`      | No changes to email, password or two-factor. Account pages are read-only. Can't sign in to a real property.                                                                                                                                                                                           |

## Logins

- One demo login per partner, plus one for estatell's direct sales. Each login has the Owner role on both demo properties.
- An admin creates, turns off and resets demo logins on the **Demo** tab. Turning one off stops that partner's link without touching the others.
- The partner portal shows its own login on a **Demo access** card with **Copy login**. Partners can't change it.
- To verify: how the password stays readable for **Copy login**. Either reuse `temporary_password` without an expiry (today a check constraint needs one), or add an encrypted column.

## Rebuild

- `RebuildDemoProperty` runs as a queued job. It deletes all of the demo property's data, runs `BuildDemoProperty` again and attaches the demo logins again.
- Triggers: **Rebuild** on the admin **Demo** tab, and a nightly schedule at 03:00 property time.
- The property foreign keys use `restrictOnDelete`, so a table the delete step misses makes the rebuild fail loudly.
- To verify: how long a 14-day build takes.

```
 Admin ▸ Properties ▸ [Demo]                       Partner ▸ Properties
 ┌─────────────────────────────────────────────┐   ┌─ Demo access ───────────────────┐
 │ Pearlside View Resort  rebuilt 03:00 [Rebuild]│   │ demo-andaman@estatell.com        │
 │ Boreno Hotel           rebuilt 03:00 [Rebuild]│   │ Opens Pearlside View Resort and │
 │                                             │   │ Boreno Hotel.      [Copy login] │
 │ Demo logins                       [Add login]│   └─────────────────────────────────┘
 │  estatell (direct)    [Copy] [Reset] [Off]  │
 │  Andaman Stays        [Copy] [Reset] [Off]  │
 └─────────────────────────────────────────────┘
```

## Tests

- `BuildDemoProperty` runs and produces the counts above (rooms, bookings, in-house stays, AR invoices). A changed Action signature breaks this test, not the demo.
- A rebuild leaves no extra rows: after two rebuilds, row counts per `property_id` table match one build.
- A demo property sends no notification and never appears in `DueAudits`.
- A demo user can't change email, password or two-factor, and can't open a real property.
- A partner sees only its own demo login. A turned-off login can't sign in.

## Build status

Not built. See [Checklist: 17 Onboarding](../checklist.md#17-onboarding).
