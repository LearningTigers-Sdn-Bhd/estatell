# Core reports

## Purpose

The reports a small property needs daily and monthly, with a shared frame: a date range with quick dates, a column layout per property, row selection and export as PDF, CSV or Excel.

## Reports

| Report                        | `ReportKey`          | Source                                              | Dates    | Default orientation | Longest range |
| ----------------------------- | -------------------- | --------------------------------------------------- | -------- | ------------------- | ------------- |
| Arrivals                      | `Arrivals`           | `reservation_units`                                 | Range    | Portrait            | 31 days       |
| Departures                    | `Departures`         | `reservation_units`                                 | Range    | Portrait            | 31 days       |
| In-house                      | `InHouse`            | `reservation_units`, `unit_nights`                  | One date | Portrait            | –             |
| Occupancy, ADR, RevPAR        | `Occupancy`          | `daily_statistics`                                  | Range    | Portrait            | 366 days      |
| Revenue by transaction code   | `Revenue`            | `DailyRevenueQuery` (live)                          | Range    | Portrait            | 366 days      |
| Payments by method            | `Payments`           | `payments`, `ar_payments`                           | Range    | Portrait            | 366 days      |
| Folio ledger                  | `FolioLedger`        | `folio_lines`                                       | Range    | Landscape           | 31 days       |
| Tax report (SST, tourism tax) | `Tax`                | `folio_lines` of kind Tax                           | Range    | Landscape           | 366 days      |
| Kitchen meal counts           | `MealCounts`         | `MealCounts::between()` (`MealEntitlementResolver`) | Range    | Portrait            | 31 days       |
| Outstanding balance           | `OutstandingBalance` | folio balances (`FolioBalance`)                     | One date | Portrait            | –             |
| Deposit liability             | `DepositLiability`   | payments on stays not yet arrived                   | One date | Portrait            | –             |
| Non-national guests           | `NonNationalGuests`  | in-house foreign guests and their tourism tax       | Range    | Landscape           | 31 days       |
| Police report                 | `PoliceReport`       | foreign guests who arrived                          | Range    | Landscape           | 31 days       |

- Revenue by transaction code is live for every date, open or closed, from `DailyRevenueQuery` (`app/Services/Reports/`), with grouping by day or month and drill-down to the lines. The night audit copies one closed date of the same query into `daily_revenue`.
- The last four reports come from wastays (step 7 of the build order). Payments also gets a **Received by** grouping with a cash-up total per staff member, and a **Refunds only** filter, in place of the wastays cashier activity and refund reports. Extra charges are covered by Revenue by transaction code.
- Occupancy, ADR and RevPAR read the `daily_statistics` snapshot for closed dates and `StatisticsCalculator` for the open date.
- City ledger aging and statements are already built on the Finance side (`AgingReport`, `StatementBuilder`). They stay there and are not in the registry. Their PDFs get the letterhead from [Printing settings](../02-property/05-printing-settings.md).
- The **night audit packet** is one fixed PDF per closed date, built from the snapshot tables. It is not in the registry and has no layout or row selection. It uses the printing settings, in Portrait. It opens from the Night audit history report.

## Registry

- `ReportKey` (backed enum, the value is the URL slug, for example `folio-ledger`): `label()`, `category()`, `defaultOrientation()`, `maxRangeDays()` (null for a one-date report), `columns()` and `report()`.
- `columns()` returns `ReportColumn` data objects in their default order: `key`, `label`, `align` (left or right) and `defaultVisible`. Column keys are stable. Renaming a key is a migration of stored layouts.
- `report()` returns the report class. Each report is one class in `app/Services/Reports/` that implements `Report`: `rows(Property, ReportFilters, ?array $rowKeys)` returns its row data objects, each with a stable `key` (the reservation unit id, the payment id, the date, the transaction code).
- `rows()` returns the full list for the dates and filters. `maxRangeDays()` keeps it small. The page controller sorts and pages that list on the server with the `?sort` and `?page` keys of `DataTable`. Exports call the same `rows()`, so the screen, the PDF and the spreadsheet always match.
- One controller per report page, as before. The layout and export routes are shared and thin: they take `{report}` as a `ReportKey` and call `report()`. There is no reports controller with logic in it.

## Arrivals columns

Arrivals lists the reservation units that arrive in the range. The row `key` is the reservation unit id.

