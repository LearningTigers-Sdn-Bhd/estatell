# Admin properties

## Purpose

One place for platform admins to find any property and see its details. Admin actions live on the property page.

## Pages

| Page           | URL                                                                                       | Name                         | Archetype |
| -------------- | ----------------------------------------------------------------------------------------- | ---------------------------- | --------- |
| List           | `GET /admin/properties`                                                                   | `admin.properties.index`     | Index     |
| Create         | `POST /admin/properties`                                                                  | `admin.properties.store`     | Action    |
| Show           | `GET /admin/properties/{code}`                                                            | `admin.properties.show`      | Detail    |
| Staff tab      | `GET /admin/properties/{code}/staff`                                                      | `admin.properties.staff`     | Detail    |
| Features tab   | `GET /admin/properties/{code}/features`                                                   | `admin.properties.features`  | Detail    |
| OTA lists tab  | `GET /admin/properties/{code}/ota-lists`                                                  | `admin.properties.ota-lists` | Detail    |
| Go-live review | `GET /admin/properties/{code}/go-live`, `POST …/go-live/approve`, `POST …/go-live/reject` | `admin.properties.go-live.*` | Detail    |

- Read routes use `auth` and `can:admin`. Create, send setup email, suspend, reactivate, Reveal and Mark processed routes also use `support.2fa`.
- `{property}` binds by code (see [Routing](../routing.md#property-code)).
- The admin sidebar has a **Properties** item, with a count badge of go-live requests waiting for approval.

## List

- Columns: code, name, status, type, city, owner (name and email), partner, created date. A partner filter (`filter[partner]`, including Direct) comes with partners (see [Partners](../17-onboarding/05-partners.md#admin)). A row click opens the show page.
- Server-side filter, sort and pagination with `spatie/laravel-query-builder`, 25 per page. Filters live in the URL: `?filter[search]=kota&filter[status]=live&filter[type]=hostel&sort=-created_at&page=2`.
- Search: a code (`ES1142`, any case) matches `code` exactly. Other text matches `name` or `city`, ignoring case.
- Sorts: `code`, `name`, `created_at`. Default `-created_at` (newest first). A code sort is a string sort, so it groups by prefix first.
- Unknown filters or sorts return `400`.
- The owner is the first active user with the property's Owner role (the first deactivated one when none is active). `PropertyOwners` (a Staff service) adds `owner_name` and `owner_email` as subqueries, so the list runs a fixed number of queries.
- `PropertyDirectory` service builds the query. `AdminPropertyRowData` is one row.
- **Create property** opens a Sheet. The admin enters the property name and type, owner name and email, then chooses a seven-day emailed setup link or an initial password. With an initial password, the admin can also mark the email as verified after confirming that the owner controls the address. The action creates an Onboarding property in Malaysia, seeds its defaults and assigns the Owner role. Success opens the new property detail page.

- A **Waiting for approval** status tab lists pending go-live requests (property, partner, sent, warnings, **Review**). See [Go live](../17-onboarding/03-go-live.md#admin-side).

## Show

- Header: name, status badge, back link to the list.
- Each tab has its own URL, so a refresh keeps the open tab and a link can point at one tab. All tabs render `pages/admin/properties/show.tsx` with a `tab` prop, and the server sends only the open tab's data (`staff` or `features`). The summary, `staffCount` and the owner come on every tab.
- Tabs: **Overview** (see below), **Staff** (name with a Deactivated badge, email, role, verified, created and setup-email action) **Features** (see [Feature flags](03-feature-flags.md#admin-ui)) **OTA lists** (add, change, remove and reveal logins, see [Channel manager table](../17-onboarding/02-wizard-steps.md#channel-manager-table)) and **Channels** (only when `ChannelManager` is on, see [Admin Channels tab](../21-channels/07-admin-channels-tab.md)).
- **Overview** starts with **At a glance**: rooms and room types, rate plans (not archived), bookings made in the last 30 days, staff (and how many are verified), and features on out of all. `PropertyOverview` (`app/Services/Platform`) reads them, scoped to the property, for this tab only. **Property details** follows: address, type, contact email and phone, registration and TIN, each "Not set" when empty. While Booking engine is on it also shows **Booking website**: the microsite address with Copy link and a Published or Not published badge (a link only once published), or "API only: the owner's own website". The partner page shows the same Overview; partners can already see all of it through view as.
