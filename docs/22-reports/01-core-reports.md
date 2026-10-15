# Core reports

## Purpose

The reports a small property needs daily and monthly, with a shared frame: a date range with quick dates, a column layout per property, row selection and export as PDF, CSV or Excel.

## Reports

| Report                        | `ReportKey`          | Source                                                 | Dates    | Default orientation | Longest range |
| ----------------------------- | -------------------- | ------------------------------------------------------ | -------- | ------------------- | ------------- |
| Arrivals                      | `Arrivals`           | `reservation_units`                                    | Range    | Portrait            | 31 days       |
| Departures                    | `Departures`         | `reservation_units`                                    | Range    | Portrait            | 31 days       |
| In-house                      | `InHouse`            | `reservation_units`, `unit_nights`                     | One date | Portrait            | –             |
| Occupancy, ADR, RevPAR        | `Occupancy`          | `daily_statistics`                                     | Range    | Portrait            | 366 days      |
| Revenue by transaction code   | `Revenue`            | `DailyRevenueQuery` (live)                             | Range    | Portrait            | 366 days      |
| Payments by method            | `Payments`           | `payments`, `ar_payments`                              | Range    | Portrait            | 366 days      |
| Folio ledger                  | `FolioLedger`        | `folio_lines`                                          | Range    | Landscape           | 31 days       |
| Tax report (SST, tourism tax) | `Tax`                | `folio_lines` of kind Tax                              | Range    | Landscape           | 366 days      |
| Kitchen meal counts           | `MealCounts`         | `MealCounts::between()` (`MealEntitlementResolver`)    | Range    | Portrait            | 31 days       |
| Night audit history           | `NightAuditHistory`  | `night_audits`, `daily_statistics`                     | Range    | Landscape           | 366 days      |
| Outstanding balance           | `OutstandingBalance` | `FolioBalancesOnDate` (the folio balance on the date)  | One date | Landscape           | –             |
| Deposit liability             | `DepositLiability`   | `FolioBalancesOnDate` (every credit balance)           | One date | Landscape           | –             |
| Non-national guests           | `NonNationalGuests`  | foreign units that stayed, and their tourism tax lines | Range    | Landscape           | 31 days       |
| Police report                 | `PoliceReport`       | every guest of a unit that stayed (`unit_guests`)      | Range    | Landscape           | 31 days       |
| Boat manifest                 | `BoatManifest`       | `unit_boat_trips` (only with the Boats feature)        | One date | Portrait            | –             |
| Housekeeping tasks            | `HousekeepingTasks`  | `room_statuses`, `unit_nights`, `room_blocks`          | One date | Landscape           | –             |

