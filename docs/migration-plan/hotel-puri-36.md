# Hotel Puri 36

wastays id 8 · Kota Kinabalu · no rooms yet · in wastays since 5 Oct 2026 · **Copy setup**

## Summary

Mid-onboarding in wastays: room types and rate plans exist, rooms do not. Copy what exists, then the hotel adds rooms in the estatell wizard. Move it soon, so it does not set up twice.

## Setup

| Item            | wastays                                                                                          | estatell                            |
| --------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------- |
| Room types      | 3: Deluxe Queen 21, Superior Twin 24, Standard Family 6                                          | Copy. Rooms are added in onboarding |
| Rooms           | None                                                                                             | Added by the hotel                  |
| Rate plans      | 9: Standard, Walk-in and Corporate, repeated for each room type                                  | 3 plans, see below                  |
| Taxes           | SST on. No other tax                                                                             | Service Tax 8%                      |
| Extra charges   | Cleaning Fee, Damage Charge, Food & Beverage, Miscellaneous Revenue, Parking. All priced by hand | Charge items                        |
| Payment methods | 8                                                                                                | Copy                                |
| Staff           | 5                                                                                                | Through the onboarding team step    |

### Rate plans

| estatell plan  | Type                       | Price                                                           | Who can use it |
| -------------- | -------------------------- | --------------------------------------------------------------- | -------------- |
| Standard Rate  | PerRoom                    | Deluxe Queen 88.89, Superior Twin 98.15, Standard Family 125.00 | Everyone       |
| Walk-in Rate   | Derived from Standard, +0% | Follows Standard                                                | Everyone       |
| Corporate Rate | Derived from Standard, +0% | Follows Standard                                                | All companies  |

88.89 and 98.15 look like prices before SST (96.00 and 106.00 with 8%). Run the import with `--net-prices` so they stay 88.89 and 98.15. Confirm the tax basis with the hotel (see [Tax basis](README.md#rate-plan-mapping)).

## Questions for the hotel

1. Are your prices 96 and 106 with SST?
2. Do you take bookings anywhere today?
