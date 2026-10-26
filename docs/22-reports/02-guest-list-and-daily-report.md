# Guest list, Daily report and Booking performance

## Purpose

Four reports from wastays that hotels use daily and that estatell does not have yet:

| #   | Item                | What it is                                                                                                                         | Needs                   |
| --- | ------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ----------------------- |
| 1   | Tax split           | A Base column on the Tax report, and the layout rule for tax columns. Daily report › Revenue (item 4) gets one column for each tax | Nothing                 |
| 2   | Checked out         | A new report: the units that checked out in the range, with their balance                                                          | Nothing                 |
| 3   | Guest list          | One page with the tabs Arrivals, In-house, Departures and Checked out                                                              | Item 2                  |
| 4   | Daily report        | One page with the tabs Overview, Revenue and Cashier activity                                                                      | Item 1                  |
| 5   | Booking performance | Bookings and revenue grouped by booking date, source, room type, rate plan or status                                               | Ingest channel bookings |

The shared frame (date range, quick dates, column layout, row selection, export) is in [Core reports](01-core-reports.md). These items reuse it.

## Reports sidebar

```
FRONT DESK   Guest list · Police report · Boat manifest
REVENUE      Daily report · Occupancy · Revenue · Tax · Non-national guests · Booking performance
FINANCE      Payments · Folio ledger · Outstanding balance · Deposit liability
KITCHEN      Meal counts
AUDIT        Night audit history · Activity log
```

- **Guest list** replaces the Arrivals, Departures and In-house items. Those reports keep their `ReportKey`, but they are not listed in the sidebar.
- **Daily report** is a new item. Payments stays in the sidebar too.
- Police report and Meal counts stay separate. They have other columns and other users.

## 1. Tax split

The Tax report has no base today. Every tax line is a child of its charge (`parent_line_id`), so the base is the sum of the parent charges. No schema change.

```
Tax                                          [Day | Month]  [Export ▾]
┌────────────┬──────────────┬───────┬────────────┬────────────┐
│ Date/month │ Tax          │ Lines │ Base       │ Tax amount │
├────────────┼──────────────┼───────┼────────────┼────────────┤
│ 25 Oct     │ SST 8%       │  52   │ 10,920.00  │    873.60  │
│ 25 Oct     │ Tourism tax  │   9   │      —     │     90.00  │
├────────────┼──────────────┼───────┼────────────┼────────────┤
│ Total      │              │  61   │ 10,920.00  │    963.60  │
└────────────┴──────────────┴───────┴────────────┴────────────┘
```