| Key           | Label             | Align | Default visible |
| ------------- | ----------------- | ----- | --------------- |
| `reservation` | Reservation no.   | left  | ✓               |
| `guest`       | Guest             | left  | ✓               |
| `phone`       | Phone             | left  |                 |
| `arrival`     | Arrival           | left  | ✓               |
| `nights`      | Nights            | right | ✓               |
| `unit`        | Room type / room  | left  | ✓               |
| `guests`      | Adults / children | right |                 |
| `company`     | Company           | left  |                 |
| `status`      | Status            | left  | ✓               |
| `balance`     | Balance           | right | ✓               |

- Units that hold inventory count (Confirmed, Checked in, Checked out). Cancelled and no-show units are left out, because nobody arrives. **Guest** and **Phone** are the primary guest of the unit, or the booker when the unit has none. **Room type / room** shows the room or bed of the first night when one is assigned. **Balance** is the balance of the reservation (its folios that are not Voided), from `FolioBalance::forReservations()`.

## Other report columns

Each report has a stable column list like Arrivals. Proposed in the overview and settled in each build step:

| Report       | Row is                        | Columns                                                                                                                                                                                    |
| ------------ | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Departures   | a unit departing in the range | as Arrivals, with Departure in place of Arrival                                                                                                                                            |
| In-house     | a unit staying on the date    | Reservation, Guest, Room, Arrival, Departure, Guests, Company, Balance                                                                                                                     |
| Occupancy    | a date                        | Date, Rooms available, Rooms sold, Occupancy %, ADR, RevPAR, Room revenue, Arrivals, Departures, No-shows, In-house guests. The open date is marked **Live**; dates after it are left out. |
| Revenue      | a date (or month) and a code  | Date, Code, Description, Lines, Amount                                                                                                                                                     |
| Payments     | a payment                     | Date, Receipt no., Reservation or company, Method, Status, Staff, Amount, Reference (hidden by default)                                                                                    |
| Folio ledger | a folio line                  | Posting date, Folio, Reservation, Guest / payer, Code, Description, Kind, Staff, Amount                                                                                                    |
| Tax          | a date and a tax              | Date / month, Tax, Registration no. (hidden by default), Lines, Tax amount. There is no Base column, because tax lines do not store their base.                                            |
| Meal counts  | a date and a meal period      | Date, Meal, Adults, Children, Total                                                                                                                                                        |

### Built: Departures and In-house

- **Departures** has the Arrivals columns with `departure` in place of `arrival`, the same status rule, and a default sort of `departure`. **Room type / room** shows the room or bed of the last night.
- **In-house** (`room` is the default sort) lists the Checked in and Checked out units with arrival on or before the date and departure after it, so the arrival day counts and the departure day does not. Dates alone are enough: an early check-out moves the departure date and removes the unused nights, so a unit that left early is in-house on the dates it stayed. `unit_nights` is read only for **Room**, the room or bed of that date. Confirmed units are left out: an expected or missed arrival belongs on Arrivals, not In-house. Defaults visible: all columns except Company.
- **One-date URL.** A report with `maxRangeDays()` of null takes `?date=Y-m-d` (default: the business date) in the page URL, the exports and the layout preview. `ReportFilters` keeps `from` = `to` = that date, and the page props still send `from` and `to`. `from` and `to` are ignored for these reports.
- Arrivals, Departures and In-house share `UnitReportRows` (the guest, company and balance lookups).

### Built: Occupancy and Revenue

