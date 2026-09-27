# Admin properties

## Purpose

One place for platform admins to find any property and see its details. Later admin actions (features, suspend, dorm strategy, View as) live on the property page.

## Pages

| Page         | URL                                     | Name                        | Archetype |
| ------------ | --------------------------------------- | --------------------------- | --------- |
| List         | `GET /admin/properties`                 | `admin.properties.index`    | Index     |
| Show         | `GET /admin/properties/{code}`          | `admin.properties.show`     | Detail    |
| Staff tab    | `GET /admin/properties/{code}/staff`    | `admin.properties.staff`    | Detail    |
| Features tab | `GET /admin/properties/{code}/features` | `admin.properties.features` | Detail    |

- Middleware: `auth`, `can:admin`. Both pages are read-only, so no `admin.2fa`.
- `{property}` binds by code (see [Routing](../routing.md#property-code)).
- The admin sidebar has a **Properties** item.

## List

- Columns: code, name, status, type, city, staff count, created date. A row click opens the show page.
- Server-side filter, sort and pagination with `spatie/laravel-query-builder`, 25 per page. Filters live in the URL: `?filter[search]=kota&filter[status]=live&filter[type]=hostel&sort=-created_at&page=2`.
- Search: a number matches `code` exactly. Other text matches `name` or `city`, ignoring case.
- Sorts: `code`, `name`, `created_at`. Default `-code` (newest first).
- Unknown filters or sorts return `400`.
- Staff count uses `withCount('users')`. The list runs a fixed number of queries.
- `PropertyDirectory` service builds the query. `AdminPropertyRowData` is one row.

## Show

- Header: name, status badge, back link to the list.
- Each tab has its own URL, so a refresh keeps the open tab and a link can point at one tab. All tabs render `pages/admin/properties/show.tsx` with a `tab` prop, and the server sends only the open tab's data (`staff` or `features`). The summary and `staffCount` come on every tab.
- Tabs: **Overview** (address, contact, registration and TIN), **Staff** (name, email, verified, created) and **Features** (see [Feature flags](03-feature-flags.md#admin-ui)).
- Summary card: code, status, type, created, live since, suspended (when set).
- `AdminPropertyData` holds the page data, with `AdminPropertyStaffData` rows.
- There is no owner role until [Staff](../03-staff/). The list shows a staff count instead.

## Later

| Addition                            | Where                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Suspend and reactivate              | Show page actions, behind `admin.2fa`                                                                     |
| Create property button              | [Account creation](../17-onboarding/01-account-creation.md)                                               |
| View as                             | [Impersonation](04-impersonation.md)                                                                      |
| Onboarding board                    | A status filter view, see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md) |
| Admin ⌘K search by code, name, city | Command palette                                                                                           |
