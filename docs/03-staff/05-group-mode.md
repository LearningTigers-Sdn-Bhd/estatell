# Group mode

## Purpose

A manager of more than one property of a group (Tanini: Sepilok and Kinabatangan) sees them together, without signing in again: one dashboard with today at every property, then a group timeline and consolidated reports. Plan items 1.2 and 7.4. It builds on [Property groups and multi-property access](04-property-groups.md).

**Status:** group mode, the group dashboard and the group timeline built 2026-11-22. Next: consolidated reports.

## Who sees it

- A staff user with an active membership at **two or more Live properties of one group**. Anyone else gets a 404 on `/g/{group}`, and the property menu offers no group.
- What they see of each property follows **their role there** (`GroupProperties::permissionsFor()`, one query for all properties). A property where the role shows none of a page's figures says so instead.
- A support session (view as staff) cannot open group mode: it covers one property.
- Single-property staff see no change anywhere.

## Navigation

- **Property menu** (navbar): an **All properties** section lists the groups the user may open (`groupSwitcher`), above the properties. In group mode the identity shows the group name and "All properties", and the menu lists the group's properties to open one.
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

## Code

| Part                                                            | Role                                                                                 |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `GroupProperties`                                               | The groups a user may open, a group's properties for them, their permissions at each |
| `EnsureGroupAccess`                                             | Lets in two or more Live properties, refuses support sessions                        |
| `GroupNavigation`                                               | The group sidebar                                                                    |
| `GroupDashboard`, `GroupDashboardController`, `group/dashboard` | The dashboard                                                                        |
| `app-navbar.tsx`                                                | The group entries and identity                                                       |

## Tests

`tests/Feature/Group/GroupDashboardTest.php` and `GroupTimelineTest.php`:

- who may open group mode, and when suspending a property closes it
- the menu entries
- figures per role with the totals
- support sessions refused
- the timeline's properties by role, and its date limit
- a constant query count for the dashboard and the timeline

The shared setup is `tests/Feature/Support/GroupSetup.php`.