- **Occupancy** (`date` is the default sort, 366 days). Columns: `date`, `available`, `sold`, `occupancy`, `adr`, `revpar`, `revenue`, `arrivals`, `departures`, `noshows`, `guests`. The row key is the date. `available` and `sold` count rooms and beds as one unit each (`rooms + beds` of the snapshot), like the occupancy figure. A closed date reads its `daily_statistics` row. The open business date (also while it is Auditing) is worked out now by `StatisticsCalculator` and is marked **Live** (a badge on screen, "(live)" in the PDF and the spreadsheet). A date after the open date is left out, and so is a closed date with no snapshot row (before go-live): the report does not invent figures. `revenue` (room revenue) is not stored in the snapshot, so every date reads `DailyRevenueQuery::roomRevenueBetween()` with one query. A closed date takes no new postings, so it equals what the audit saw.
- **Revenue** (`period` is the default sort, 366 days). Columns: `period` (labelled "Date / month"), `code`, `description`, `lines`, `amount`. One row for each date and transaction code, or for each month and code with `group=month`; a month holds only the dates inside the range. The row key is `{period}:{transaction_code_id}` (`2026-10-07:12`, `2026-10:12`). It reads `DailyRevenueQuery::between()` for every date and never `daily_revenue`. Payments are not revenue.
- **Totals.** `HasTotals::totals(rows)` (an optional interface next to `Report`) works on the rows it gets, so it covers every row that matches the filters on the page and only the selected rows in an export. It returns `ReportTotals`, a map of column key to a count, a two-decimal string or null. Occupancy sums `available`, `sold`, `revenue`, `arrivals`, `departures` and `noshows`, and works `occupancy`, `adr` and `revpar` out again from the sums. `guests` has no total, because each date counts the same guests again. Revenue sums `lines` and `amount`. The screen shows it in the `DataTable` footer (the `footer` prop); the PDF adds a `tfoot` row and the spreadsheet a last row. Only visible columns show a figure, and "Total" goes in the first visible column that has none.
- **Filter `group`.** `ReportFilters` has a typed `?ReportGroup $group` (Day or Month). `ReportKey::groups()` lists the values a report takes (Revenue only; the first is the default). `ReportFiltersRequest` validates `group` against that list, and other reports ignore it. It stays in the page URL, the exports and the layout preview, and a Day / Month `ToggleGroup` in the toolbar sets it. Changing it clears the row selection. The `code` filter of step 5c is added to `ReportFilters` the same way.

### Built: Payments, Folio ledger and Tax

- **Payments** (`date` is the default sort, 366 days, Portrait, Finance). Columns: `date`, `receipt`, `payer` ("Reservation / company"), `method`, `status`, `staff`, `amount`, `reference` (hidden by default). One row for each folio payment or refund (`payments`) and each city ledger payment (`ar_payments`). Row key: `p:{id}` or `ar:{id}`. The date is the business date: the posting date of the payment's folio line, or `received_on` for a city ledger payment (`received_at` is the clock and is not used). The payer is the reservation number, or the company name for a city ledger payment. A refund has a negative amount (`payments.amount` is always positive; `refund_of_id` marks the refund) and no receipt number.
- **Status.** A folio payment shows its `PaymentStatus`. A voided city ledger payment shows **Voided**. `PaymentReportStatus` (Pending, Completed, Failed, Voided) holds these labels and tones, so `PaymentStatus` has no fake case. A city ledger payment that is not voided is Completed.
- **Totals count only Completed rows.** Pending, Failed and Voided rows show in the table but add nothing, in the total row and in the total for each method. Refunds subtract.
- **Total for each method.** `HasBreakdown::breakdown(rows)` (an optional interface next to `HasTotals`) returns a `ReportBreakdown`: a title, headings, rows of display text and a total row. Payments returns Method, Count and Amount for the Completed rows, with a Total row. The page controller sends it as the `breakdown` prop, `ReportScreen` shows it as a small table under the main table, and the PDF prints it under the main table. The spreadsheet has only the normal total row. Like `totals()`, it works on the rows it gets, so an export covers only the selected rows.
- **Folio ledger** (`date` is the default sort, 31 days, Landscape, Finance). Columns: `date` ("Posting date"), `folio`, `reservation`, `guest` ("Guest / payer"), `code`, `description`, `kind`, `staff`, `amount`. One row for each folio line of any kind; the row key is the line id and the amount is signed as in the ledger. **Guest / payer** is the company of a company folio, else the payer guest, else the booker of the reservation. Staff is empty for an automatic posting. The total row sums `amount`.
- **Filter `code`.** `ReportFilters` has `?string $code`. `ReportKey::hasCodeFilter()` is true for the Folio ledger only; `ReportFiltersRequest` validates `code` against the transaction codes of the property (`?code=ROOM`) and other reports ignore it. It stays in the page URL, the exports and the layout preview. A Select of the codes with **All codes** first sets it in the toolbar, and changing it clears the row selection.
- **Revenue drill-down.** The Revenue **Amount** cell links to the Folio ledger with that code and the dates of the row. A day row opens that day; a month row opens the month cut to the report's from and to dates (the row carries `from` and `to`). `ReportCellLink` (`components/pms/reports/`) is the reusable link for any cell renderer of `ReportScreen`.
- **Tax** (`period` is the default sort, 366 days, Landscape, Revenue). Columns: `period` ("Date / month"), `tax`, `registration` (hidden by default), `lines`, `amount` ("Tax amount"). The rows are the lines of kind Tax, one row for each date and tax, or for each month and tax with `group=month` (Tax is added to `ReportKey::groups()`). The row key is `{period}:{transaction_code_id}`. A tax is found by the transaction code of its lines (one code for each tax). Reversals reduce the date they were posted on. There is no Base column. The total row sums `lines` and `amount`.

