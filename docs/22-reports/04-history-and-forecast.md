# History & forecast

## Purpose

One row for each date, past and future, with rooms, pax and money: Tanini's eZee "Monthly statistics" (requirement 7.3). Past dates show what happened; today and later dates show what the bookings on hand will bring.

**Status: built** (2026-11-22).

## Report

`ReportKey::HistoryForecast` (`history-forecast`), Reports › Revenue, Landscape, up to 366 days. With no dates in the URL it opens on the whole month of the business date (`ReportKey::opensOnMonth()`). Quick dates: This month, Last month, Next 7 days, This week, Next week, This year.

| Column          | Key             | Actual (closed date)                                       | Today and Forecast                                                |
| --------------- | --------------- | ---------------------------------------------------------- | ----------------------------------------------------------------- |
| Date            | `date`          | The date                                                   | The date                                                          |
| Basis           | `basis`         | Actual                                                     | Today (the open business date) or Forecast                        |
| Available rooms | `available`     | `daily_statistics` rooms + beds available                  | Active rooms and beds no open block takes (`AvailabilityService`) |
| Nights sold     | `sold`          | `daily_statistics` rooms + beds sold                       | Overnight nights of the units that hold inventory                 |
| Complimentary   | `complimentary` | Nights on a complimentary plan of the units that stayed    | Nights on a complimentary plan of the units that hold inventory   |
| Occupancy %     | `occupancy`     | Sold ÷ available                                           | Same                                                              |
| ADR             | `adr`           | Room charges ÷ (sold − complimentary)                      | Same                                                              |
| RevPAR          | `revpar`        | Room charges ÷ available                                   | Same                                                              |
| Pax             | `pax`           | `daily_statistics.in_house_guests`                         | Adults + children of the booked nights                            |
| Room charges    | `room_charges`  | Room revenue posted on the date, net of its discounts      | Posted so far + `ChargeForecast` room                             |
| Extra charges   | `extra_charges` | Every other charge and adjustment posted (day use too)     | Posted so far + inclusions, day use and scheduled add-ons         |
| Tax             | `tax`           | Tax lines posted                                           | Posted so far + forecast tax                                      |
| Receipts        | `receipts`      | Payment lines shown positive, refunds net, City ledger out | Today: posted so far. Forecast: "—"                               |

- **Complimentary.** A rate plan has `is_complimentary` (Settings › Rate plans › Details). Its nights count as sold for occupancy and stay out of ADR. Tanini's report had the column; estatell had no such concept before.
- **No Expense column.** estatell has no expense ledger. Add it if Tanini needs it.
- **Forecast charges** are quoted by `NightChargeQuote` and `DayUseChargeQuote`, the classes the night audit posts with and the folio shows as Scheduled rows, at today's prices and taxes. A night already on a folio (`night:{id}`) is never quoted again. A charge whose tax needs a nationality the guest lacks adds no tax.
- A closed date with no `daily_statistics` row (before go-live) is left out, as on Occupancy. Migrated lines and the Opening balance code are left out, as in every revenue figure (`DailyRevenueQuery::ledgerLinesBetween()`).
- **Totals.** Every count and amount is summed; occupancy, ADR and RevPAR are worked out again from the sums. A forecast date adds no receipts.
- **Queries.** A fixed number, whatever the range or the bookings (tested with lazy loading prevented).

## Code

| Part                                                          | Role                                                                           |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `HistoryForecastReport`                                       | Builds the actual rows and the forward rows, and the totals.                   |
| `BookedNightCounts`                                           | Sold, complimentary and pax of each date in one query, for the statuses given. |
| `ChargeForecast`                                              | Room, extra and tax of the unposted nights, day use and add-ons of each date.  |
| `DailyRevenueQuery::receiptsBetween()`                        | Receipts of each date.                                                         |
| `ForecastBasis`                                               | Actual, Today, Forecast (badge).                                               |
| `HistoryForecastReportController`, `reports/history-forecast` | The page, on `ReportScreen`.                                                   |

## Open

- A segment breakdown (revenue or occupancy by market segment over time) is kept in view until Tanini has segment data.
