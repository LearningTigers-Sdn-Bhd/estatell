# Guest list, Daily report and Booking performance

## Purpose

Four reports from wastays that hotels use daily and that estatell does not have yet:

| #   | Item                | What it is                                                                                                                         | Needs                   |
| --- | ------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ----------------------- |
| 1   | Tax split           | A Base column on the Tax report, and the layout rule for tax columns. Daily report › Revenue (item 4) gets one column for each tax | Nothing                 |
| 2   | Checked out         | A new report: the units that checked out in the range, with their balance                                                          | Nothing                 |
| 3   | Guest list          | One page with the tabs Arrivals, In-house, Departures and Checked out                                                              | Item 2                  |
| 4   | Daily report        | One page with the tabs Overview, Revenue, Charges and Cashier activity                                                             | Item 1                  |
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

The Tax report has no base today. Every tax line is a child of its charge (`parent_line_id`), so the base can be worked out from the parent. No schema change.

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

- **Base** comes after Lines, visible by default. For each tax line, the base is its parent line **plus the Adjustment children of that parent**. `PostFolioCharge` posts the charge at its full price and a discount booked with it as an Adjustment child beside the tax, so the parent alone would overstate the base by the discount.
- A reversal of a tax line has the reversal of the charge as its parent, so it reduces the base on the date it was posted. A tax line under a discount line (`ApplyDiscount`) has the negative discount as its parent, so the discount reduces the base.
- A **flat tax** is one whose `taxes.rate_type` is `flat` (Tourism tax, per room night). `TaxReport` already finds the tax of each row. A flat tax, or a row whose tax cannot be found, shows "—". The total row sums only the percentage taxes.
- The **Migrated from old system** table under the main table is not changed.
- This item also adds the layout rule for tax columns (see [Daily report › Layout](#layout)), with a unit test, so item 4 only adds the columns.

## 2. Checked out

A new report, `ReportKey::CheckedOut`, category Front desk, range, Portrait, 31 days.

- Rows: the units with status Checked out whose departure date is in the range. An early check-out moves the departure date to the day the guest left, so the departure date is the check-out day.
- Columns: Booking, Guest, Room, Checked out (`checked_out_at`, date and time), Balance. The rest follow the Departures columns and are hidden by default.
- Default sort: Balance, highest first, so unpaid stays come to the top.
- It shares `UnitReportRows` with Arrivals, Departures and In-house.
- **No By column.** `CheckOutUnit` stores `checked_out_at` only. The staff member is only in the activity log, and an undone and repeated check-out makes that join unreliable.
- A check-out done late (after the audit) keeps its departure date, so it shows on that date. Filtering on `checked_out_at` instead is a possible follow-up if a hotel asks.

## 3. Guest list

One page at `GET /p/{code}/reports/guest-list`, with a tab for each report:

```
Guest list                                         [⚙ Layout] [Export ▾]
[ Arrivals 12 ] [ In-house 34 ] [ Departures 9 ] [ Checked out 7 ]
 ━━━━━━━━━━━━
[📅 26 Oct 2026 – 26 Oct 2026 ▾]  [Today] [Tomorrow] [This week]
```

- The tab is in the URL (`?tab=arrivals`, `in-house`, `departures`, `checked-out`). A bad or missing value opens Arrivals. Each tab is a link.
- Each tab shows a count for the chosen dates. In-house counts the one date. Each of the four reports exposes a `query()` that its `rows()` uses, and the counts are four `COUNT` queries on it. `rows()` is too heavy for a count.
- The open tab is its own report: its own `ReportKey`, columns, layout, filters, sort, row selection and exports. Only the open tab's rows are sent.
- In-house takes one date (`?date=`). The other tabs take a range. From a range tab to In-house, `date` is `from`. From In-house to a range tab, `from` and `to` are the date. With no dates in the URL, each tab keeps its own default.
- The old routes `reports.arrivals`, `reports.departures` and `reports.in-house` keep their names and redirect to the Guest list with their tab and query. Exports and layouts keep using `{report}`, so they do not change.
- **Layout.** The Layout button edits the open tab's layout ("Layout · Guest list › Arrivals"). Saved layouts carry over.
- **Changing tabs.** The page renders `ReportScreen` with `key={tab}`, so the row selection resets. The Layout draft lives only while the Sheet is open, and the open Sheet covers the tabs, so a tab change cannot lose it. Closing a dirty Sheet already asks first.
- `ReportScreen` takes `query` (kept on every visit, here `tab`), `embedded` (no page title or header; the page draws them above the tabs) and `layoutLabel` ("Guest list › Arrivals" in the Sheet title).

## 4. Daily report

One page at `GET /p/{code}/reports/daily-report`, with four tabs (`?tab=overview`, `revenue`, `charges`, `cashier`). A bad or missing value opens Overview. The page takes a range, 366 days, with the standard quick dates.

```
[ Overview ] [ Revenue ] [ Charges ] [ Cashier activity ]
```

### Overview

Two rows of four cards. No table, no layout, no export.

| Row                          | Cards                                                          |
| ---------------------------- | -------------------------------------------------------------- |
| Revenue (accrual)            | Bookings with revenue, Total charges, Adjustments, Net revenue |
| Cashier activity (cash flow) | Movements, Collected, Refunded, Net at desk                    |

- Revenue reads `DailyRevenueQuery`. Total charges is the sum of the charge buckets and the taxes, Adjustments is the Adjustments bucket, and Net revenue is the two together.
- **Bookings with revenue** comes from a new `DailyRevenueQuery::bookingCountsBetween()`: distinct reservations with a Charge or Adjustment line in the range, over the same line filter (no migrated lines, no Opening balance).
- Cashier reads the Payments rows with the same totals rule (Completed rows only, refunds subtract). Movements counts the Completed rows.

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

- **Buckets**, by line kind and `TransactionCategory`:

    | Column        | Lines                                                                                        |
    | ------------- | -------------------------------------------------------------------------------------------- |
    | Accommodation | Charges of category Room, at full price, including no-show, without day use                  |
    | Day use       | Charges of the day use system code                                                           |
    | Fees          | Charges of category Fee                                                                      |
    | Extras        | Every other charge (Food and beverage, Laundry, Minibar, Transport, Other, and any unmapped) |
    | Tax columns   | Lines of kind Tax, one column for each tax                                                   |
    | Adjustments   | Lines of kind Adjustment (discounts and adjustments)                                         |
    | Total         | The sum of all columns: the net figure                                                       |

- **Bookings** is the `bookingCountsBetween()` count for the date or month. The total row counts distinct reservations over the range, not the sum of the rows.
- **One column for each tax**, keyed `tax:{taxes.code}` (for example `tax:SST`), from `ReportKey::columnsFor($property)`, for the taxes the property has. A property that has no SST shows no SST column. `taxes.code` is unique per property.
- `ReportPage` sorts by row properties. For a `tax:` key it falls back to the row's `values()`.
- It reads `DailyRevenueQuery` live, like Revenue. Migrated lines stay out.
- A tax line whose transaction code has no tax (only possible after a tax is deleted during onboarding) is left out of every column and the total.
- Wastays also had By source and Extra charges tables under the main one. They are not in this item.

### Charges

The charge register: Revenue at guest level. One row for each charge, discount or adjustment, with its taxes as columns. For the same dates its totals equal the Revenue tab totals. It is built after the other tabs (step 6).

```
[ Overview ] [ Revenue ] [ Charges ] [ Cashier activity ]
                          ━━━━━━━━━
[📅 25 Oct 2026 – 25 Oct 2026 ▾]  [Bucket: All ▾]  [🔍 e.g. guest name or booking no.]

┌────────┬─────────────┬─────────┬───────────────┬─────────┬──────────┬────────┬─────────┬─────────┐
│ Date   │ Charge      │ Booking │ Guest / payer │ Base    │ Discount │ SST 8% │ Tourism │ Total   │
├────────┼─────────────┼─────────┼───────────────┼─────────┼──────────┼────────┼─────────┼─────────┤
│ 25 Oct │ Room charge │ R-10234 │ Ahmad Faizal  │  180.00 │  −20.00  │  12.80 │  10.00  │  182.80 │
│ 25 Oct │ Discount    │ R-10198 │ Lim Wei Jie   │  −10.00 │    0.00  │  −0.80 │    —    │  −10.80 │
│ 25 Oct │ Room charge │ R-10201 │ Nurul Huda    │ −150.00 │    0.00  │ −12.00 │    —    │ −162.00 │
├────────┼─────────────┼─────────┼───────────────┼─────────┼──────────┼────────┼─────────┼─────────┤
│ Total  │             │         │               │   20.00 │  −20.00  │   0.00 │  10.00  │   10.00 │
└────────┴─────────────┴─────────┴───────────────┴─────────┴──────────┴────────┴─────────┴─────────┘
```

A new report, `ReportKey::DailyCharges`, Landscape, category Revenue, not in the sidebar, 31 days, default sort `date`. `TabbedReport::DailyReport` gets the `charges` tab after `revenue`.

**Rows.** A row is an **owner line and a posting date**. Every non-payment line belongs to exactly one row, on its own posting date, so the totals equal the Revenue tab on every date, also when a tax or discount is posted on a later day than its charge.

The owner of a line:

1. A line with no parent that is not the reversal of a child is its own owner: a charge, a top-level adjustment, or (as a fallback) a parentless tax.
2. A line with a parent belongs to the parent's owner: inline discounts, taxes, and the reversals of children (`ReverseLine` gives a child's reversal the parent's reversal as its parent).
3. A Tax or Adjustment reversal with no parent and `reversal_of_id` set (`RemoveLineTax`, `RecalculateLineTax`) belongs to the owner of the line it reverses, when that line is a child that is not an owner. The reversal of an owner line (a top-level adjustment, a later discount) is a row of its own.
4. An Adjustment child that has Tax children of its own is a later discount (`ApplyDiscount`): it is its own owner, bucket Adjustments. "Has Tax children" is the test, because `ApplyDiscount` skips flat taxes.

The chain walk stops after 4 steps, and a line is never dropped. Owners outside the range, and migrated owners of non-migrated children, are loaded by id with no migrated filter.

| Case                                       | Row                                                                                                  |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| A charge                                   | Its row: Base is the charge, its inline discount in Discount, its taxes in the tax columns           |
| A discount posted later with taxes         | Its own row on its own date, bucket Adjustments, Base is the discount, with its negative taxes       |
| A discount posted later with no taxes      | The Discount column of a row of its charge on that date, with Base 0                                 |
| A tax removed, recalculated or added later | A row of its charge on that date: the charge's description, Base 0, only the tax amounts             |
| A top-level adjustment                     | Its own row, bucket Adjustments                                                                      |
| A reversal                                 | Its own row, negative, on the date it was posted, status **Reversal**                                |
| A moved charge (`TransferLines`)           | The reversal on the old folio and the copy on the new one each show, status **Moved**; they net to 0 |
| Payments, migrated lines, Opening balance  | Left out, as on the Revenue tab                                                                      |
| A tax line whose code has no tax           | Left out, as on the Revenue tab                                                                      |

- The row key is a line id: the owner's id on the owner's own date, else the lowest line id of the row.
- **Base** is the owner line amount on its own date, else 0. **Total** is Base + Discount + every tax. A flat tax column shows its amount.
- **Guest / payer** follows the Folio ledger: the company of a company folio, else the payer guest, else the booker. Every folio has a reservation.
- **Bucket** comes from a shared `ChargeBucket` enum (Accommodation, Day use, Fees, Extras, Adjustments) with `forLine(kind, category, systemKey)`. The Revenue tab uses the same enum, so the two tabs cannot drift.
- **Status** (`ChargeStatus`): **Moved** when `transfer_group_id` is set, else **Reversal** when `reversal_of_id` is set, else **Posted**. Moved is checked first, so a move shows as one Moved pair.
- A parentless tax owner (a fallback) has no bucket: its Bucket is empty and no bucket filter matches it.

**Columns.** Visible: Date, Charge (the line description), Booking, Guest / payer, Base, Discount, one `tax:{taxes.code}` column for each tax of the property, Total. Hidden: Bucket, Folio, Room, Code, Status, Staff. Tax is charged on the price after the discount, so the taxes come after Discount and before Total. `ReportKey::taxColumnAnchor()` names the column the taxes go before (`adjustments` for Daily revenue, `total` for Charges); `columnsFor()` and `ReportLayoutResolver` both use it, so a new tax column joins a saved layout before that column.

**Filters.**

- `bucket` (`accommodation`, `day-use`, `fees`, `extras`, `adjustments`; none for all): a Select in the toolbar. It filters the rows and the totals.
- `search`: the booking number or the Guest / payer name, a case-insensitive substring match on the server after the rows are built (31 days keeps them few), at most 100 characters, so it covers every page and `%` or `_` match literally. `bucket` is also applied after the owners are found.
- Both are typed on `ReportFilters` (`?ChargeBucket $bucket`, `?string $search`) behind `ReportKey::hasChargeFilters()`, stay in the URL, the exports and the layout preview, and clear the row selection when they change.

**Dates.** The tab takes 31 days at most. A Charges tab link built from a longer Revenue range keeps `to` and moves `from` to 30 days before it (`ReportFilters::clampedFrom()`, used by the tab link and the drill-down).

**Drill-down.** On the Revenue tab, each bucket cell of a row opens Charges for that date (or month, cut to the dates asked for and to 31 days) and bucket. A tax cell opens Charges for that date with no bucket. Each Revenue row carries its own `from` and `to`, so the browser only builds the link.

**Totals.** Base, Discount, each tax and Total. With no filter, **Total and each tax column** equal the Revenue tab for the same dates (a test checks this). The Revenue Adjustments column equals the Discount total plus the Base of the Adjustments rows, so it has no column of its own here. With a filter the totals cover only the matching rows.

**Queries.** A fixed number of queries whatever the row count: the lines of the range with their folio, reservation, booker, payer, unit, code and staff loaded, the owners and reversed lines outside the range by id, and the taxes. A query-count test with lazy loading prevented checks it.

### Cashier activity

The Payments report inside the tab, with `group=staff` as the default. It uses the **Payments** layout, filters and exports, so one setting changes both.

- The Payments page logic moves into a shared class that both the Payments page and this tab call.
- It renders `ReportScreen` with `embedded`, `query={{ tab: 'cashier' }}` and `layoutLabel`, so sorting and paging stay on `?tab=cashier`.

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
| Daily report › Charges                      | `DailyCharges`                      |
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
| 6    | A      | `feat(reports): add the charge register`                  |

Step 0 holds the pieces both lanes touch:

- The `CheckedOut` and `DailyRevenue` cases on `ReportKey`, both off the sidebar until their lane builds them.
- **A tab-aware report key.** `ReportFiltersRequest` takes the report key from the route name, so `reports.guest-list` and `reports.daily-report` would fail. Step 0 adds a `TabbedReport` enum (GuestList, DailyReport) whose `tabs()` maps each `?tab` to its `ReportKey` (the first is the default), and `ReportFiltersRequest` uses it for those two routes. Daily report › Overview uses the `DailyRevenue` key for its dates.
- The Guest list and Daily report sidebar entries in `ReportsNavigation`, first in their group, with Arrivals, Departures and In-house off the sidebar. An entry is also active on the old route of a tab that is not in the sidebar.
- The two routes. Until its lane replaces it, Guest list redirects to Arrivals and Daily report to Revenue, keeping the query.
- Stub `CheckedOutReport` and `DailyRevenueReport` classes with no rows, which the lanes fill.
