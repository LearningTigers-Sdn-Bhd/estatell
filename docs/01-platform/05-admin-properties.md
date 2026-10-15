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
- Search: a code (`ES10142`, any case) matches `code` exactly. Other text matches `name` or `city`, ignoring case.
- Sorts: `code`, `name`, `created_at`. Default `-created_at` (newest first). A code sort is a string sort, so it groups by prefix first.
- Unknown filters or sorts return `400`.
- The owner is the first active user with the property's Owner role (the first deactivated one when none is active). `PropertyOwners` (a Staff service) adds `owner_name` and `owner_email` as subqueries, so the list runs a fixed number of queries.
- `PropertyDirectory` service builds the query. `AdminPropertyRowData` is one row.
- **Create property** opens a Sheet. The admin enters the property name and type, owner name and email, then chooses a seven-day emailed setup link or an initial password. With an initial password, the admin can also mark the email as verified after confirming that the owner controls the address. The action creates an Onboarding property in Malaysia, seeds its defaults and assigns the Owner role. Success opens the new property detail page.

- A **Waiting for approval** status tab lists pending go-live requests (property, partner, sent, warnings, **Review**). See [Go live](../17-onboarding/03-go-live.md#admin-side).
- Demo properties are left out of the other tabs. A **Demo** tab lists them with **Rebuild** and the demo logins. See [Demo properties](06-demo-properties.md).

## Show

- Header: name, status badge, back link to the list.
- Each tab has its own URL, so a refresh keeps the open tab and a link can point at one tab. All tabs render `pages/admin/properties/show.tsx` with a `tab` prop, and the server sends only the open tab's data (`staff` or `features`). The summary, `staffCount` and the owner come on every tab.
- Tabs: **Overview** (address, contact, registration and TIN), **Staff** (name with a Deactivated badge, email, role, verified, created and setup-email action) **Features** (see [Feature flags](03-feature-flags.md#admin-ui)) and **OTA lists** (read only, see [Channel manager table](../17-onboarding/02-wizard-steps.md#channel-manager-table)).
- Summary card: code, owner, status, type, created, live since, suspended (when set).
- Admins with confirmed two-factor can suspend an active property or reactivate a suspended one from this page. Suspension asks for confirmation. Reactivation restores the status from before suspension.
- `AdminPropertyData` holds the page data, with `AdminPropertyStaffData` rows and `AdminOtaListRowData` rows. The OTA list rows never hold a password. **Reveal** calls `POST …/ota-lists/{credential}/reveal` and gets `{ password }` as JSON. It logs `ota_credential.revealed` without the password. **Mark processed** calls `PUT …/ota-lists/{credential}/processed` and logs `ota_credential.processed` once.
- The Staff tab sets the permission team to the property so each user's role loads. Admin routes have no team otherwise.
- An unverified Owner row shows **Send setup email**. It sends a fresh seven-day link and records the admin action.

## Later

| Addition                            | Where                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------- |
| View as                             | [Impersonation](04-impersonation.md)                                                                      |
| Onboarding board                    | A status filter view, see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md) |
| Admin ⌘K search by code, name, city | Command palette                                                                                           |