### Built: Meal counts

- **Meal counts** (`date` is the default sort, 31 days, Portrait, Kitchen, icon `utensils`). Columns: `date`, `meal`, `adults`, `children`, `total`, all visible by default. One row for each date and meal period that has guests; the row key is `{date}:{meal_period_id}` (`2026-10-07:3`). Rows come in date order and, inside a date, in the order of the meal start times.
- **Source.** `MealCounts::between(Property, from, to)` loads the units once for the whole range. For each unit it calls `MealEntitlementResolver` once with adults plus children to get the dates and periods, then adds the unit's adults and children to each of them. Two inclusions for the same period count a guest once. `MealCounts::forDate()` calls `between()` for one date, and `MealCount` keeps `guests` as the total beside the new `adults` and `children`.
- **Units counted.** Confirmed, Checked-in and Checked-out. An early check-out has already moved `departure_date`, so a unit that left early is counted only for the meals it stayed for. Cancelled and No-show units are left out. The night audit message uses the same rule.
- **Future dates** are allowed, up to the 31-day range, and use the bookings as they are now. There is no forecast badge.
- **Totals.** The total row sums `adults`, `children` and `total`.
- **Empty state.** The controller sends `hasMealPlans`, false when no rate plan of the property has a meal inclusion (`MealCounts::propertyHasMealInclusions()`). The table then shows "No rate plan includes meals" in place of "No meals in these dates".
- **Quick dates.** Today, Tomorrow, Next 7 days, This week and Next week (see below).
- Children stay one count: there is no infant or age-group split.

### Categories

`ReportCategory` groups the Reports sidebar: **Front desk** (Arrivals, Departures, In-house, Police report), **Revenue** (Occupancy, Revenue, Tax, Non-national guests), **Finance** (Payments, Folio ledger, Outstanding balance, Deposit liability) and **Kitchen** (Meal counts).

### Totals

A report may implement an optional `totals()` that returns one row of sums for its money and count columns. The table shows it in the footer, and the PDF and the spreadsheet add it as the last row. Payments also shows a total per method under the table.

### Report filters

Some reports take one extra filter in the URL, validated by `ReportFilters` for that report only and kept in exports:

- Revenue and Tax: `group` (`day` or `month`), a toggle in the toolbar.
- Folio ledger: `code`, the transaction code string (`?code=ROOM`), a Select in the toolbar, checked against the codes of the property. **Revenue drill-down**: the Revenue Amount cell opens the Folio ledger for that date (or month) and code.
- Payments: `group=staff` (Received by) and `refunds=1` (step 7).

## Date range and quick dates

- `DateRangePicker` gets a `presets` prop. The presets show in a **Quick dates** Select before the date trigger, with **Custom dates** when the range matches no preset (choosing it opens the calendar). The Select and the trigger sit in a toolbar row under the page header, not in the header. Reports pass: Today, Yesterday, This week, Last week, This month, Last month, This year, Last 30 days.
- **Presets count from the business date, not the clock.** At 01:00 before the audit, Today is still the open business date. The page gets `businessDate` from the server, and the browser works out the presets from it. Weeks start on Monday, in the presets and in the calendar. A preset longer than `maxDays` (This year on Arrivals) is left out of the list.
- **Quick dates for each report.** `ReportKey::datePresets()` returns a list of `ReportDatePreset` values, or null for the standard list above. The page controller sends it as the `presets` prop, `ReportScreen` passes it to `reportDatePresets(businessDate, presets)` (`resources/js/lib/date-presets.ts`), and a report that sends none keeps the standard list. Meal counts sends Today, Tomorrow, Next 7 days, This week and Next week, all counted from the business date. The longer-than-`maxDays` rule applies to every list.
- A one-date report (In-house, Outstanding balance, Deposit liability) uses `DatePicker` with a `presets` prop: Today and Yesterday.
- `from` and `to` are in the URL query (`?from=2026-10-01&to=2026-10-07`), so a report can be bookmarked and shared. With no dates, the report opens on Today.
- `ReportFilters` (a Form Request plus a data object) checks that `from` is not after `to` and that the range is not longer than `maxRangeDays()`.

## Column layouts (per property)