- **Base** comes after Lines, visible by default. It is the sum of the parent lines of the tax lines in the row.
- A reversal of a tax line has the reversal of the charge as its parent, so it reduces the base on the date it was posted. A tax line under a discount line has the negative discount as its parent, so the discount reduces the base.
- A flat tax (Tourism tax, per room night) shows "—". The total row sums only the percentage taxes. To verify in the plan: how a flat tax is told apart (the tax definition or the transaction code).
- The **Migrated from old system** table under the main table is not changed.
- This item also adds the layout rule for tax columns (see [Daily report › Layout](#layout)), with a unit test, so item 4 only adds the columns.

## 2. Checked out

A new report, `ReportKey::CheckedOut`, category Front desk, range, Portrait, 31 days.

- Rows: the units with status Checked out whose departure date is in the range. An early check-out moves the departure date to the day the guest left, so the departure date is the check-out day.
- Columns: Booking, Guest, Room, Checked out (`checked_out_at`, date and time), Balance. The rest follow the Departures columns and are hidden by default.
- Default sort: Balance, highest first, so unpaid stays come to the top.
- It shares `UnitReportRows` with Arrivals, Departures and In-house.
- To verify in the plan: whether the staff member who checked out is stored. Add a **By** column only if it is.

## 3. Guest list

One page at `GET /p/{code}/reports/guest-list`, with a tab for each report:

```
Guest list                                         [⚙ Layout] [Export ▾]
[ Arrivals 12 ] [ In-house 34 ] [ Departures 9 ] [ Checked out 7 ]
 ━━━━━━━━━━━━
[📅 26 Oct 2026 – 26 Oct 2026 ▾]  [Today] [Tomorrow] [This week]
```

- The tab is in the URL (`?tab=arrivals`, `in-house`, `departures`, `checked-out`). A bad or missing value opens Arrivals. Each tab is a link.
- Each tab shows a count for the chosen dates. In-house counts the one date.
- The open tab is its own report: its own `ReportKey`, columns, layout, filters, sort, row selection and exports. Only the open tab's rows are sent.
- In-house takes one date (`?date=`). The other tabs take a range. Changing tabs keeps the dates where they fit.
- The old pages `reports.arrivals`, `reports.departures` and `reports.in-house` redirect to the Guest list with their tab and query.
- **Layout.** The Layout button edits the open tab's layout ("Layout · Guest list › Arrivals"). Saved layouts carry over. Changing tabs with unsaved changes asks first, like closing the Sheet does.

## 4. Daily report

One page at `GET /p/{code}/reports/daily-report`, with three tabs (`?tab=overview`, `revenue`, `cashier`). A bad or missing value opens Overview. The page takes a range, 366 days, with the standard quick dates.

```
[ Overview ] [ Revenue ] [ Cashier activity ]
```

### Overview

Two rows of four cards. No table, no layout, no export.

| Row                          | Cards                                                          |
| ---------------------------- | -------------------------------------------------------------- |
| Revenue (accrual)            | Bookings with revenue, Total charges, Adjustments, Net revenue |
| Cashier activity (cash flow) | Movements, Collected, Refunded, Net at desk                    |

- Revenue reads `DailyRevenueQuery`. Cashier reads the Payments rows with the same totals rule (Completed rows only, refunds subtract).

### Revenue

A new report, `ReportKey::DailyRevenue`, Landscape, not listed in the sidebar. One row for each date, or for each month with `group=month`.

```
┌────────┬──────┬───────────┬────────┬────────┬────────┬────────┬─────────┬──────────┬───────────┐
│ Date   │ Bkgs │ Accommod. │ Day use│ Fees   │ Extras │ SST 8% │ Tourism │ Adjust.  │ Total     │
├────────┼──────┼───────────┼────────┼────────┼────────┼────────┼─────────┼──────────┼───────────┤
│ 24 Oct │  38  │ 9,600.00  │ 240.00 │ 150.00 │ 820.00 │ 864.80 │  70.00  │ −120.00  │ 11,624.80 │
├────────┼──────┼───────────┼────────┼────────┼────────┼────────┼─────────┼──────────┼───────────┤
│ Total  │  79  │ 19,800.00 │ 240.00 │ 230.00 │1,460.00│1,738.40│ 160.00  │ −320.00  │ 23,308.40 │
└────────┴──────┴───────────┴────────┴────────┴────────┴────────┴─────────┴──────────┴───────────┘
```

- Buckets come from `TransactionCategory`: Accommodation (Room, without day use), Day use (`DailyRevenueQuery::dayUseRevenue()`), Fees (Fee), Extras (Food and beverage, Laundry, Minibar, Transport, Other), Adjustments (Discount, Adjustment). To verify in the plan against `DailyRevenueQuery`.
- **One column for each tax**, keyed `tax:{transaction_code}`, from `ReportKey::columnsFor($property)`. A property that has no SST shows no SST column.
- It reads `DailyRevenueQuery` live, like Revenue. Migrated lines stay out.
- Wastays also had By source and Extra charges tables under the main one. They are not in this item.

### Cashier activity

The Payments report inside the tab, with `group=staff` as the default. It uses the **Payments** layout, filters and exports, so one setting changes both.

### Layout

- `ReportLayoutResolver` today puts a column that is new to a saved layout at the end. **A tax column goes before Adjustments instead.** A saved column for a tax that no longer exists is ignored, as now.

## 5. Booking performance

A new report, `ReportKey::BookingPerformance`, category Revenue, range by **booking date**, 366 days.

```
Booking performance                    [Group by: Room type ▾]  [Export ▾]
┌──────────────────┬──────────┬─────────────┬─────────────┬──────────┬──────────┐
│ Room type        │ Bookings │ Room nights │ Revenue     │ ADR      │ Lead time│
├──────────────────┼──────────┼─────────────┼─────────────┼──────────┼──────────┤
│ Deluxe Queen     │    72    │    160      │ 32,000.00   │ 200.00   │ 15 days  │
│ Twin             │    51    │    118      │ 20,060.00   │ 170.00   │ 12 days  │
├──────────────────┼──────────┼─────────────┼─────────────┼──────────┼──────────┤
│ Total            │   167    │    379      │ 71,610.00   │ 188.95   │ 14 days  │
└──────────────────┴──────────┴─────────────┴─────────────┴──────────┴──────────┘
```

- **Group by** (`group`): Booking date, Source, Room type, Rate plan, Status. It is a filter in the URL, not part of the layout. Wastays also grouped by fund collector and currency. They are left out until the channel work needs them.
- **Room type counts per unit.** A booking with two room types counts in both rows. Room nights and Revenue come from that type's units only.
- The total row counts distinct bookings, so it can be less than the sum of the rows. ADR and Lead time are worked out again from the totals.
- Walk-ins have no lead time, as on the [dashboard Performance tab](../18-front-desk/06-dashboard-performance.md).

## Layouts

All use the existing layouts: one per property and report, edited in the Layout Sheet with `settings.manage`. No layouts per user, so a printed report looks the same whoever prints it.

| Page / tab                                  | Layout key                          |
| ------------------------------------------- | ----------------------------------- |
| Guest list › Arrivals, In-house, Departures | `Arrivals`, `InHouse`, `Departures` |
| Guest list › Checked out                    | `CheckedOut`                        |
| Daily report › Overview                     | None                                |
| Daily report › Revenue                      | `DailyRevenue`                      |
| Daily report › Cashier activity             | `Payments`                          |
| Booking performance                         | `BookingPerformance`                |

## Build order

Items 1 to 4 run as two lanes in parallel after a shared commit. Item 5 waits for channel booking ingest.

| Step | Lane   | Commit                                                    |
| ---- | ------ | --------------------------------------------------------- |
| 0    | Shared | `feat(reports): add the guest list and daily report keys` |
| 1    | A      | `feat(reports): split tax columns and add the tax base`   |
| 2    | B      | `feat(reports): add the checked-out report`               |
| 3    | B      | `feat(reports): merge guest reports into the guest list`  |
| 4    | A      | `feat(reports): add the daily report`                     |
| 5    | –      | `feat(reports): add booking performance`                  |

Step 0 holds the pieces both lanes touch: the `CheckedOut` and `DailyRevenue` cases on `ReportKey`, the sidebar entries in `ReportsNavigation` and the route stubs.
