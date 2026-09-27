# Core reports

## Purpose

The reports a small property needs daily and monthly.

## Reports

| Report                           | Source                             |
| -------------------------------- | ---------------------------------- |
| Arrivals, departures, in-house   | `reservation_units`, `unit_nights` |
| Occupancy, ADR, RevPAR           | `daily_statistics`                 |
| Revenue by transaction code      | `daily_revenue`                    |
| Payments by method               | `payments`                         |
| Folio ledger                     | `folio_lines`                      |
| Tax report (SST, tourism tax)    | `folio_lines` of kind Tax          |
| City ledger aging and statements | `AgingReport`, `StatementBuilder`  |
| Kitchen meal counts              | `MealEntitlementResolver`          |
| Night audit packet               | Snapshot tables                    |

## Rules

- One class per report (query + data object). No shared reports controller.
- Closed dates read from snapshot tables. Open dates read live data.
- Exports: PDF with dompdf. CSV via streamed responses.
- Index pages use `spatie/laravel-query-builder` for filters and sorting and `@tanstack/react-table` on the frontend.

## Lessons from wastays

`ReportsController` was 1,138 lines.