```
report_layouts            id, property_id, report_key, orientation, timestamps
                          unique (property_id, report_key)
report_layout_columns     id, report_layout_id (FK, cascade), column_key, position, is_visible
                          unique (report_layout_id, column_key)
                          unique (report_layout_id, position)
```

- One layout per property and report, shared by every user. A printed report looks the same whoever prints it.
- No row means the code defaults: `defaultOrientation()` and `columns()`.
- `ReportLayoutResolver` (`app/Services/Reports/`) merges the stored rows with `columns()`. A column added later in code goes at the end with its default visibility. A stored key that is no longer in `columns()` is ignored.
- At least one column stays visible.
- The report page gets the resolved layout as one `ReportLayoutData` prop (the orientation and the columns in order, each with `visible`), plus `canEditLayout`.
- When the sorted column is hidden, the server falls back to the report's default sort.
- The screen table, the PDF, the CSV and the Excel file all use the resolved order and visibility. The PDF also uses the orientation.
- `PageOrientation`: Portrait, Landscape. It is added with the registry (step 2), because `defaultOrientation()` returns it.
- `category()` returns a `ReportCategory` (the sidebar group, for example Front desk) and `ReportColumn::align` is a `ColumnAlign` enum.

### Layout Sheet

Only users with `settings.manage` see the **Layout** button on a report page. It opens a Sheet on `FormOverlay`.

```
Arrivals     [ This week ▾ ]   [⚙ Layout]   [ Export ▾ ]
                                    │
                    ┌ Layout · Arrivals ──────────────┐
                    │ Orientation                     │
                    │ (•) Portrait  ( ) Landscape     │
                    │                                 │
                    │ ● Reservation no.        ↑ ↓    │
                    │ ● Guest                  ↑ ↓    │
                    │ ○ Phone                  ↑ ↓    │
                    │ ● Room                   ↑ ↓    │
                    │ ● Balance                ↑ ↓    │
                    │                                 │
                    │ [Reset to default]              │
                    │ [Preview PDF ↗]  [Cancel][Save] │
                    └─────────────────────────────────┘
```

- **The table behind the Sheet is the preview.** Hiding or moving a column changes it at once.
- **Preview PDF** (a native form POST with `target="_blank"`) opens the real PDF of the current dates with the unsaved layout, in a new tab. This shows the orientation, which the screen cannot.
- Order uses up and down arrows. There is no drag-and-drop package.
- **Save** calls `UpdateReportLayout`: orientation and the full column list in order, in one transaction. It refuses a list whose keys do not match `columns()`.
- **Cancel**, or closing the Sheet, puts the table back. A dirty Sheet asks before it closes.
- **Reset to default** asks first, then calls `ResetReportLayout`, which deletes the layout.
- Both log `report_layout.updated`.
- Every report PDF uses one generic view, `pdf/report.blade.php`: the letterhead, the title, the dates and the table. Preview PDF and the exports share it.
- `ReportPdf` (`app/Services/Reports/`) renders that view. Every row class implements `ReportRow`: `cells()` returns the display text by column key, so the view prints any report without knowing its columns. The preview route reads `from`, `to`, the orientation and the column list from the POST, checks the list with `ReportLayoutResolver::fromDraft()` (the same check as Save) and runs `rows()` on the server. The page gets the CSRF token for the native form from the shared `csrfToken` prop.

## Row selection and export

```
Arrivals     [ This week ▾ ]   [⚙ Layout]   [ Export ▾ ]  PDF · CSV · Excel
☑ RES-1042  Tan Wei Ming   204   350.00
☐ RES-1043  Aisyah Rahman  105     0.00
☑ RES-1045  陈美玲          301   120.00
        ╭ 2 selected   [ Export ▾ ]   [✕] ╮   ← BulkActionBar
```

- The report `DataTable` gets a checkbox column. The header checkbox selects the rows on the current page.
- **With rows selected**, the export contains only those rows. **With none selected**, it contains every row that matches the dates and filters, on all pages.
- While rows are selected, the floating `BulkActionBar` shows the count, the same Export menu and a clear button. The header checkbox shows a dash when only some rows on the page are selected.
- The selection is kept while paging and is cleared when the dates or filters change. Up to 500 rows can be selected.
- The browser POSTs the row keys, the dates, the filters and the sort, never row data. The server runs `rows()` again, scoped to the property and the filters, keeps only those keys and sorts them like the page. A key outside the filters is ignored. Exports use the saved layout.
- The PDF opens in a new tab through a native form POST (`target="_blank"`). The spreadsheet downloads through `postDownload` (`resources/js/lib/post-download.ts`), so a validation error shows as a toast. Neither is an Inertia visit.
- CSV and Excel use `SpreadsheetFormat` and `spatie/simple-excel`, which is already installed. Money columns are numbers, not formatted text. Each row has `cells()` (display text, for the screen and the PDF) and `values()` (typed values for spreadsheets: numbers for money and counts, `Y-m-d` for dates).
- The PDF uses the [printing settings](../02-property/05-printing-settings.md) letterhead, font and size, then the report title, the dates and "Selected rows only" when there is a selection. The table header repeats on every page.

