# Group mode

## Purpose

A manager of more than one property of a group (Tanini: Sepilok and Kinabatangan) sees them together, without signing in again: one dashboard with today at every property, then a group timeline and consolidated reports. Plan items 1.2 and 7.4. It builds on [Property groups and multi-property access](04-property-groups.md).

**Status:** built 2026-11-22: group mode, the group dashboard, the group timeline and group reports. Extended 2026-11-24: Reservations, Forecast, Property reports, and the dashboard's alerts, next six months and quick links.

## What an owner needs across properties

Analysis of 2026-11-24 (Tanini asked for more to see, manage and export from both lodges without switching). The owner's questions, and where group mode answers them:

| The owner asks                                     | Group mode                                                                                                       | Status |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ------ |
| How is every lodge doing today? Is anything stuck? | Dashboard: today's figures per property and summed, night audit alerts                                           | Built  |
| How do the next months look, against last year?    | Forecast (3–24 months) and the dashboard's next six months                                                       | Built  |
| Where is this guest booked? What do they owe?      | Reservations: one list and search, with room total and balance                                                   | Built  |
| Who is arriving, leaving or in house everywhere?   | Property reports: Arrivals, Departures, In house, Checked out                                                    | Built  |
| Money: revenue, payments, tax, what is owed        | Group reports, Property reports (Revenue, Payments, Tax, Outstanding balance, Deposit liability, Agent payments) | Built  |
| Which rooms are free across the lodges?            | Timeline View, read only                                                                                         | Built  |
| Export anything for the accountant or the bank     | CSV on Forecast, Reports, Property reports and Reservations                                                      | Built  |
| Change rates and availability for both at once     | Not yet: each property's Rates page                                                                              | Next   |
| Give a staff member access to both, in one place   | Not yet: each property's Staff › Access                                                                          | Next   |
| Group transfers and boats for the day              | Not yet: each property's run sheet and Boat Trips                                                                | Next   |
| Channel health of every property                   | Not yet: each property's Channels page                                                                           | Next   |

Changes still happen inside a property: group mode reads and exports, and every row links to the property page where the change is made. Rates, staff access and settings are per property by design (each lodge has its own licence, tax setup, channel connection and invoices), so a bulk edit across properties is a separate decision.

## Who sees it

- A staff user with an active membership at **two or more Live properties of one group**. Anyone else gets a 404 on `/g/{group}`, and the property menu offers no group.
- What they see of each property follows **their role there** (`GroupProperties::permissionsFor()`, one query for all properties). A property where the role shows none of a page's figures says so instead.
- A support session (view as staff) opens group mode only when the support user may view every property of the group as staff (an admin always may; a partner only for their own properties). The menu offers the staff member's other properties and groups the same way; opening another property goes through the view-as switch.
- Opening group mode stores the group in `users.last_property_group_id`; sign-in lands back on the group dashboard while the user may still open two or more of its properties. Opening a property clears it. Only page visits count: a hover prefetch or the property logo image does not.
- Single-property staff see no change anywhere.

## Navigation

