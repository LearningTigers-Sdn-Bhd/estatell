# Sand Bay Resort Semporna

wastays id 5 · Semporna · 41 rooms · in wastays since 16 Sep 2026 · **Full migration**

## Summary

Check-in 15:00 and check-out 12:00 (confirmed 25 Oct 2026). Property `AS1102` exists in production with an account only, so the setup import fills its details: `wastays:import-setup ... --check-in=15:00 --check-out=12:00`.

The hardest move. The resort sells to travel agents on company rates, has real company debt and credit, and has bookings out to August 2027. It moves after the Grand Eastern pilot and after [city ledger item 10](../15-city-ledger/05-old-system-balances.md) is built.

## Setup

| Item           | wastays                                                                                                                                                                                                                          | estatell                                                 |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Room types     | 6: Deluxe Super King 5, Deluxe King 9, Deluxe Twin 7, Standard Twin 10, Standard Triple 4, Standard Family 6. Base price 0 on all                                                                                                | Copy. Prices come from the plans                         |
| Rooms          | 41, all active                                                                                                                                                                                                                   | Copy                                                     |
| Rate plans     | 30 active, 6 archived. Every plan is per person with occupancy prices                                                                                                                                                            | 15 plans, see below                                      |
| Age bands      | Toddler or Infant 0–3, Child 4–12. Free, 50% or 100% by plan                                                                                                                                                                     | Property age groups and child prices                     |
| Stay discounts | Min 2 nights, an amount off from night 2: 50, 100, 180 or 200 by plan                                                                                                                                                            | Stay discounts                                           |
| Taxes          | SST off. Tourist Jetty Fee 10.00 flat, off                                                                                                                                                                                       | Ask the hotel                                            |
| Extra charges  | Tourist Jetty Fee 10 per person, Tourism Tax 10 per room-night, Charter Boat 280, Peak Season 100 per person-night, 1/2kg Cake 65, Sea Fishing (Half Day) 340, Single Occupancy, Food & Beverage, Miscellaneous Revenue, Parking | Charge items. Tourism Tax maps to the Tourism tax preset |

### Charge codes

The setup import maps these wastays codes to charge items, with the wastays price and unit when the extra charge is fixed. The folio lines of the replay land on them.

| wastays code | estatell charge item                                      | Price and unit         |
| ------------ | --------------------------------------------------------- | ---------------------- |
| JETTY        | Tourist Jetty Fee                                         | 10.00 per guest        |
| TTX          | TTX (a charge, no tax; `TAX_TTX` is the real tourism tax) | 10.00 per night        |
| SO           | Single Occupancy                                          | none (manual)          |
| MISC, PARK   | Miscellaneous Revenue, Parking                            | none (manual)          |
| CB           | Charter Boat                                              | 280.00 per item        |
| CAKE         | 1/2kg Cake                                                | 65.00 per item         |
| PS           | Peak Season                                               | 100.00 per guest-night |
| SFS          | Sea Fishing (Half Day)                                    | 340.00 per item        |

`auto_apply` on the Jetty Fee is not copied. The replay posts the wastays lines as they were, so it does not need it. Decide after cutover whether the item should add itself to new bookings.

### Rate plans

Prices are nightly totals by number of adults, for example Malaysian Publish Rate on Deluxe King: 1 adult 937.50, 2 adults 1,250.00, 3 adults 1,875.00. They map to PerGuest plans with occupancy prices.

| wastays plan                                                            | Copies | Access in wastays                       | estatell                                                        |
| ----------------------------------------------------------------------- | ------ | --------------------------------------- | --------------------------------------------------------------- |
| Standard Rate                                                           | 5      | Everyone                                | One plan                                                        |
| Base Rate                                                               | 1      | Everyone                                | Ask: likely replaced by Standard Rate                           |
| Malaysian Publish Rate                                                  | 5      | Everyone                                | One plan                                                        |
| International Publish Rate                                              | 1      | Everyone                                | One plan                                                        |
| Malaysian Agent Rate                                                    | 6      | All agents, any market                  | One plan, All companies. Ask whether the market should be Local |
| International Agent Rate                                                | 3      | All agents (1 copy international only)  | One plan, All companies, market International (confirm)         |
| Complimentary Stay                                                      | 1      | Everyone                                | One plan                                                        |
| Perfect Holiday                                                         | 1      | Only Perfect Vacation                   | Only these companies. **Needs a decision**, see below           |
| Super Sightseeing                                                       | 1      | Only Super Sightseeing                  | Same                                                            |
| CU World Travel, Good Earth Travel, GuangZhou Asian World, Zeng Jiaming | 1 each | **All agents except** the named company | Almost certainly meant "only". Confirm                          |
| Aladdin Holiday Tours, Guangdong Everbright                             | 1 each | All agents except nobody                | Confirm who should use them                                     |

