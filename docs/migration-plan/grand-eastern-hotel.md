# Grand Eastern Hotel

wastays id 3 · Kota Kinabalu · 78 rooms · in wastays since 13 Aug 2026 · prefix `GEH` · **Full replay from 13 Aug, pilot**

## Summary

The cleanest data in wastays. Every closed folio is at zero, every in-house guest has paid in advance, and the hotel has no companies or future bookings. It is the pilot: it tests the importer on real data with little money at risk.

The hotel wants all its history in estatell, so Grand Eastern does not use the usual cutover. estatell goes live on **13 Aug 2026**, the first wastays business date, and the importer replays every wastays day through estatell Actions and the night audit. See [Go live](../17-onboarding/03-go-live.md#actions) and [Migration from another PMS](../17-onboarding/07-migration.md#the-rule).

## Setup

| Item          | wastays                                                                                                                                 | estatell                                                                      |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Room types    | 6: Superior Deluxe Twin 25, Superior Deluxe Family 6, Superior Triple 2, Super Deluxe Queen 10, Deluxe Queen 8, Superior Deluxe King 27 | Copy. Max children is 0 on 5 types: confirm                                   |
| Rooms         | 78, all active                                                                                                                          | Copy                                                                          |
| Rate plans    | 18: Standard, Walk-in and Corporate, repeated for each room type                                                                        | 3 plans, see below                                                            |
| Taxes         | SST on. DBKK 1.30 flat, off                                                                                                             | Service Tax 8%. DBKK: ask the hotel                                           |
| Extra charges | Cleaning Fee, Damage Charge, Food & Beverage, Miscellaneous Revenue, Parking, Extra Bed, TTX. All priced by hand                        | Charge items. TTX is likely the tourism tax: map it to the Tourism tax preset |

### Rate plans

| estatell plan  | Type                       | Price                                                                                    | Who can use it |
| -------------- | -------------------------- | ---------------------------------------------------------------------------------------- | -------------- |
| Standard Rate  | PerRoom                    | Twin 162.00, Family 192.24, Triple 192.24, Super Queen 143.64, Queen 138.24, King 162.00 | Everyone       |
| Walk-in Rate   | Derived from Standard, +0% | Follows Standard                                                                         | Everyone       |
| Corporate Rate | Derived from Standard, +0% | Follows Standard                                                                         | All companies  |

The base prices look like they include SST: 162.00 posts as 150.00 plus 12.00 SST. Confirm before import (see [Tax basis](README.md#rate-plan-mapping)). Staff often type their own price at the desk (103.27, 117.05, 133.00 are common), so the daily rate matters less here than the folio history.

## History

| Item            | Count                                                          | In the replay                               |
| --------------- | -------------------------------------------------------------- | ------------------------------------------- |
| Business dates  | 70 audits, 13 Aug to 21 Oct 2026, all completed                | One estatell audit per date                 |
| Bookings        | 686 (check-in 14 Aug to check-out 24 Oct 2026)                 | Normal reservations, created on their day   |
| Booking sources | Walk-in 351, Booking.com 205, Agoda 110, phone 18, Traveloka 2 | Kept on the reservation                     |
| Guests          | Part of 743 across wastays                                     | Guest profiles, through the Rails export    |
| Folio lines     | 2,589                                                          | Posted on the same business date as wastays |
| Invoices        | 670, `GEH-26700001` to `GEH-26700670`                          | Same numbers                                |
| Receipts        | 710, `GEH-26500001` to `GEH-26500710`, all issued              | Same numbers                                |

Figures are from the 22 Oct dump. They grow until cutover.

## Replay

### Day loop

`ApproveGoLive` opens the first business date on 13 Aug 2026. The importer then goes through every wastays business date in order:

```
for each wastays business date, 13 Aug → the last wastays audit:
  1. create the bookings made that day
  2. check in the arrivals, check out the departures
  3. post the extra charges and payments wastays posted that day
  4. RunNightAudit → the next date opens
```

- Every write goes through estatell Actions, never direct inserts. The wastays id is the idempotency key, and the audit skips steps already completed, so a stopped run resumes.
- Each night's price is fixed to the amount wastays posted, through `unit_nights.override_amount`. The room charge step then posts the wastays amount, typed desk prices included. Nothing is recalculated.
- The audit runs for past dates without force: `business_date_not_ended` refuses only a date that has not ended.
- The replay posts normal lines. It needs no bridge: no migrated stays, no opening balances, no Old system payments, no Old stays and no copied daily figures. The audit builds the daily figures.
- After the last wastays audit, estatell opens the next date, which is the cutover day. Staff start work in estatell on that day.

### Document numbers

estatell builds the same prefix (`GEH`) and the same reference format as wastays. Guests already hold wastays invoices and receipts, so each replayed document keeps its wastays number: invoice `GEH-26700123` in estatell is the same stay as in wastays.

`NextDocumentNumber` takes only a floor today, not an exact number. The importer needs a way to issue a document with its wastays number (to build). Folio and reservation references follow the same rule (to verify in wastays).

### Settings

| Setting                 | Value                                                                                                                  |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `migration_window_days` | 90. The window then closes on 11 Nov 2026. Raise it if cutover is later and a bridge is still needed for a stray item. |
| E-invoice               | Not used by the hotel. Nothing to switch off.                                                                          |

### Tax reports

Replayed lines are normal postings, so estatell's tax report shows full SST (and tourism tax, if TTX is that tax) for August to October. The hotel filed earlier periods from wastays reports.

| Period                                         | System of record                                                   |
| ---------------------------------------------- | ------------------------------------------------------------------ |
| Periods already filed                          | wastays. Never refile them from estatell, never add both together. |
| Periods not filed yet, including replayed days | estatell, after the dry run shows it matches wastays.              |

Tell the hotel's accountant before cutover.

### Dry run

1. Replay all 70 days on a copy of the estatell database.
2. Compare every day with its wastays night audit: room revenue, other revenue, SST, tourism tax, payments, occupancy.
3. Compare every invoice and receipt number and amount with wastays.
4. Fix and repeat until every day matches. Only then run on prod.

## Open at cutover

The replay checks guests in and out on their real days, so the guests in-house on the last replayed day are simply checked in. The 22 Oct dump had 9, all paid in advance (RM 1,300.20 credit). No opening balance is needed.

| Item              | Finding                                                         | In the replay                                                                                     |
| ----------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Empty open folios | 7 at zero: 6 on voided bookings, 1 on a cancelled booking       | Replayed as voided or cancelled                                                                   |
| Security deposits | 2 × RM 50 held, bookings 5 and 9 (August), both bookings voided | Replayed as held. Ask the hotel                                                                   |
| Future bookings   | None after 22 Oct                                               | New ones replay as normal bookings                                                                |
| OTA bookings      | Booking.com, Agoda, Traveloka                                   | Replayed from wastays up to cutover. Channex takes over after (to decide how not to import twice) |
| Companies and AR  | None                                                            | Nothing to do                                                                                     |

## Questions for the hotel

1. The two RM 50 deposits from 14 and 21 Aug: were they returned?
2. Is DBKK 1.30 still charged? Is TTX the tourism tax?
3. Which SST and tourism tax periods are already filed from wastays?
4. Which day is cutover? The window setting assumes cutover before 11 Nov.

## Order

1. Setup import, run on the clone first.
2. Guests, through the Rails export.
3. Exact document numbers in the importer.
4. Dry run of all days on a copy, until every day matches.
5. Cutover: last wastays audit, fresh dump, replay on prod, staff start in estatell the next morning.