- **Property menu** (navbar): an **All properties** section lists the groups the user may open (`groupSwitcher`), above the properties. In group mode the identity shows the group name and "All properties", and the menu lists the group's properties to open one.
- **Back to the group in one click**: inside a property, the sidebar starts with an **All properties** section linking to each group dashboard the user may open (`app-sidebar.tsx`, from `groupSwitcher`). The property menu offers it too.
- **Group sidebar**: one Group context (`NavigationContext::Group`, built by `GroupNavigation`), labelled with the group's name, in two sections (decided 2026-11-24):
    - **Overview**: Dashboard, Reservations, Timeline View.
    - **Reports**: Forecast, Performance (By property, By date, Bookings), then one item per report category that has a group report: Front desk, Revenue, Finance, Kitchen, Audit ([Property reports](#property-reports)).
      No folio, night audit or settings: those always belong to one property.
- **No page header** on group pages (decided 2026-11-24): the breadcrumb names the page, so each page starts with its own controls and the Export menu on the right, to give the tables and charts the space.
- Group pages have no current property: no business date in the footer, no permission team. Every link into a property names it (`/p/{code}/...`).
- Routes: `routes/group.php` under `/g/{propertyGroup}` (`group.*`), behind `EnsureGroupAccess`, which puts the properties on the request (`groupProperties`) for the page and the switcher.

## Group dashboard

`GET /g/{group}/dashboard` (`group.dashboard`). Redesigned 2026-11-24 for an owner reading several lodges at a glance: headline figures first, then the trend, then the lodges side by side. Everything loads after the page (two deferred props: `dashboard` for today, `forecast` for the months).

| Part                | What it shows                                                                                                                                                                                                                                                                                                           |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Needs attention     | A destructive Alert, only when a property's night audit alert is on (failed, waiting on a fix, or the business date behind), from the property dashboard's own `DashboardAlert`. Shown where the role shows reservations or reports.                                                                                    |
| Four headline cards | This month for the group, actual and on the books: Occupancy, ADR, RevPAR and Revenue (rooms and extras), each with its change against the same month last year (points for occupancy, percent for money)                                                                                                               |
| Occupancy outlook   | A line per property for the next six months, and the group's last year as a dashed line. **Forecast** opens the full page                                                                                                                                                                                               |
| Revenue this month  | A bar per property, rooms and extras stacked. Hidden when the properties use different currencies                                                                                                                                                                                                                       |
| Today by property   | One row per property: business date, arrivals and departures left of the day's total, tonight's occupancy with a bar, the next seven nights as small bars with no figures (hover or focus a bar for the night, occupancy and rooms sold; 2026-11-26), new bookings today, revenue posted this month, and a link into it |

- The headline cards and both charts read `GroupForecast` for six months from this month (`lastYearAvailable` on each row gives last year's RevPAR). The browser only works out the change against last year.
- Today by property reads each property's `DashboardTiles`, as the property dashboard does; a property whose role shows neither reservations nor reports says so in its row.

Each property counts on its own business date, as its own dashboard does. Queries grow with the number of properties shown, never with bookings (tested).

## Group timeline

`GET /g/{group}/timeline` (`group.timeline`), Group › Timeline View. It takes the same `from` and `to` as a property's Timeline View (`StayViewTimelineRequest`: 31 at most). With no dates it opens on this whole month and shows those dates for every property whose role shows the front desk (`front-desk.view`), one card each, by name.

- **Month / 2 weeks** (decided 2026-11-24; Month is the default): Month shows the whole calendar month (up to 31 nights; earlier and later move a month), 2 weeks the 14 nights from the first date. The grid is **compact** in group mode (`density="compact"`: 36pt rows, 44px nights, a narrower room column), and a night that narrow heads with the weekday's letter and the day only. The property's own Timeline View keeps its size.
- Each card is the property's own Timeline View grid (`StayViewQuery` without rates, grouped by room type), read only: no dragging, resizing, blocking or new bookings. **Open Timeline View** opens the property's own view for the same dates, where every change happens.
- Clicking a stay opens its booking in its property (`/p/{code}/reservations/{id}`), when the role there may view reservations.
- Earlier and later move the whole window. Queries grow with the number of properties, never with stays (tested).

## Group reports

`GET /g/{group}/reports` (`group.reports`), Group › Reports, with three tabs (`?tab=`). It covers the properties whose role shows reports (`reports.view`); the others are named under the dates ("Not included: …"). Dates take `from` and `to`, 366 days at most. With no dates, By property and By date open on this month, Bookings on today.

| Tab         | Rows                                                                                                                                                                                                                    | Built from                                                                                                  |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| By property | One row per property, then **All properties**: available, sold, complimentary, occupancy, ADR, RevPAR, pax, room charges, extra charges, tax, receipts. A property name opens its own History & forecast for the dates. | Each property's `HistoryForecastReport` for the dates, added up                                             |
| By date     | One row per date, the properties added together, then the total                                                                                                                                                         | The same rows, grouped by date. Each property counts a date as actual or forecast by its own business date. |
| Bookings    | Every change of every property in the dates, by time, with the property. A reservation opens in its property.                                                                                                           | Each property's `BookingChangesReport` (Rooms on books › Changes)                                           |

- Counts and money are summed; occupancy, ADR and RevPAR are worked out again from the sums, never averaged. Money is summed only when the properties use one currency (else "—").
- **Export CSV** (`group.reports.export`) gives the open tab's rows with the screen's headings, the total last.
- Queries grow with the number of properties, never with bookings (tested).

## Forecast

`GET /g/{group}/forecast` (`group.forecast`), Group › Forecast. Tanini asked for bookings 6 months ahead, perhaps a year (decided 2026-11-24: let the user pick).

- `from` is the first month (`Y-m`, this month when missing) and `months` the number of months: 3, 6, 12, 18 or 24, or **Custom…** to type any number from 1 to 24 (6 when missing). Earlier and later move one month.
- Each property's History & forecast for those months (`HistoryForecastReport`), added up by month (`GroupForecast`): closed dates are actual, later dates are the bookings on hand, each property by its own business date. The **Basis** badge says Actual, Actual + on the books, or On the books.
- **Last year** is the same month a year before, from closed dates only (`daily_statistics`). A month with no closed dates a year back (before go-live) shows "—". **Change** is occupancy minus last year's, in points.
- Columns: Available, Sold, Occupancy, Last year, Change, ADR, Pax, Room revenue, Total revenue (room + extra), Room revenue last year. A bar chart shows occupancy on the books beside last year by month.
- The **All properties / one property** switch works on the loaded rows (no request). Money in the all-properties rows is shown only when every property uses one currency.
- Only properties whose role shows reports (`reports.view`) count; the others are named ("Not included: …").
- **Export CSV** (`group.forecast.export`): every row, each property month by month, then All properties.
- Queries grow with the number of properties, never with bookings or months (tested).
- **Speed** (2026-11-26). Each future night is priced exactly as the night audit will post it (`ChargeForecast`), which took about 2.5 s per property-year, so 24 months took 10 s. Now:
    - `ForecastMonthCache` keeps each property's rows month by month for 30 minutes under a data version (business date, stays, add-ons, night prices, folio lines, taxes; one short query). Only months not in the cache are worked out, in one call. Any change makes a new version.
    - Last year asks only for closed dates (it used to price a year of future nights and drop them).
    - The page prefetches the 24-month view in the background once it is up, so the next pick comes from the cache.
    - **Performance** (Group reports) reads its dates through the same cache (`ForecastMonthCache::between()`), so a range inside months already seen opens at once.
    - Measured on the demo group: 6 months cold 3.9 s (the dashboard's next six months warm the same months), then 12 months 1.9 s, 24 months 0.6 s, any range already seen 0.1–0.3 s.

A single property gets the same look-ahead in Reports › History & forecast: the quick dates now include **Next 3 months**, **Next 6 months** and **Next 12 months** (today to the end of the last whole month).

## Reservations

`GET /g/{group}/reservations` (`group.reservations`), Group › Reservations: every property's bookings in one list, so an owner finds a guest without knowing which lodge they booked.

- Covers the properties whose role shows reservations (`reservations.view`); the others are named. Guest names show only where the role shows guests (`guests.view`), and a name search looks only there; elsewhere the row shows the reference.
- Filters in the URL: `search` (reference, short code, channel reference, or the booker's name, email or phone), `status` (one reservation status, or all), `from` and `to` (stays that touch the dates) and `property` (a property code, or all). A plain list starts today; a search looks at every date.
- Columns: Property, Booking (guest and reference; opens the booking in its property), Stay, Rooms, Pax, Status, Source, Room total and Balance in the property's currency (a balance owed shows in the danger colour). Sorted by arrival, 25 a page.
- **Export CSV** (`group.reservations.export`): every matching booking, up to 5,000.
- Built by `GroupReservations` with the same joins as the property's reservation list. Queries do not grow with the bookings on a page (tested).

## Property reports

`GET /g/{group}/property-reports` (`group.property-reports`), Group › Reports › a category (Front desk, Revenue, Finance, Kitchen, Audit): the category's reports as tabs, each run for every property at once, so an owner reads and exports both lodges without switching. `category` opens the category on its first report; `report` opens one report (and its category).

- `report` picks the report (Arrivals when missing). Offered (`ReportKey::runsInGroup()`): Arrivals, Departures, In house, Checked out, Occupancy, Revenue, Daily revenue, Payments, Tax, Outstanding balance, Deposit liability, Agent payments, Meal counts, Night audit history, Housekeeping tasks, Booking changes. Left out: reports that need a filter of one property (transaction code, staff member, boat or facility), the ones that show ID numbers (Police report, Non-national guests), and the ones group mode already adds up (History & forecast, Rooms on books).
- Dates follow the report: one `date` for a report of one date, else `from` and `to` no longer than the report allows (31 or 366 days). With none, today, or this month for a report that opens on the month. A report that groups its rows (Revenue, Tax, Payments) takes `group`.
- Rows are the report's own `rows()` for each property whose role shows reports, in property order, with a **Property** column; columns are each property's default visible ones (tax columns of every property). Totals come from the report's own totals: one per property, then **All properties** when every property uses one currency.
- The screen shows the first 500 rows; **Export CSV** (`group.property-reports.export`) has every row and the totals.
- A property without reports permission is named ("Not included: …"); one that does not use the report's feature is named too.

## Exports

Every group page with a table has one **Export** button with **Export PDF** and **Export CSV** (`GroupExportMenu`): Forecast, Performance, each report category and Reservations. Routes: `group.forecast.pdf`, `group.reports.pdf`, `group.property-reports.pdf`, `group.reservations.pdf` beside the `….export` CSV routes.

- Both files come from the same headings and rows (`GroupTableExport`), so they always match. The PDF opens in a new tab.
- The PDF is **landscape A4** (decided 2026-11-24: the group tables run to 12–16 columns, which portrait cannot fit). It has no property letterhead: it names the group and "All properties", the page and its dates or filters, and the time printed. Totals and all-properties rows print bold; numbers align right. More than 10 columns print a size smaller.

## Code

| Part                                                                              | Role                                                                                   |
| --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `GroupProperties`                                                                 | The groups a user may open, a group's properties for them, their permissions at each   |
| `EnsureGroupAccess`                                                               | Lets in two or more Live properties; a support session only where it may view them all |
| `GroupNavigation`                                                                 | The group sidebar                                                                      |
| `GroupDashboard`, `GroupDashboardController`, `group/dashboard`                   | The dashboard                                                                          |
| `GroupForecast`, `GroupForecastController`, `group/forecast`                      | The forecast and its CSV                                                               |
| `GroupReservations`, `GroupReservationsController`, `group/reservations`          | Every property's bookings in one list                                                  |
| `GroupPropertyReports`, `GroupPropertyReportController`, `group/property-reports` | A property report for every property, by category                                      |
| `GroupTableExport`, `pdf/group-table.blade.php`, `GroupExportMenu`                | The CSV and landscape PDF of every group table                                         |
| `components/pms/group/*`                                                          | The dashboard's headline cards and charts, and the Export menu                         |
| `app-navbar.tsx`                                                                  | The group entries and identity                                                         |

## Tests

`tests/Feature/Group/GroupDashboardTest.php`, `GroupTimelineTest.php` and `GroupReportsTest.php`:

- who may open group mode, and when suspending a property closes it
- the menu entries
- figures per role with the totals
- support sessions: admin allowed, partner of another property refused
- sign-in lands back in group mode, not after a prefetch or the logo
- the timeline's properties by role, and its date limit
- the reports' sums and rates, left-out properties, the date limit and the CSV
- a constant query count for the dashboard, the timeline and the reports

The shared setup is `tests/Feature/Support/GroupSetup.php`.
