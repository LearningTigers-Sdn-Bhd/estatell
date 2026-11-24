# Group mode

## Purpose

A manager of more than one property of a group (Tanini: Sepilok and Kinabatangan) sees them together, without signing in again: one dashboard with today at every property, then a group timeline and consolidated reports. Plan items 1.2 and 7.4. It builds on [Property groups and multi-property access](04-property-groups.md).

**Status:** built 2026-11-22: group mode, the group dashboard, the group timeline and group reports.

## Who sees it

- A staff user with an active membership at **two or more Live properties of one group**. Anyone else gets a 404 on `/g/{group}`, and the property menu offers no group.
- What they see of each property follows **their role there** (`GroupProperties::permissionsFor()`, one query for all properties). A property where the role shows none of a page's figures says so instead.
- A support session (view as staff) opens group mode only when the support user may view every property of the group as staff (an admin always may; a partner only for their own properties). The menu offers the staff member's other properties and groups the same way; opening another property goes through the view-as switch.
- Opening group mode stores the group in `users.last_property_group_id`; sign-in lands back on the group dashboard while the user may still open two or more of its properties. Opening a property clears it. Only page visits count: a hover prefetch or the property logo image does not.
- Single-property staff see no change anywhere.

## Navigation

- **Property menu** (navbar): an **All properties** section lists the groups the user may open (`groupSwitcher`), above the properties. In group mode the identity shows the group name and "All properties", and the menu lists the group's properties to open one.
- **Back to the group in one click**: inside a property, the sidebar starts with an **All properties** section linking to each group dashboard the user may open (`app-sidebar.tsx`, from `groupSwitcher`). The property menu offers it too.
- **Group sidebar**: one Group context (`NavigationContext::Group`, built by `GroupNavigation`), labelled with the group's name. No folio, night audit or settings: those always belong to one property.
- Group pages have no current property: no business date in the footer, no permission team. Every link into a property names it (`/p/{code}/...`).
- Routes: `routes/group.php` under `/g/{propertyGroup}` (`group.*`), behind `EnsureGroupAccess`, which puts the properties on the request (`groupProperties`) for the page and the switcher.

## Group dashboard

`GET /g/{group}/dashboard` (`group.dashboard`). The figures load after the page (deferred).

| Part                           | What it shows                                                                                                                                                                                                                                                                |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| All properties                 | Arrivals and departures (to do of total), tonight's occupancy (units sold over units on sale, summed across properties), new bookings today, and revenue this month (summed only when every property has one currency, with "1 of 2 properties" when a role hides it at one) |
| One card per property, by name | Its ID and own business date, the same figures from `DashboardTiles` (the property dashboard's numbers), and **Open** to its dashboard                                                                                                                                       |

Each property counts on its own business date, as its own dashboard does. Queries grow with the number of properties shown, never with bookings (tested).

## Group timeline

`GET /g/{group}/timeline` (`group.timeline`), Group › Timeline View. It takes the same `from` and `to` as a property's Timeline View (`StayViewTimelineRequest`: 14 nights from today by default, 30 at most) and shows those dates for every property whose role shows the front desk (`front-desk.view`), one card each, by name.

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

- `from` is the first month (`Y-m`, this month when missing) and `months` the number of months: 3, 6, 12, 18 or 24 on screen, 1 to 24 accepted (6 when missing). Earlier and later move one month.
- Each property's History & forecast for those months (`HistoryForecastReport`), added up by month (`GroupForecast`): closed dates are actual, later dates are the bookings on hand, each property by its own business date. The **Basis** badge says Actual, Actual + on the books, or On the books.
- **Last year** is the same month a year before, from closed dates only (`daily_statistics`). A month with no closed dates a year back (before go-live) shows "—". **Change** is occupancy minus last year's, in points.
- Columns: Available, Sold, Occupancy, Last year, Change, ADR, Pax, Room revenue, Total revenue (room + extra), Room revenue last year. A bar chart shows occupancy on the books beside last year by month.
- The **All properties / one property** switch works on the loaded rows (no request). Money in the all-properties rows is shown only when every property uses one currency.
- Only properties whose role shows reports (`reports.view`) count; the others are named ("Not included: …").
- **Export CSV** (`group.forecast.export`): every row, each property month by month, then All properties.
- Queries grow with the number of properties, never with bookings or months (tested).

A single property gets the same look-ahead in Reports › History & forecast: the quick dates now include **Next 3 months**, **Next 6 months** and **Next 12 months** (today to the end of the last whole month).

## Code

| Part                                                            | Role                                                                                   |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `GroupProperties`                                               | The groups a user may open, a group's properties for them, their permissions at each   |
| `EnsureGroupAccess`                                             | Lets in two or more Live properties; a support session only where it may view them all |
| `GroupNavigation`                                               | The group sidebar                                                                      |
| `GroupDashboard`, `GroupDashboardController`, `group/dashboard` | The dashboard                                                                          |
| `GroupForecast`, `GroupForecastController`, `group/forecast`    | The forecast and its CSV                                                               |
| `app-navbar.tsx`                                                | The group entries and identity                                                         |

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
