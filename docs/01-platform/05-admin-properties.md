# Admin properties

## Purpose

One place for platform admins to find any property and see its details. Admin actions live on the property page.

## Pages

| Page         | URL                                     | Name                        | Archetype |
| ------------ | --------------------------------------- | --------------------------- | --------- |
| List         | `GET /admin/properties`                 | `admin.properties.index`    | Index     |
| Show         | `GET /admin/properties/{code}`          | `admin.properties.show`     | Detail    |
| Staff tab    | `GET /admin/properties/{code}/staff`    | `admin.properties.staff`    | Detail    |
| Features tab | `GET /admin/properties/{code}/features` | `admin.properties.features` | Detail    |

- Read routes use `auth` and `can:admin`. Suspend and reactivate routes also use `admin.2fa`.
- `{property}` binds by code (see [Routing](../routing.md#property-code)).
- The admin sidebar has a **Properties** item.

## List

- Columns: code, name, status, type, city, owner (name and email), created date. A row click opens the show page.
- Server-side filter, sort and pagination with `spatie/laravel-query-builder`, 25 per page. Filters live in the URL: `?filter[search]=kota&filter[status]=live&filter[type]=hostel&sort=-created_at&page=2`.
- Search: a number matches `code` exactly. Other text matches `name` or `city`, ignoring case.
- Sorts: `code`, `name`, `created_at`. Default `-code` (newest first).
- Unknown filters or sorts return `400`.
- The owner is the first active user with the property's Owner role (the first deactivated one when none is active). `PropertyOwners` (a Staff service) adds `owner_name` and `owner_email` as subqueries, so the list runs a fixed number of queries.
- `PropertyDirectory` service builds the query. `AdminPropertyRowData` is one row.

## Show

- Header: name, status badge, back link to the list.
- Each tab has its own URL, so a refresh keeps the open tab and a link can point at one tab. All tabs render `pages/admin/properties/show.tsx` with a `tab` prop, and the server sends only the open tab's data (`staff` or `features`). The summary, `staffCount` and the owner come on every tab.
- Tabs: **Overview** (address, contact, registration and TIN), **Staff** (name with a Deactivated badge, email, role, verified, created) and **Features** (see [Feature flags](03-feature-flags.md#admin-ui)).
- Summary card: code, owner, status, type, created, live since, suspended (when set).
- Admins with confirmed two-factor can suspend an active property or reactivate a suspended one from this page. Suspension asks for confirmation. Reactivation restores the status from before suspension.
- `AdminPropertyData` holds the page data, with `AdminPropertyStaffData` rows.
- The Staff tab sets the permission team to the property so each user's role loads. Admin routes have no team otherwise.

## Later

| Addition                            | Where                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Create property button              | [Account creation](../17-onboarding/01-account-creation.md)                                               |
| View as                             | [Impersonation](04-impersonation.md)                                                                      |
| Onboarding board                    | A status filter view, see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md) |
| Admin ⌘K search by code, name, city | Command palette                                                                                           |
