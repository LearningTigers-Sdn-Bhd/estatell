# Grand Eastern Hotel

wastays id 3 · Kota Kinabalu · 78 rooms · in wastays since 13 Aug 2026 · **Full migration, pilot**

## Summary

The cleanest data in wastays. Every closed folio is at zero, every in-house guest has paid in advance, and the hotel has no companies or future bookings. It is the pilot: it tests the importer on real data with little money at risk.

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

| Item            | Count                                                          | estatell                                      |
| --------------- | -------------------------------------------------------------- | --------------------------------------------- |
| Bookings        | 686 (14 Aug to 24 Oct 2026)                                    | Old stays for every stay ended before go-live |
| Booking sources | Walk-in 351, Booking.com 205, Agoda 110, phone 18, Traveloka 2 | Kept on the Old stay                          |
| Guests          | Part of 743 across wastays                                     | Guest list, through the Rails export          |
| Night audits    | 70                                                             | Daily figures copied once                     |
| Folio lines     | 2,589                                                          | Stay in wastays                               |

## Open at cutover

### In-house guests

9 guests, all paid in advance. Total credit RM 1,300.20.

| Folio | Booking | Stay        | Charged | Paid   | Balance |
| ----- | ------- | ----------- | ------- | ------ | ------- |
| 969   | 985     | 20 → 24 Oct | 267.50  | 534.98 | −267.48 |
| 939   | 965     | 20 → 23 Oct | 270.00  | 405.00 | −135.00 |
| 940   | 966     | 20 → 23 Oct | 270.00  | 405.00 | −135.00 |
| 976   | 992     | 21 → 23 Oct | 156.98  | 313.96 | −156.98 |
| 975   | 991     | 21 → 23 Oct | 111.53  | 223.06 | −111.53 |
| 985   | 1001    | 22 → 23 Oct | 0       | 111.53 | −111.53 |
| 982   | 998     | 22 → 23 Oct | 0       | 109.06 | −109.06 |
| 983   | 999     | 22 → 23 Oct | 0       | 129.98 | −129.98 |
| 984   | 1000    | 22 → 23 Oct | 0       | 143.64 | −143.64 |

Each becomes a migrated stay with its credit as one opening balance. If go-live is 24 Oct, only folio 969 remains.

### Other open items

| Item              | Finding                                                         | Action                        |
| ----------------- | --------------------------------------------------------------- | ----------------------------- |
| Empty open folios | 7 at zero: 6 on voided bookings, 1 on a cancelled booking       | Not migrated                  |
| Closed folios     | 670, all at 0.00                                                | Nothing owed                  |
| Security deposits | 2 × RM 50 held, bookings 5 and 9 (August), both bookings voided | Ask the hotel                 |
| Future bookings   | None after 22 Oct                                               | Nothing to import             |
| Companies and AR  | None                                                            | City ledger bridge not needed |

## Questions for the hotel

1. Can you go live on 24 Oct? Then only the guest in folio 969 comes over.
2. The two RM 50 deposits from 14 and 21 Aug: were they returned?
3. Are you taking new bookings outside wastays now?
4. Is DBKK 1.30 still charged? Is TTX the tourism tax?

## Order

1. Setup import, run on the clone first.
2. Guests and Old stays.
3. Daily figures.
4. On go-live day: the in-house migrated stays.
5. Check before go-live whether the opening balance needs the migrated-lines reports item (to verify).
