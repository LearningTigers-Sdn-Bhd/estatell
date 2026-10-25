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

**Decision needed:** estatell allows Only these companies on a derived plan only. The company plans have their own prices by occupancy, so a single percentage from a parent will not fit. Choose one: allow Only these companies on a plan with its own prices, or make each company plan a derived plan with an Amount and accept small price differences.

## History

| Item         | Count                        | estatell                           |
| ------------ | ---------------------------- | ---------------------------------- |
| Bookings     | 278 (stays from 25 Sep 2026) | Old stays for ended stays          |
| Night audits | 36                           | Daily figures copied once          |
| Folio lines  | 570                          | Stay in wastays                    |
| Companies    | 187                          | Copy. Confirm which are still used |

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
3. Companies, guests, Old stays, daily figures.
4. On go-live day: in-house stays, travel-agent and phone bookings with Old system payments, company debt and credit, and the Channex switch for OTA bookings.