**Decided 25 Oct 2026:** estatell now allows Only these companies on any plan ([Who can use this rate](../08-rates/01-rate-plans.md#who-can-use-this-rate)), so the company plans keep their own prices. The import (`wastays:import-setup`, `ImportWastaysOccupancyRatePlans`) does this when the bundle has an active plan that is not Standard, Walk-in or Corporate:

| Step           | Rule                                                                                                                                                                                                                                                             |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Merge          | Active copies with the same name become one per-guest plan that sells all their room types. Archived plans (Walk-in) are not imported. Base Rate joins Standard Rate, because it is the Standard Rate of the one room type Standard Rate lacks. Result: 14 plans |
| Standard Rate  | The seeded Basic Rate, switched to per guest. It keeps the name Basic Rate until the hotel renames it after go-live                                                                                                                                              |
| Prices         | Daily rates from the first audit date to 31 Dec 2027. The 1-adult price is the rate amount and every adult count is an occupancy price. No SST division, because SST is off. The room type base price (0) is ignored                                             |
| Children       | The youngest band goes to the youngest age group. A band of 0 is Free, a multiplier is Percent, an amount is Fixed. Child prices that differ by room type are not copied                                                                                         |
| Stay discounts | Amount or percent, from the night wastays names                                                                                                                                                                                                                  |
| Access         | hidden → Everyone. all → All companies, with the market when the copies agree (else all). only → Only these companies. except with a named company → Only that company. except with nobody → All companies                                                       |
| Companies      | Not in the export, so a limited plan starts with an empty list. The summary names the wastays company ids to list after the companies import                                                                                                                     |

Re-running writes nothing: every wastays plan copy and every room type price has an `import_links` row.

**Taxes step:** SST is off, so the setup import answers the Taxes step with "We don't charge tax" when no tax is on. Go-live would otherwise stay locked. The replay turns Tourism tax on from the day wastays first charged it.

## History

| Item         | Count                        | estatell                           |
| ------------ | ---------------------------- | ---------------------------------- |
| Bookings     | 278 (stays from 25 Sep 2026) | Old stays for ended stays          |
| Night audits | 36                           | Daily figures copied once          |
| Folio lines  | 570                          | Stay in wastays                    |
| Companies    | 187                          | Copy. Confirm which are still used |

### Companies

`wastays:import-companies {bundle} {property} --actor= --dry-run` runs after the setup import and before go-live. It reads `hotel_corporate_accounts` with the account name from `accounts`.

| wastays                                                            | estatell                                                                                                                                      |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Account name                                                       | Company name. Two wastays companies with the same name (ignoring case) become one company, and both ids link to it. Sand Bay has 3 such pairs |
| `account_type`                                                     | travel_agent → Travel agent, government → Government, anything else (company, salesperson, airline) → Corporate                               |
| `market`                                                           | Local or International                                                                                                                        |
| `relationship_type` direct_bill                                    | Direct bill on. City ledger is turned on when a company is created                                                                            |
| Contact email, phone                                               | Email lower case, dropped if invalid. Phone as E.164, dropped if unreadable (18 at Sand Bay)                                                  |
| Credit limit, payment terms, BRN, TIN, SST number, billing address | Copied. The export has no billing state or country                                                                                            |
| `status` suspended                                                 | Suspended                                                                                                                                     |
| Agent code, agent booking switches, portal login                   | Not copied. No portal login is created, so nobody gets an invitation                                                                          |
| `rate_plan_agency_rules`                                           | The company is listed on the merged plan that is limited to companies (6 links)                                                               |

Each wastays company has an `import_links` row (`hotel_corporate_accounts`), which the replay uses to put a booking on its company. A second run writes nothing.

### Stays

Sand Bay does not use the day-by-day replay. Its first weeks were typed into wastays after the fact (72 of 92 check-ins were recorded on or after the departure date), so the daily figures cannot match. It follows the [Migration](../17-onboarding/07-migration.md) rule instead: before go-live belongs to wastays, and only what is open comes across. Go-live is the cutover day (`wastays:go-live --opening-date=2026-10-23`), then `wastays:import-stays {bundle} {property} --actor= --dry-run`:

| wastays                                          | Bookings | estatell                                                                                                                                                                                                                              |
| ------------------------------------------------ | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Completed, left before go-live                   | 88       | **Old stay**: room and guest history, no money. The room is the one wastays gave it (or the room named on the booking when the stay was upgraded). A stay on a plan that needs a company, booked without one (11), goes on Basic Rate |
| Confirmed, arriving on or after go-live, not OTA | 110      | Normal booking with its company, source, plan and wastays price. What was paid in wastays, net of refunds, is one **Old system** payment on the Master folio (companies) or the unit folio. 12 bookings, RM 14,145.00                 |
| Confirmed OTA                                    | 13       | Not imported. They come through Channex                                                                                                                                                                                               |
| Cancelled, voided, no-show                       | 63       | Not imported. History stays in wastays                                                                                                                                                                                                |
| In the house or due out                          | 4        | Not imported yet. The hotel decides (see below)                                                                                                                                                                                       |

Bookings go in by wastays reservation number, so the numbers stay in order, and a second run writes nothing. The command exits with a failure and lists any stay it could not place (for example a room that is not free); the others are kept.

### Company bookings in the replay

| wastays                                                                                              | estatell                                                                                                                                                                                       |
| ---------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The company of a booking: `bookings.hotel_corporate_account_id`, else its company billing party      | The reservation's company. Billing mode Master pays all, so room charges and company money sit on the Master folio                                                                             |
| A line on a company folio (`booking_folios.payer_type` company): charge, payment, adjustment, refund | Master folio. Payments are Advance before check-in and Settlement after                                                                                                                        |
| A line on a guest folio of the same booking                                                          | The unit folio                                                                                                                                                                                 |
| A booking with no company on a plan limited to companies (15 bookings)                               | Created as an imported stay: the company check is skipped, because wastays allowed it                                                                                                          |
| `deposits` of kind prepayment                                                                        | Not replayed. The part wastays applied to a folio is already a payment line, and the rest (4 available deposits, RM 4,374; 1 cancelled group deposit) is held outside any folio. Ask the hotel |

### Other rules the replay needed

| wastays                                                                 | estatell                                                                                                                                                                                |
| ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A charge taken before the guest checked in (28 Jetty fee lines)         | estatell posts charges only on a checked-in unit. The charge waits and is posted when the unit checks in, on the check-in day. The day compare lists these days as accepted differences |
| More bookings of a room type than rooms (a group of 11 in a type of 10) | The booking is kept as an over-capacity stay: the replay retries it without the availability check and counts it                                                                        |
| Receipt of a prepayment deposit, issued when the deposit came in        | The first payment taken from the deposit takes that receipt number (the numbers stay in order). Later payments from the same deposit get the next estatell number                       |
| Payment with no reference on a method that needs one                    | Reference `wastays folio_transactions {id}`, or the wastays receipt number when there is one                                                                                            |

A check-in that wastays recorded on or after the departure date (72 of the 92 check-ins) is made as of the arrival day, and the day is listed as an accepted difference. The nights then post on the check-in day, while wastays posted them on the day it caught up. Local run: 2 Oct shows room revenue 3,930 in estatell against 0 in wastays, and 4 Oct shows 0 against 2,880, so the replay stops on 4 Oct.

### Booking sources

The replay places each wastays `bookings.source` like this. A booking with a wastays corporate account takes its company from the companies import (`import_links` table `hotel_corporate_accounts`), and the replay stops with "Import the companies first" when the company is missing.

| wastays source | Bookings                 | estatell source                                |
| -------------- | ------------------------ | ---------------------------------------------- |
| travel_agent   | 218 (211 with a company) | Company / Travel agent                         |
| phone          | 25                       | Phone                                          |
| ota            | 24                       | OTA (the Other channel, named OTA)             |
| internal       | 8                        | Internal (a Direct source, added when missing) |
| walk_in        | 2                        | Walk-in                                        |
| email          | 1                        | Email                                          |

## Open at cutover

### Company debt: RM 30,900

| Company                             | Owed      | wastays invoices   |
| ----------------------------------- | --------- | ------------------ |
| Super Sightseeing Holidays          | 10,700.00 | 5, 6, 7, 26        |
| Perfect Vacation                    | 6,590.00  | 11, 12, 30, 31, 32 |
| Beijing Pipikou International       | 5,270.00  | 19, 20             |
| Guangzhou Asian World International | 4,620.00  | 17, 18, 29         |
| Guangzhou Youxieren Trip Service    | 3,720.00  | 1, 2               |

13 of the 16 unpaid invoices are overdue. Each comes in with **Add from old system**, with its wastays reference (SBR-264000xx) and real dates.

### Company credit: RM 6,980

| Company                    | Credit   | Payments                       |
| -------------------------- | -------- | ------------------------------ |
| CU World Travel & Tour     | 2,760.00 | 920 + 920 + 460 + 460, Apr–Oct |
| Divers Armada Borneo       | 2,300.00 | One payment, 14 Apr            |
| Good Earth Travel and Tour | 1,920.00 | 960 + 960, Apr and Sep         |

### Future and in-house bookings: 128

| Source       | Bookings | Arrivals                  | Paid in wastays                   | estatell                                                  |
| ------------ | -------- | ------------------------- | --------------------------------- | --------------------------------------------------------- |
| Travel agent | 102      | 22 Oct 2026 → 10 May 2027 | 19,645.00, less 4,640.00 refunded | Normal bookings on company folios, payments as Old system |
| OTA          | 13       | 26 Oct 2026 → 19 Aug 2027 | 36,478.64                         | **Through Channex only**, never from wastays              |
| Phone        | 13       | 20 Oct → 1 Nov            | 6,870.00                          | 4 in house: migrated stays. 9 future: normal bookings     |

### Deposits

| Status    | Count | Amount    |
| --------- | ----- | --------- |
| Settled   | 36    | 69,023.64 |
| Refunded  | 41    | 40,284.25 |
| Cancelled | 1     | 3,200.00  |
| Available | 4     | 4,374.00  |

The 4 available deposits (ids 30–33, received 1 Oct) belong to bookings 482–485, which are completed. The money was never applied or refunded.

## Data the import must clean

| Problem                                                                   | Rule for the import                  |
| ------------------------------------------------------------------------- | ------------------------------------ |
| Payment 3 matched twice to invoices 13 and 14, one match each reversed    | Count only matches with no reversal  |
| Payment 10 (RM 11,400) voided as a double key; payment 11 is the real one | Skip voided payments                 |
| Invoices 13, 14, 17, 18, 19 have a due date before the issue date         | Use the real terms from the hotel    |
| Stored `paid_amount` and `outstanding_amount` on invoices                 | Recalculate from matches, never copy |

## Questions for the hotel

1. Confirm the RM 30,900 owed by the five companies, and any payment not yet entered.
2. Do CU World Travel, Divers Armada Borneo and Good Earth Travel really have this credit?
3. The four prepayments from 1 Oct (RM 4,374) for bookings 482–485: used, refunded or still held?
4. Which OTA collected the RM 36,478.64? Was it paid to you or held by the OTA?
5. Nine phone bookings arrive on 1 Nov with RM 802.50 charged and nothing paid. Is this one group? Who pays and when?
6. What are the real payment terms for invoices 13, 14, 17, 18 and 19?
7. The four company plans marked "all agents except": should they be for that company only?
8. Is the Tourist Jetty Fee a tax or a charge? Is Base Rate still used?
9. Which go-live date suits the 102 travel-agent bookings?

## Order

1. City ledger item 10.
2. Setup import with the company plan decision.
3. `wastays:import-companies`, then guests, then `wastays:go-live` and the replay.
4. On go-live day: in-house stays, travel-agent and phone bookings with Old system payments, company debt and credit, and the Channex switch for OTA bookings.
