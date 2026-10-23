# Hotel Asia City

wastays id 6 · Kota Kinabalu · 36 active rooms · in wastays since 17 Sep 2026 · **Copy setup**

## Summary

Set up in wastays with many rate plans, but only one booking (6 Oct, reversed). Copy the setup, then the hotel finishes onboarding in estatell.

## Setup

| Item            | wastays                                                                                   | estatell                                                       |
| --------------- | ----------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Room types      | 4: Deluxe Queen 18, Deluxe Twin 12, Superior Triple 5, Executive 1                        | Copy                                                           |
| Rooms           | 59, of which 23 archived                                                                  | Copy the 36 active rooms only                                  |
| Rate plans      | 24 active (Standard and Walk-in repeated per room type), 4 archived DayUse plans          | 11 plans, see below                                            |
| Taxes           | SST on (8%). "Service Tax" 10%                                                            | Service Tax 8%, plus a custom 10% named "Service charge" (ask) |
| Extra charges   | Mattress set 50, Key & tag lost 55, Pillow, Parking, Miscellaneous, Miscellaneous Revenue | Charge items                                                   |
| Payment methods | 7                                                                                         | Copy                                                           |

### Rate plans

Prices per room type: Deluxe Queen / Deluxe Twin / Superior Triple / Executive.

| estatell plan                                                             | Type                       | Price                           |
| ------------------------------------------------------------------------- | -------------------------- | ------------------------------- |
| Standard Rate                                                             | PerRoom                    | 93.22 / 93.22 / 118.65 / 135.59 |
| Walk-in Rate                                                              | Derived from Standard, +0% | Follows Standard                |
| HALFDAY                                                                   | Day use, 4 hours           | 50.85 / 50.85 / 63.56 / 67.80   |
| FASTART                                                                   | PerRoom                    | 76.27 / 76.27 / 110.17 / 118.64 |
| STAFF                                                                     | PerRoom                    | 76.27 / 76.27 / 110.17 / 118.64 |
| ADVANCE PAYMENT                                                           | PerRoom                    | 83.90 / 83.90 / 114.41 / 122.03 |
| BOOKING.COM, OYO, AGODA, EXPEDIA, MEITUAN, CTRIP, TRAVELOKA, DIDA TRAVELS | PerRoom, one plan each     | 67.80 / 67.80 / 110.17 / 118.64 |

All plans are Everyone. The fixed plans cannot be derived: their discount is not one percentage (the OTA plans are 27% below Standard on Queen, 7% on Triple). The import creates 12 months of daily rates from go-live.

### Tax basis

The prices look like they are before tax: 93.22 × 1.18 = 110.00, and the one booking was paid 110.00. Two ways give the guest the same total. The rounding was checked at 93.22 and 67.80.

| Choice                      | Prices | Taxes in estatell     |
| --------------------------- | ------ | --------------------- |
| **Recommended:** before tax | 93.22  | 8% and 10%, exclusive |
| Tax included                | 110.00 | 8% and 10%, inclusive |

### Likely data errors

| Finding                                                                                            | Import rule                                               |
| -------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| Standard Rate has an extra adult charge of 110 (Queen, Twin) and 140 (Triple): the full room price | Import as 0 unless the hotel confirms                     |
| OTA-named plans with no channel manager                                                            | Import as plans. Map or archive when Channex is connected |

## Questions for the hotel

1. Is the 10% "Service Tax" a service charge?
2. Do you think in prices with tax (110) or before tax (93.22)?
3. Should a third adult in the Superior Triple pay RM 140?
4. Do you take bookings anywhere today? Those must be entered at go-live.
5. Are the OTA-named plans used to key in OTA bookings by hand?
