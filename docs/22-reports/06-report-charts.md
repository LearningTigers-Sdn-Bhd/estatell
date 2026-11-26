# Report charts

## Purpose

Managers asked to see the revenue reports, not only read them. A chart above the table shows the trend of the range at a glance. The table stays the record: exact figures, drill-down links, the total row and the exports.

**Status: built** (2026-11-25), on screen only. The PDF, Excel and CSV exports have no chart.

## Which reports

| Report                                            | Chart                                                                                          | Points                               |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------ |
| [Occupancy, ADR, RevPAR](01-core-reports.md)      | Bars of Occupancy, ADR, RevPAR or Room revenue (a switch in the card header)                   | A day, or a month past 62 days       |
| [History & forecast](04-history-and-forecast.md)  | The same, with Room charges in place of Room revenue. Forecast bars are lighter, with a legend | A day, or a month past 62 days       |
| [Revenue by transaction code](01-core-reports.md) | Two cards: the codes ranked by amount, and the total of each day or month                      | The report's Group by (Day or Month) |

Group mode (2026-11-26) shows the same charts for all properties at once: Performance and the group Occupancy report through `RoomStatsChart::forGroup()`, the group Revenue report through `RevenueChart`. See [Group mode](../03-staff/05-group-mode.md#group-reports).

Tax, Non-national guests, Daily charges and the Folio ledger have no chart: they are lists to check and file, and a chart adds nothing.

## Rules

- **The server sends the chart, for the whole range.** The table pages its rows, so a chart drawn from the page would be wrong past one page. Each controller passes the rows it already built for the totals (every row of the range) to a chart service, and the page gets a `chart` prop. No extra query.
- **The chart adds up to the table.** A day point is the row itself. A month point is the report's own `totals()` over that month's rows, the same math as the total row of the table filtered to that month. The Revenue code amounts and the period totals each add up to the table's total.
- **One series, one color.** Every chart is one series in `chart-3`. The chart tokens are one green ramp, which cannot tell several codes apart, so Revenue ranks the codes on one axis instead of stacking them. A stacked chart needs a categorical palette first (a DESIGN.md decision).
- **Bars, not lines.** The figures are separate days or months; a line would invent values between them. Bars are rounded at the free end and stand on a solid zero line.
- **Below zero.** Revenue counts on its posting date, so a correction can take a day or a code below zero. That bar hangs from the zero line in `warning`, never clamped, and a note under the chart explains it.
- **Missing days.** Occupancy has no row for a closed date without a night audit, so that day has no bar. Revenue fills every day or month of the range, 0.00 where there were no lines.
- **Basis.** `RoomStatsChartPointData.basis` is a `ForecastBasis`: Actual for a closed date, Today for the open date (and for a month that holds it, or mixes actual and forecast days), Forecast for a later date. Occupancy has no forecast. The tooltip names the basis.
- **Hover.** Every bar has a tooltip with the full date (or the code's name) and the figure in the property currency. The chart is `role="img"` with a label; the table below is its accessible table view.

## Build shape

| Part                             | Where                                                                                                                                                                                  |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Occupancy and History & forecast | `RoomStatsChart` (`app/Services/Reports/`), `RoomStatsChartData` and `RoomStatsChartPointData`; `DAILY_UP_TO_DAYS` = 62                                                                |
| Revenue by transaction code      | `RevenueChart`, `RevenueChartData`, `RevenueChartCodeData`, `RevenueChartPeriodData`; `TOP_CODES` = 8, the rest one "Other codes" bar (only when it sums two or more codes)            |
| Screen                           | `ReportScreen`'s `chart` slot, between the filters and the table; `components/pms/reports/room-stats-chart.tsx`, `revenue-chart.tsx`, the shared `chart-bar.tsx` and `chart-format.ts` |

## Later

- Charts on Booking performance (codes ranked by revenue and room nights per group) and a pickup line on Rooms on books.
- Charts in the PDF: the server would draw them as images, since the PDF engine cannot run the browser charts.

## Tests

`tests/Feature/Reports/OccupancyReportTest.php`, `RevenueReportTest.php` and `HistoryForecastReportTest.php`: the points by day and by month, the month equal to the table's totals for that month, the basis of each point, the zero-filled days, the code ranking with "Other codes", and a numeric code.