## Permissions

`reports.view` (Owner, Manager, Finance) guards report pages, exports and the night audit packet. The Layout Sheet also needs `settings.manage`. A migration calls `SyncPermissions`.

## Routes

| Route                                            | Name                              | Permission                        |
| ------------------------------------------------ | --------------------------------- | --------------------------------- |
| `GET /p/{code}/reports/{slug}`                   | `reports.{slug}` (one per report) | `reports.view`                    |
| `PUT /p/{code}/reports/{report}/layout`          | `reports.layout.update`           | `reports.view`, `settings.manage` |
| `DELETE /p/{code}/reports/{report}/layout`       | `reports.layout.destroy`          | `reports.view`, `settings.manage` |
| `POST /p/{code}/reports/{report}/layout/preview` | `reports.layout.preview`          | `reports.view`, `settings.manage` |
| `POST /p/{code}/reports/{report}/pdf`            | `reports.pdf`                     | `reports.view`                    |
| `POST /p/{code}/reports/{report}/spreadsheet`    | `reports.spreadsheet`             | `reports.view`                    |

## Rules

- One class per report (query and data objects). The page controllers stay thin.
- Every report query is scoped by `property_id`.
- Exports: PDF with dompdf, CSV and Excel streamed.
- Report pages sort and page the `rows()` list on the server (not `spatie/laravel-query-builder`) and use `@tanstack/react-table` (`DataTable`) on the frontend.

## Build order

Item 20 of the [checklist](../checklist.md), in this order:

| Step | Commit                                                           | Content                                                                                                                          |
| ---- | ---------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 1a   | `feat(property): add printing settings and the logo`             | Backend and PDFs. See [Printing settings](../02-property/05-printing-settings.md)                                                |
| 1b   | `feat(property): add the profile tab and printing pages`         | UI. See [Properties](../02-property/01-properties.md#profile) and [Printing settings](../02-property/05-printing-settings.md#ui) |
| 1c   | `feat(property): add PDF font styles`                            | Office, Modern, Fancy, Handwritten. See [Printing settings](../02-property/05-printing-settings.md#fonts)                        |
| 2    | `feat(reports): add the report registry and the arrivals report` | `ReportKey`, `ReportColumn`, `PageOrientation`, `Report`, `ReportFilters`, date presets, `reports.view`, the Arrivals page       |
| 3    | `feat(reports): add report layouts`                              | Layout tables, `ReportLayoutResolver`, the Layout Sheet, Preview PDF                                                             |
| 4    | `feat(reports): export selected rows as PDF, CSV or Excel`       | Row selection, the PDF and spreadsheet routes                                                                                    |
| 5a   | `feat(reports): add departures and in-house`                     | Departures, In-house, `ReportCategory` groups in the sidebar, `DatePicker` presets, a shared `ReportScreen` page component       |
| 5b   | `feat(reports): add occupancy and revenue`                       | Occupancy, Revenue with the day or month toggle, `totals()`                                                                      |
| 5c   | `feat(reports): add payments, folio ledger and tax`              | Payments, Folio ledger with the `code` filter and the Revenue drill-down, Tax                                                    |
| 5d   | `feat(reports): add kitchen meal counts`                         | Meal counts                                                                                                                      |
| 6    | `feat(reports): add the night audit packet PDF`                  | The packet from the snapshot tables                                                                                              |
| 7a   | `feat(reports): add cashier activity and refunds`                | Received by grouping, cash-up total and the refunds filter on Payments                                                           |
| 7b   | `feat(reports): add outstanding balance and deposit liability`   | Two one-date reports                                                                                                             |
| 7c   | `feat(reports): add non-national guests and the police report`   | Two reports from guest nationality and the tourism tax lines                                                                     |

## Lessons from wastays

- `ReportsController` was 1,138 lines.
- `report_view_preferences` stored only visible columns, per user, as jsonb. estatell adds column order and orientation, keeps one layout per property and stores the columns as rows.