- Revenue by transaction code is live for every date, open or closed, from `DailyRevenueQuery` (`app/Services/Reports/`), with grouping by day or month and drill-down to the lines. The night audit copies one closed date of the same query into `daily_revenue`.
- **Boat manifest** belongs to the Boats module. See [Boat manifest](../24-boats/03-boat-manifest.md).
- **Housekeeping tasks** belongs to the Housekeeping module and is printed from the board, not from the Reports menu. It has no report page. Its export routes sit under `housekeeping.view`, and its Layout Sheet uses the shared layout routes. See [Print, export and layout](../19-housekeeping/02-print-and-layout.md).
- The core reports (item 20) are complete. The last six reports come from wastays (step 7 of the build order). Payments also gets a **Received by** grouping with a cash-up total per staff member, and a **Refunds only** filter, in place of the wastays cashier activity and refund reports. Extra charges are covered by Revenue by transaction code.
- Occupancy, ADR and RevPAR read the `daily_statistics` snapshot for closed dates and `StatisticsCalculator` for the open date.
- City ledger aging and statements are already built on the Finance side (`AgingReport`, `StatementBuilder`). They stay there and are not in the registry. Their PDFs get the letterhead from [Printing settings](../02-property/05-printing-settings.md).
- A company's debt after a folio is settled to the city ledger stays in City ledger aging: the settled folio is at 0 and is in neither Outstanding balance nor Deposit liability.
- The **night audit packet** is one fixed PDF per closed date, built from the snapshot tables. It is not in the registry and has no layout or row selection. It uses the printing settings, in Portrait. It opens from the Night audit history report and from the Run Night Audit sheet. See [Built: Night audit history and packet](#built-night-audit-history-and-packet).

## Activity log

Reports → Audit → Activity log opens `GET /p/{code}/reports/activity-log` (`reports.activity-log`). It requires `reports.view` and property access.

- The read-only page uses existing activity records, not the export registry. It has no layout editor or exports.
- Entries belong to the property through `properties.property_id` or a direct Property subject. The staff member alone never establishes property ownership.
- Columns show Time, Recorded by, Action, Record (type and internal ID), and Event. Times use the browser's time zone.
- The default order is newest first, with an ID tie-breaker. The Time column can reverse the timestamp order.
- Search matches action descriptions and event keys. Search, sort and pagination stay in the URL. The database returns 25 entries per page.
- System actions show System. Missing staff accounts show Unknown user. Missing subjects keep their recorded type and ID.
- Raw properties, audit diffs, credentials and identity numbers are not page props. The page does not track notification delivery.

## Registry

- `ReportKey` (backed enum, the value is the URL slug, for example `folio-ledger`): `label()`, `category()`, `defaultOrientation()`, `maxRangeDays()` (null for a one-date report), `columns()` and `report()`.
- `columns()` returns `ReportColumn` data objects in their default order: `key`, `label`, `align` (left or right) and `defaultVisible`. Column keys are stable. Renaming a key is a migration of stored layouts.
- **Merged and split columns.** Some columns pack two values in one cell (`unit` "Room type / room", `guests` "Adults / children", `identity` "ID", `exceptions`, and the date-and-time columns). A merged column keeps its key, label and default visibility. Its split columns are new keys, hidden by default, so staff choose them in the Layout Sheet. A split column sits right after its merged column and has its own typed row property, so it sorts and exports as a number, a date or a time. `ReportLayoutResolver` adds a new code column to a stored layout at the end with its default visibility, so adding split columns needs no data migration.
- `report()` returns the report class. Each report is one class in `app/Services/Reports/` that implements `Report`: `rows(Property, ReportFilters, ?array $rowKeys)` returns its row data objects, each with a stable `key` (the reservation unit id, the payment id, the date, the transaction code).
- `rows()` returns the full list for the dates and filters. `maxRangeDays()` keeps it small. The page controller sorts and pages that list on the server with the `?sort` and `?page` keys of `DataTable`. Exports call the same `rows()`, so the screen, the PDF and the spreadsheet always match.
- One controller per report page, as before. The layout and export routes are shared and thin: they take `{report}` as a `ReportKey` and call `report()`. There is no reports controller with logic in it.

## Arrivals columns

Arrivals lists the reservation units that arrive in the range. The row `key` is the reservation unit id.

| Key            | Label             | Align | Default visible |
| -------------- | ----------------- | ----- | --------------- |
| `reservation`  | Reservation no.   | left  | ✓               |
| `guest`        | Guest             | left  | ✓               |
| `phone`        | Phone             | left  |                 |
| `arrival`      | Arrival           | left  | ✓               |
| `arrival_time` | Arrival time      | left  | ✓               |
| `boat`         | Boat              | left  | ✓               |
| `nights`       | Nights            | right | ✓               |
| `unit`         | Room type / room  | left  | ✓               |
| `room_type`    | Room type         | left  |                 |
| `room`         | Room              | left  |                 |
| `guests`       | Adults / children | right |                 |
| `adults`       | Adults            | right |                 |
| `children`     | Children          | right |                 |
| `company`      | Company           | left  |                 |
| `status`       | Status            | left  | ✓               |
| `balance`      | Balance           | right | ✓               |

- Units that hold inventory count (Confirmed, Checked in, Checked out). Cancelled and no-show units are left out, because nobody arrives. **Guest** and **Phone** are the primary guest of the unit, or the booker when the unit has none. **Room type / room** shows the room or bed of the first night when one is assigned. **Balance** is the balance of the reservation (its folios that are not Voided), from `FolioBalance::forReservations()`.
- **Split columns** (hidden by default): `room_type` and `room` split **Room type / room**; `room` is empty when no room is assigned. `adults` and `children` split **Adults / children** and are whole numbers. **Arrival time** is `reservation_units.arrival_time` as `H:i`. It is visible by default. Arrivals has no totals.

## Other report columns

Each report has a stable column list like Arrivals. Proposed in the overview and settled in each build step:

| Report       | Row is                        | Columns                                                                                                                                                                                    |
| ------------ | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Departures   | a unit departing in the range | as Arrivals, with Departure in place of Arrival                                                                                                                                            |
| In-house     | a unit staying on the date    | Reservation, Guest, Room, Arrival, Arrival time (hidden), Departure, Departure time (hidden), Guests, Adults (hidden), Children (hidden), Company, Balance                                 |
| Occupancy    | a date                        | Date, Rooms available, Rooms sold, Occupancy %, ADR, RevPAR, Room revenue, Arrivals, Departures, No-shows, In-house guests. The open date is marked **Live**; dates after it are left out. |
| Revenue      | a date (or month) and a code  | Date, Code, Description, Lines, Amount                                                                                                                                                     |
| Payments     | a payment                     | Date, Receipt no., Reservation or company, Method, Status, Staff, Amount, Reference (hidden by default), Original receipt (hidden by default)                                              |
| Folio ledger | a folio line                  | Posting date, Folio, Reservation, Guest / payer, Code, Description, Kind, Staff, Amount                                                                                                    |
| Tax          | a date and a tax              | Date / month, Tax, Registration no. (hidden by default), Lines, Tax amount. There is no Base column, because tax lines do not store their base.                                            |
| Meal counts  | a date and a meal period      | Date, Meal, Adults, Children, Total                                                                                                                                                        |

### Built: Departures and In-house

- **Departures** has the Arrivals columns with `departure` and `departure_time` in place of `arrival` and `arrival_time` (and Boat out in place of Boat in), the same status rule, and a default sort of `departure`. **Room type / room** and its split `room` show the room or bed of the last night. **Departure time** is visible by default. Departures has no totals.
- **In-house** (`room` is the default sort) lists the Checked in and Checked out units with arrival on or before the date and departure after it, so the arrival day counts and the departure day does not. Dates alone are enough: an early check-out moves the departure date and removes the unused nights, so a unit that left early is in-house on the dates it stayed. `unit_nights` is read only for **Room**, the room or bed of that date. Confirmed units are left out: an expected or missed arrival belongs on Arrivals, not In-house. Defaults visible: all columns except Company, the two time columns and the split `adults` and `children`. In-house has a total row that sums `adults` and `children`; `guests` has no figure.
- **One-date URL.** A report with `maxRangeDays()` of null takes `?date=Y-m-d` (default: the business date) in the page URL, the exports and the layout preview. `ReportFilters` keeps `from` = `to` = that date, and the page props still send `from` and `to`. `from` and `to` are ignored for these reports.
- **Boat column.** Arrivals and Departures have a `boat` column after the time column, only while the [Boats](../24-boats/02-boat-trips.md#front-desk) feature is on. A `ReportColumn` can name a `feature`; `ReportKey::columnsFor($property)` and `ReportLayoutResolver` leave the column out of the layout while the feature is off, and `fromDraft` drops it from a saved draft. When the feature comes on, the column joins a stored layout at the end with its default visibility. Both reports order rows by date, time and id.
- Arrivals, Departures and In-house share `UnitReportRows` (the guest, company and balance lookups).

### Built: Occupancy and Revenue

- **Occupancy** (`date` is the default sort, 366 days). Columns: `date`, `available`, `sold`, `occupancy`, `adr`, `revpar`, `revenue`, `arrivals`, `departures`, `noshows`, `guests`. The row key is the date. `available` and `sold` count rooms and beds as one unit each (`rooms + beds` of the snapshot), like the occupancy figure. A closed date reads its `daily_statistics` row. The open business date (also while it is Auditing) is worked out now by `StatisticsCalculator` and is marked **Live** (a badge on screen, "(live)" in the PDF and the spreadsheet). A date after the open date is left out, and so is a closed date with no snapshot row (before go-live): the report does not invent figures. `revenue` (room revenue) is not stored in the snapshot, so every date reads `DailyRevenueQuery::roomRevenueBetween()` with one query. A closed date takes no new postings, so it equals what the audit saw.
- **Day use** ([Day use](../12-reservations/10-day-use.md#reports)): Occupancy gains `day_use_sold` and `day_use_revenue` after `revenue`, both visible by default (from `daily_statistics`, live from `StatisticsCalculator` for the open date), summed in the totals. A closed date from before the snapshot columns reads 0. `occupancy`, `adr` and `revpar` stay overnight only. Arrivals and Departures show "Day use · 10:00–14:00" in the Nights column. In-house has a **Stay** column (visible by default, empty for overnight) with the same label and lists a day use unit on its date. Police and Non-national guests list a day use on its date (0 nights).
- **Revenue** (`period` is the default sort, 366 days). Columns: `period` (labelled "Date / month"), `code`, `description`, `lines`, `amount`. One row for each date and transaction code, or for each month and code with `group=month`; a month holds only the dates inside the range. The row key is `{period}:{transaction_code_id}` (`2026-10-07:12`, `2026-10:12`). It reads `DailyRevenueQuery::between()` for every date and never `daily_revenue`. Payments are not revenue.
- **Totals.** `HasTotals::totals(rows)` (an optional interface next to `Report`) works on the rows it gets, so it covers every row that matches the filters on the page and only the selected rows in an export. It returns `ReportTotals`, a map of column key to a count, a two-decimal string or null. Occupancy sums `available`, `sold`, `revenue`, `arrivals`, `departures` and `noshows`, and works `occupancy`, `adr` and `revpar` out again from the sums. `guests` has no total, because each date counts the same guests again. Revenue sums `lines` and `amount`. The screen shows it in the `DataTable` footer (the `footer` prop); the PDF adds a `tfoot` row and the spreadsheet a last row. Only visible columns show a figure, and "Total" goes in the first visible column that has none.
- **Filter `group`.** `ReportFilters` has a typed `?ReportGroup $group` (Day or Month). `ReportKey::groups()` lists the values a report takes (Revenue only; the first is the default). `ReportFiltersRequest` validates `group` against that list, and other reports ignore it. It stays in the page URL, the exports and the layout preview, and a Day / Month `ToggleGroup` in the toolbar sets it. Changing it clears the row selection. The `code` filter of step 5c is added to `ReportFilters` the same way.

### Built: Payments, Folio ledger and Tax

- **Payments** (`date` is the default sort, 366 days, Portrait, Finance). Columns: `date`, `receipt`, `payer` ("Reservation / company"), `method`, `status`, `staff`, `amount`, `reference` (hidden by default). One row for each folio payment or refund (`payments`) and each city ledger payment (`ar_payments`). Row key: `p:{id}` or `ar:{id}`. The date is the business date: the posting date of the payment's folio line, or `received_on` for a city ledger payment (`received_at` is the clock and is not used). The payer is the reservation number, or the company name for a city ledger payment. A refund has a negative amount (`payments.amount` is always positive; `refund_of_id` marks the refund) and no receipt number.
- **Status.** A folio payment shows its `PaymentStatus`. A voided city ledger payment shows **Voided**. `PaymentReportStatus` (Pending, Completed, Failed, Voided) holds these labels and tones, so `PaymentStatus` has no fake case. A city ledger payment that is not voided is Completed.
- **Totals count only Completed rows.** Pending, Failed and Voided rows show in the table but add nothing, in the total row and in the total for each method. Refunds subtract.
- **Total for each method.** `HasBreakdown::breakdown(rows, filters)` (an optional interface next to `HasTotals`) returns a `ReportBreakdown`: a title, headings, rows of display text and a total row. Payments returns Method, Count, In, Out and Net for the Completed rows, with a Total row (see Cashier activity and refunds below for the staff version). The page controller sends it as the `breakdown` prop, `ReportScreen` shows it as a small table under the main table, and the PDF prints it under the main table. The spreadsheet has only the normal total row. Like `totals()`, it works on the rows it gets, so an export covers only the selected rows.
- **Folio ledger** (`date` is the default sort, 31 days, Landscape, Finance). Columns: `date` ("Posting date"), `folio`, `reservation`, `guest` ("Guest / payer"), `code`, `description`, `kind`, `staff`, `amount`. One row for each folio line of any kind; the row key is the line id and the amount is signed as in the ledger. **Guest / payer** is the company of a company folio, else the payer guest, else the booker of the reservation. Staff is empty for an automatic posting. The total row sums `amount`.
- **Filter `code`.** `ReportFilters` has `?string $code`. `ReportKey::hasCodeFilter()` is true for the Folio ledger only; `ReportFiltersRequest` validates `code` against the transaction codes of the property (`?code=ROOM`) and other reports ignore it. It stays in the page URL, the exports and the layout preview. A Select of the codes with **All codes** first sets it in the toolbar, and changing it clears the row selection.
- **Revenue drill-down.** The Revenue **Amount** cell links to the Folio ledger with that code and the dates of the row. A day row opens that day; a month row opens the month cut to the report's from and to dates (the row carries `from` and `to`). `ReportCellLink` (`components/pms/reports/`) is the reusable link for any cell renderer of `ReportScreen`.
- **Tax** (`period` is the default sort, 366 days, Landscape, Revenue). Columns: `period` ("Date / month"), `tax`, `registration` (hidden by default), `lines`, `amount` ("Tax amount"). The rows are the lines of kind Tax, one row for each date and tax, or for each month and tax with `group=month` (Tax is added to `ReportKey::groups()`). The row key is `{period}:{transaction_code_id}`. A tax is found by the transaction code of its lines (one code for each tax). Reversals reduce the date they were posted on. There is no Base column. The total row sums `lines` and `amount`.

### Built: Cashier activity and refunds

- **`group` on Payments.** `ReportGroup` gains **Method** ("By method") and **Staff** ("By staff"). `ReportKey::groups()` for Payments is `[Method, Staff]`, so Method is the default; Revenue and Tax keep Day and Month. The toggle in the toolbar picks the summary table under the main table, on screen and in the PDF. Rows stay one per payment, so selection and exports work as before. `ReportFiltersRequest` refuses a group the report does not list (`group=staff` on Revenue, `group=month` on Payments). The PDF prints the group under the title ("By staff"; `ReportGroup::caption()`).
- **Summaries.** `HasBreakdown::breakdown(rows, filters)` now gets the filters and picks the summary from `filters->group`. In is the Completed payments (folio and city ledger), Out is the Completed refunds (negative) and Net is In plus Out. Count is the number of Completed rows. Pending, Failed and Voided rows add nothing.
    - **By method**: Method, Count, In, Out, Net, then a Total row.
    - **By staff** (the cash-up): Staff, Method, Count, In, Out, Net. Each staff member has a row for each method and then a "{name} subtotal" row, and the Total row comes last. Staff are grouped by user id (`PaymentRow::staff_id`, not a column), so two people with the same name stay apart.
- **Subtotal rows.** `ReportBreakdown` has `subtotalRows`, the indexes of the subtotal rows in `rows`. The screen shows them bold on a muted background and the PDF prints them bold and muted.
- **Sort.** `ReportKey::defaultSort(?ReportGroup)` returns `staff` for Payments with the Staff group, else `date`. `rows()` returns the rows in date order and the sort is stable, so the rows of each staff member stay in date order. The page and the exports pass `filters->group`.
- **Filter `staff`.** `ReportFilters` has `?int $staff`. `ReportKey::hasPaymentFilters()` is true for Payments only. `ReportFiltersRequest` checks that `staff` is a user with `users.property_id` of this property; any other value is a validation error. A Select with **All staff** first sets it in the toolbar. `PaymentsReport::staffOptions()` lists the users who received a folio or city ledger payment in the dates, by name, and ignores the `staff` and `refunds` filters so the list does not shrink after a pick. The filter narrows the rows, the total row and both summaries, and travels in the page URL, the exports and the layout preview. Other reports ignore it.
- **Filter `refunds`.** `?refunds=1` shows only the refund rows (folio `payments` with `refund_of_id`) and leaves out city ledger rows. A checkbox "Refunds only" in the toolbar sets it. It travels like `staff`. Changing `staff`, `refunds`, `group` or the dates clears the row selection.
- **Column `refund_of`** ("Original receipt", hidden by default): the receipt number of the refunded payment, empty for any other row. The `refundOf` relation is eager-loaded, so there is no N+1.
- **No shift time window.** A cash-up is by business date and staff member only.

### Built: Meal counts

- **Meal counts** (`date` is the default sort, 31 days, Portrait, Kitchen, icon `utensils`). Columns: `date`, `meal`, `adults`, `children`, `total`, all visible by default. One row for each date and meal period that has guests; the row key is `{date}:{meal_period_id}` (`2026-10-07:3`). Rows come in date order and, inside a date, in the order of the meal start times.
- **Source.** `MealCounts::between(Property, from, to)` loads the units once for the whole range. For each unit it calls `MealEntitlementResolver` once with adults plus children to get the dates and periods, then adds the unit's adults and children to each of them. Two inclusions for the same period count a guest once. `MealCounts::forDate()` calls `between()` for one date, and `MealCount` keeps `guests` as the total beside the new `adults` and `children`.
- **Units counted.** Confirmed, Checked-in and Checked-out. An early check-out has already moved `departure_date`, so a unit that left early is counted only for the meals it stayed for. Cancelled and No-show units are left out. The night audit message uses the same rule.
- **Future dates** are allowed, up to the 31-day range, and use the bookings as they are now. There is no forecast badge.
- **Totals.** The total row sums `adults`, `children` and `total`.
- **Empty state.** The controller sends `hasMealPlans`, false when no rate plan of the property has a meal inclusion (`MealCounts::propertyHasMealInclusions()`). The table then shows "No rate plan includes meals" in place of "No meals in these dates".
- **Quick dates.** Today, Tomorrow, Next 7 days, This week and Next week (see below).
- Children stay one count: there is no infant or age-group split.

### Built: Night audit history and packet

- **Night audit history** (`-date` is the default sort, newest first; 366 days; Landscape; category Audit; icon `moon`). Columns: `date`, `status` (a badge), `trigger` (Scheduled or Run by staff), `run_by`, `started`, `completed` (in the property time zone), `forced`, `exceptions` ("open / total"), `occupancy` (%), `revenue` (room revenue) and `packet`, all visible by default. Split columns, hidden by default and right after the merged one: `started_date` and `started_time` (`j M Y`, `H:i`), `completed_date` and `completed_time` (same, property time zone), `exceptions_open` ("Open exceptions") and `exceptions_total` ("Exceptions"), both whole numbers. One row for each night audit with a business date in the range; the row key is the business date (`2026-10-08`). The total row sums `exceptions_open` and `exceptions_total`; `exceptions` has no figure.
- **Figures.** `occupancy` reads the `daily_statistics` snapshot and is empty when the audit has none. `revenue` is the room revenue of `DailyRevenueQuery::roomRevenueBetween()`, like the Occupancy report, and shows only for a Completed audit: the date of a Running or Failed audit is not closed yet.
- **Forced.** The screen shows "Yes" with the force reason as a tooltip. The PDF and the spreadsheets print "Yes: {reason}". An audit that was not forced is empty.
- **Packet column.** A Completed audit shows an "Open packet" link (a new tab) on the screen. There is no screen-only column mechanism, so the exports keep the column and leave it empty.
- **Default range.** `ReportKey::defaultDays()` is the number of days a report opens on when the URL has no dates, counted back from the business date and including it. It is 1 (Today) for every report and 30 for Night audit history. `ReportFiltersRequest` uses it, so the page props send the dates and the quick dates list and `ReportScreen` need no special case. The standard quick dates include **Last 30 days**, so the picker shows the default.
- **Packet** (`GET /p/{code}/reports/night-audit-history/{date}/packet`, `reports.night-audit-packet`, `reports.view`). `NightAuditPacketController` is thin; `NightAuditPacket` (`app/Services/Reports/`) finds the audit and renders `pdf/night-audit-packet.blade.php` through `PrintedPdf` (letterhead and footer, Portrait, inline). It is a 404 unless the property has a Completed audit for that `Y-m-d` date and the business date is Closed (a bad date, another property's date, a Failed audit and the open date are all 404). The content comes from `AuditResultSummary::forAudit($audit, allExceptions: true)`.
- **Sections.** (1) The header: "Night audit — {date}", closed by, trigger, completed time and "Forced: {reason}" when forced. (2) Statistics: rooms and beds available and sold, occupancy, ADR, RevPAR, room revenue, arrivals, departures, no-shows and in-house guests. (3) Revenue by code (code, description, lines, amount, total). (4) Payments by method (method, count, amount), then refunds, then a total. City ledger payments are in the methods. (5) "Meals for {next date} (as at printing)": adults, children and total from the live `MealCounts`, so a later reprint can differ; there is no snapshot table. The section is left out when no plan has a meal inclusion. (6) Every exception of the audit, open and resolved, with type, reservation, message and status. (7) The audit steps with status, processed and failed counts.
- **Morning message.** City ledger payments are now part of the payments by method of the morning message too, and the message still lists only open exceptions.
- **Run Night Audit sheet.** The Completed state has an "Open packet" link to the packet (a new tab). The sheet data has `canViewPacket` (`reports.view`), because the sheet needs only `night-audit.run`. The emails do not link to the packet.

### Built: Outstanding balance and Deposit liability

- **Source.** `FolioBalancesOnDate` (`app/Services/Reports/`) gives the balance of every folio of a property on one date with one grouped query over `folio_lines`, joined to `folios` and left-joined to `payments` through `folio_line_id`. It is scoped by `folios.property_id`, leaves out Voided folios and takes the lines with `posting_date <= date`. Lines are immutable and carry their posting date, so a past date is exact. A `HAVING` keeps only the debit or only the credit folios. For each folio it returns `charges` (Charge, Tax and Adjustment lines), `received` (payment lines that are not refunds, positive), `refunded` (refund lines, positive), `securityDeposit` and `balance` (the plain sum), so `charges - received + refunded = balance`.
- **Refunds and deposits.** A refund is a Payment-kind line whose `payments` row has `refund_of_id`. A payment line with no `payments` row, such as the direct bill settlement, counts as received. The security deposit is the negative sum of the lines whose `payments.purpose` is `security_deposit`; a refund copies the purpose, so refunds net off with no second query. The ledger line is used, not `payments.amount`, which includes any surcharge.
- **One row per folio** (key: the folio id), not per reservation. `FolioReportRows` loads the folios with the reservation, booker, payer and units in a fixed number of queries. **Guest / payer** follows the Folio ledger: the payer company, else the payer guest, else the booker.
- **Dates and status.** A unit folio shows the dates and the status of its unit. A master or third party folio shows the first arrival and the last departure of the units that were not cancelled or missed (all units when none is left) and the reservation status. **Status is today's status**, not the status on the date: the ledger is exact for a past date, but the bookings are not kept by date. The unit status is more meaningful on a unit folio (checked out, no-show), and the reservation status on a master folio.
- **Outstanding balance** (`-days` is the default sort, Landscape, Finance, icon `wallet`). Folios with a balance above 0 on the date. Columns, all visible by default: `folio`, `reservation`, `payer`, `arrival`, `departure`, `status`, `charges`, `payments`, `balance`, `days`. `payments` is paid minus refunded and shown negative, so `charges + payments = balance`. `days` ("Days since departure") is the date minus the departure date when the departure is on or before the date, else empty. A folio settled to the city ledger is at 0 and is not listed. The total row sums `charges`, `payments` and `balance`.
- Outstanding balance and Deposit liability count every payment line without a `payments` row as received, a DIRECT_BILL line included. That only differs from the reservation list for a reopened folio that was settled; it is left as is.
- **Deposit liability** (`arrival` is the default sort, Landscape, Finance, icon `shield`). Folios with a balance below 0 on the date: an advance for a stay not yet arrived, an overpaid in-house folio, or a departed guest owed a refund. Columns, all visible by default: `folio`, `reservation`, `payer`, `arrival`, `departure`, `status`, `received`, `refunded`, `charged`, `deposit` ("Security deposit", the amount still held, net of refunds) and `held` (minus the balance, shown positive). The total row sums the five money columns.
- **One-date URL.** Both take `?date=Y-m-d` (default: the business date) with the Today and Yesterday quick dates, as described under Departures and In-house.
- **Note.** `HasNote` (an optional interface next to `HasTotals`) has `note(Property, ReportFilters): ?string`; `ReportKey::note()` asks the report. When the date is not the business date the note is "Status as of today". The page sends it as the `note` prop, `ReportScreen` shows it under the title, and `pdf/report.blade.php` prints it under the dates.
- **Blank sorts last.** `ReportPage::sort()` puts rows with a null value in the sort column last, in either direction (a `days` that is empty, an unassigned In-house room).

### Built: Non-national guests and Police report

Both list the Checked in and Checked out units whose stay overlaps the range (arrival on or before the last date, departure after the first date), through `StayUnits`. Confirmed units (not arrived), Cancelled units and No-shows are left out. The Police report covers **all guests of every nationality**, which changes the old wastays idea of "foreign guests who arrived"; its Foreign only filter gives the foreign list.

- **Non-national guests** (`arrival` is the default sort, 31 days, Landscape, Revenue, icon `users`). Columns, all visible by default: `reservation`, `guest`, `nationality`, `identity` ("ID"), `id_type`, `id_number`, `room`, `arrival`, `arrival_time`, `departure`, `departure_time`, `nights`, `guests` ("Adults / children"), `adults`, `children`, `tourism_tax`. The split columns `id_type` ("ID type"), `id_number` ("ID number"), the two time columns, `adults` and `children` are hidden by default; the rest are visible. One row for each foreign unit; the row key is the unit id. **Guest** is the primary guest, else the booker. **Room** is the room or bed of the first night. **Nights** counts the nights of the stay inside the dates. The total row sums `nights`, `adults`, `children` and `tourism_tax`.
- **Foreign** is the rule of `TaxCalculator`: the unit nationality is set and is not `MY`. `UnitNationality::isForeign()` holds it and `UnitNationality::forUnits()` gives the nationality (primary guest, else booker) of all units with the guests and bookers eager-loaded, so there is no query for each unit.
- **Tourism tax.** `TourismTaxByUnit` is one grouped query over the Tax lines of the property's `foreign_guests_only` taxes (found by the transaction code of the line) with a posting date in the range. Reversals count, because they are negative. A tax line has no source, so the query follows `parent_line_id` up to two levels (a tax on a discount) to the charge, whose source names a reservation unit, a unit night or an add-on, and joins those to the unit. It reads only the folios of the reservations in the report.
- **Note.** `HasNote` shows "{n} units have no nationality. Their tourism tax may be missing." for the units staying in the dates with no nationality at all (primary guest and booker both blank), and nothing when there are none.
- **Police report** (`room` is the default sort, 31 days, Landscape, Front desk, icon `id-card`). Columns: `guest`, `reservation`, `room`, `nationality`, `gender`, `date_of_birth`, `identity` ("ID"), `id_type`, `id_number`, `address`, `phone`, `arrival`, `arrival_time`, `checked_in_at`, `checked_in_date`, `checked_in_time`, `departure`, `departure_time`, `checked_out_at`, `checked_out_date`, `checked_out_time`, `status`. The merged columns are visible by default. The split columns (`id_type`, `id_number`, the two time columns and the four check-in and check-out date and time columns) are hidden by default. One row for each guest attached to a unit (`unit_guests`); a unit with no attached guest gives one row for the booker. The row key is `{unit id}:{guest id}`. `address` is one line from the guest address fields and the country name. `checked_in_at` and `checked_out_at` are shown in the property time zone (`j M Y H:i`), like Night audit history; their `_date` (`j M Y`) and `_time` (`H:i`) columns use the same conversion. `arrival_time` and `departure_time` are the expected times of the unit (`reservation_units`, already local), as `H:i`. There is no total row.
- **Filter `foreign`.** `?foreign=1` lists only the guests whose own `nationality_code` is set and is not `MY`. `ReportFilters::foreignOnly`, `ReportKey::hasForeignFilter()` (Police report only) and a "Foreign only" checkbox in the toolbar carry it like `refunds`: in the page URL, the exports and the layout preview, validated as a boolean, and changing it clears the row selection. Other reports ignore it.
- **Country names.** Nationality, and the country in `address`, show the full name from `CountryOptions::name()` ("Singapore", not "SG") on screen, in the PDF and in the spreadsheets. A blank code shows blank.
- **ID.** `GuestIdentities` picks the latest document of the guest shown: a Passport before any other type, then the newest by `created_at` and `id`. It shows the type and the number ("Passport · A1234567"). `identity` keeps that text; `id_type` and `id_number` are its two parts, from `GuestIdentities::partsForGuests()`, with the same masking. The documents are picked from their plain columns with one query and only the picked documents are loaded and decrypted with a second one, so a selected-row export decrypts only the selected rows.
- **Masking.** A user without `guests.view-documents` sees the masked number (`•••• 4567`) on screen, in the PDF and in the spreadsheets. A user with it sees the full number (a MyKad with its dashes). The masking happens in the report service, so a row never carries a number the user may not see.
- **Activity log.** `LogIdentityExport` writes `report.exported_identity_numbers` (the user as causer, the property as subject; properties: `property_id`, `report_key`, `from`, `to`, `rows`, `format` as `pdf`, `csv` or `xlsx`) when a PDF, a spreadsheet or the layout preview PDF holds full numbers: the user has `guests.view-documents`, the `identity` or the `id_number` column is visible and at least one exported row has a value in a visible one of them. A masked export, an export with no ID and a page view write nothing. The entry holds no number.

### Categories

`ReportCategory` groups the Reports sidebar: **Front desk** (Arrivals, Departures, In-house, Police report, and Boat manifest when the Boats feature is on), **Revenue** (Occupancy, Revenue, Tax, Non-national guests), **Finance** (Payments, Folio ledger, Outstanding balance, Deposit liability) and **Kitchen** (Meal counts) and **Audit** (Night audit history; the Activity log joins it in a later item). Audit is the last group in the sidebar.

### Totals

A report may implement an optional `totals()` that returns one row of sums for its money and count columns. The table shows it in the footer, and the PDF and the spreadsheet add it as the last row. Payments also shows a summary under the table with Count, In, Out and Net (In is the Completed payments, Out the Completed refunds as a negative figure, Net is the sum), by method or by staff. Its main total row is the Net amount.

### Report filters

Some reports take one extra filter in the URL, validated by `ReportFilters` for that report only and kept in exports:

- Revenue and Tax: `group` (`day` or `month`), a toggle in the toolbar.
- Folio ledger: `code`, the transaction code string (`?code=ROOM`), a Select in the toolbar, checked against the codes of the property. **Revenue drill-down**: the Revenue Amount cell opens the Folio ledger for that date (or month) and code.
- Payments: `group` (`method` or `staff`, default `method`) picks the summary under the table; `staff`, a user id of the property (`?staff=12`), a Select in the toolbar; `refunds=1` shows refunds only, a checkbox in the toolbar. `staff` and `refunds` change the rows, the totals and both summaries.
- Police report: `foreign=1` lists foreign guests only, a checkbox in the toolbar.

## Date range and quick dates

- `DateRangePicker` gets a `presets` prop. The presets show in a **Quick dates** Select before the date trigger, with **Custom dates** when the range matches no preset (choosing it opens the calendar). The Select and the trigger sit in a toolbar row under the page header, not in the header. Reports pass: Today, Yesterday, This week, Last week, This month, Last month, This year, Last 30 days.
- **Presets count from the business date, not the clock.** At 01:00 before the audit, Today is still the open business date. The page gets `businessDate` from the server, and the browser works out the presets from it. Weeks start on Monday, in the presets and in the calendar. A preset longer than `maxDays` (This year on Arrivals) is left out of the list.
- **Quick dates for each report.** `ReportKey::datePresets()` returns a list of `ReportDatePreset` values, or null for the standard list above. The page controller sends it as the `presets` prop, `ReportScreen` passes it to `reportDatePresets(businessDate, presets)` (`resources/js/lib/date-presets.ts`), and a report that sends none keeps the standard list. Meal counts sends Today, Tomorrow, Next 7 days, This week and Next week, all counted from the business date. The longer-than-`maxDays` rule applies to every list.
- **Default range.** A report opens on Today unless `ReportKey::defaultDays()` says otherwise (Night audit history opens on the last 30 days). See [Built: Night audit history and packet](#built-night-audit-history-and-packet).
- A one-date report (In-house, Outstanding balance, Deposit liability) uses `DatePicker` with a `presets` prop: Today and Yesterday.
- `from` and `to` are in the URL query (`?from=2026-10-01&to=2026-10-07`), so a report can be bookmarked and shared. With no dates, the report opens on Today, or on its default range.
- `ReportFilters` (a Form Request plus a data object) checks that `from` is not after `to` and that the range is not longer than `maxRangeDays()`.

## Column layouts (per property)

```
report_layouts            id, property_id, report_key, orientation, timestamps
                          unique (property_id, report_key)
report_layout_columns     id, report_layout_id (FK, cascade), column_key, position, is_visible,
                          align (nullable: left, center, right)
                          unique (report_layout_id, column_key)
                          unique (report_layout_id, position)
```

- One layout per property and report, shared by every user. A printed report looks the same whoever prints it.
- No row means the code defaults: `defaultOrientation()` and `columns()`.
- `ReportLayoutResolver` (`app/Services/Reports/`) merges the stored rows with `columns()`. A column added later in code goes at the end with its default visibility. A stored key that is no longer in `columns()` is ignored.
- At least one column stays visible.
- **Alignment.** `ColumnAlign` has Left, Center and Right. The code sets a default for each column (numbers and money align right). A layout may override it with `report_layout_columns.align`. Null means "use the code default". A CHECK constraint allows only null, `left`, `center` or `right`. `ReportLayoutColumnData` carries `align` (the one that applies) and `defaultAlign` (the code default).
- The report page gets the resolved layout as one `ReportLayoutData` prop (the orientation and the columns in order, each with `visible`), plus `canEditLayout`.
- When the sorted column is hidden, the server falls back to the report's default sort.
- The screen table, the PDF, the CSV and the Excel file all use the resolved order and visibility. The PDF also uses the orientation. The screen table and the PDF also follow the alignment. The spreadsheets ignore it.
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
- **Alignment.** Each column row has a Left, Center and Right control. It edits the draft, so the table behind the Sheet changes at once. Preview PDF sends the alignment too.
- **Order** uses drag and drop (`@dnd-kit/sortable`). Each row has a grip handle on the left. Only the handle starts a drag, so the switch and the alignment stay clickable. The keyboard works too: focus the handle, press Space, move with the arrow keys, press Space to drop. The up and down arrows stay as a fallback. A drop edits the same draft as the arrows, so the table behind the Sheet follows at once.
- **Save** calls `UpdateReportLayout`: orientation and the full column list in order (with the alignment of each), in one transaction. An alignment equal to the code default is saved as null, so a later change of the default still shows. It refuses a list whose keys do not match `columns()`.
- **Cancel**, or closing the Sheet, puts the table back. A dirty Sheet asks before it closes.
- **Reset to default** asks first, then calls `ResetReportLayout`, which deletes the layout. This also clears the alignment overrides.
- Both log `report_layout.updated`.
- Every report PDF uses one generic view, `pdf/report.blade.php`: the letterhead, the title, the dates and the table. Preview PDF and the exports share it.
- `ReportPdf` (`app/Services/Reports/`) renders that view. Every row class implements `ReportRow`: `cells()` returns the display text by column key, so the view prints any report without knowing its columns. A report may also implement the optional `HasSections` (beside `HasTotals` and `HasBreakdown`): it returns `ReportSection`s (title, row keys in order, subtotal text, tone, printed by default). The screen draws a heading above and a subtotal below each section, and the PDF prints one table per section with a page break before each one after the first. A section not printed by default is left out of the PDF unless its rows are selected. Rows stay flat, so selection and CSV/Excel do not change. A report with sections keeps its order and its headers do not sort. The Boat manifest is the first. The preview route reads `from`, `to`, the orientation and the column list from the POST, checks the list with `ReportLayoutResolver::fromDraft()` (the same check as Save) and runs `rows()` on the server. The page gets the CSRF token for the native form from the shared `csrfToken` prop.

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

## Feature-gated reports

- `ReportKey::feature()` names the Pennant feature a report needs (`Boats` for the Boat manifest), and `isAvailable(Property)` checks it. `ReportsNavigation` leaves out an unavailable report, and the `EnsureReportIsAvailable` middleware on the reports group answers 404 to its page, exports and layout routes.

## Permissions

`reports.view` (Owner, Manager, Finance) guards report pages, exports and the night audit packet. The Layout Sheet also needs `settings.manage`. A migration calls `SyncPermissions`.

## Routes

| Route                                                     | Name                              | Permission                        |
| --------------------------------------------------------- | --------------------------------- | --------------------------------- |
| `GET /p/{code}/reports/{slug}`                            | `reports.{slug}` (one per report) | `reports.view`                    |
| `PUT /p/{code}/reports/{report}/layout`                   | `reports.layout.update`           | `reports.view`, `settings.manage` |
| `DELETE /p/{code}/reports/{report}/layout`                | `reports.layout.destroy`          | `reports.view`, `settings.manage` |
| `POST /p/{code}/reports/{report}/layout/preview`          | `reports.layout.preview`          | `reports.view`, `settings.manage` |
| `POST /p/{code}/reports/{report}/pdf`                     | `reports.pdf`                     | `reports.view`                    |
| `POST /p/{code}/reports/{report}/spreadsheet`             | `reports.spreadsheet`             | `reports.view`                    |
| `GET /p/{code}/reports/night-audit-history/{date}/packet` | `reports.night-audit-packet`      | `reports.view`                    |

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
