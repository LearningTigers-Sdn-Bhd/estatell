# Company portal

## Purpose

Companies book rooms, manage their own stays and read their statements without calling the front desk. A company signs in with one portal login. The same login reaches every property that has set it on its own company record. This is the last module.

Terms follow the [Glossary](../overview.md#glossary): **company** is the organisation, **company user** is its portal login, **company link** joins the login to a company record at one property.

## Decisions

| Area              | Decision                                                                                                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| One login         | A company has at most one portal login at a property. There is no list of portal users and no roles inside a company.                                                      |
| Account           | The login is a `users` row with `is_company_user` and no `property_id`. Staff stay 1 user : 1 property.                                                                    |
| Many properties   | One login can be linked at many properties, one company record at each. There is no global "agency".                                                                       |
| Same organisation | Not enforced. Each property names its own record: "Borneo Travel" at one, "Borneo Travel Sdn Bhd" at another.                                                              |
| Where it is set   | A **Portal login email** field on the company: the Add company Sheet, the company's Details tab, Bulk add and the onboarding Companies table. No separate user management. |
| New email         | Saving creates the login with a temp password. It takes the company name. The property shares the sign-in details with **Copy message**.                                   |
| Existing login    | Saving creates a Pending link and an invitation (in-app and mail). The company accepts it in the portal.                                                                   |
| Portal on or off  | The portal is on while the company has an Active link. Clearing the email turns it off. There is no separate switch.                                                       |
| Login email       | Separate from the contact email. The contact email gets invoices (often accounts@…); the login is usually the booking person (booking@…).                                  |
| Remove            | Clearing the email revokes the link. A property never deactivates the login itself, because other properties may use it. Only a platform admin can.                        |
| Browsing          | One property at a time. `/portal` lists the linked properties; the user opens one to book. A search across properties is later work.                                       |
| Plan access       | `rate_plans.portal_access`: Hidden, All or Selected. Selected uses `company_rate_plans`.                                                                                   |
| Plans at the desk | A plan linked to any company is hidden at the desk until one of those companies is selected.                                                                               |
| Booking           | Confirmed at once. There is no approval queue.                                                                                                                             |
| Payment           | Billed to the company (folio payer), settled through the city ledger. No online payment. Statements are read-only; the property records payments.                          |
| Company reference | The company's voucher or file number goes in `reservations.external_reference`.                                                                                            |
| Cancel            | Free until the company's cut-off. After it, "Request cancellation" notifies the property.                                                                                  |
| Changes           | Company users edit guest details only. Changes to dates or rooms go through the property.                                                                                  |
| URL               | `/portal` (property list), then `/portal/{property:code}/...`.                                                                                                             |

## Data model

```
users              + is_company_user (bool, default false)
                     CHECK users_one_kind_check becomes
                       num_nonnulls(property_id, partner_id) + is_admin::int + is_company_user::int = 1
                     (admin, staff, partner user or company user)
company_users      id, property_id, company_id, user_id, status (CompanyUserStatus),
                   invited_by, last_sent_at (nullable), accepted_at (nullable), revoked_at (nullable),
                   timestamps
                   UNIQUE (company_id) WHERE status IN (Pending, Active)
                   UNIQUE (property_id, user_id) WHERE status IN (Pending, Active)
                   CHECK status = Active → accepted_at IS NOT NULL
                   CHECK status = Revoked → revoked_at IS NOT NULL
companies          - portal_enabled (dropped: the portal is on while an Active link exists)
                   portal_cancel_cutoff_days (int, nullable) stays
reservations       + cancellation_requested_by (nullable, users)
                   cancellation_requested_at is built
rate_plans         + portal_access (PortalAccess), default Hidden
```

- `company_users` is the link. The first unique index keeps one login per company. The second keeps one company per login at each property, so `/portal/{code}` always knows which company the user books for.
- `property_id` is copied from the company by the Action. Every portal query is scoped by it.
- `user_id` is never null: a new email creates the login at once, with a temp password. There are no email tokens. `staff_invitations` is not used.

## Enums

- `CompanyUserStatus`: Pending, Active, Revoked.
- `PortalAccess`: Hidden, All, Selected.
- `ReservationSource`: `Agent` became `Portal`. [Booking sources](../12-reservations/11-booking-sources.md) replace the enum: the portal uses the property's **Company / Travel agent** system source.

## Portal login

One optional field, **Portal login email**, wherever a company is added or edited. Next to it is **Free cancellation until [n] days before arrival** (`portal_cancel_cutoff_days`). Saving runs the same checks everywhere:

| Email is…                                            | Result                                                                                      |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Empty                                                | No portal. An open link is revoked.                                                         |
| Unchanged                                            | Nothing happens.                                                                            |
| New to estatell                                      | Creates the company user (named after the company) with a temp password and an Active link. |
| An existing company user                             | Pending link. In-app and mail notification `CompanyLinkInvitation`.                         |
| A staff, admin or partner account                    | Error: "This email belongs to another kind of estatell account."                            |
| The portal login of another company at this property | Error: "This email is already the portal login of another company here."                    |

- A changed email revokes the old link first, then follows the table.
- The messages never name another property or company.
- A property can reset the temp password only while the user still has `must_change_password` and has no Active link at another property.

**Company page → Details tab**, a **Portal** form under Details:

```
Portal
 Portal login email   [ e.g. booking@borneotravel.my ]
                      Empty = no portal for this company.
 Free cancellation until [ 7 ] days before arrival   (e.g. 7)
                      Empty = they can only request a cancellation.
 Status   Temp password · 9 days     [Copy message] [Reset password]
          Waiting to accept          [Resend]
          Active
                                                          [Save]
```

- **Add company** Sheet: a third section, Portal, with the same two fields.
- **Bulk add**: `portal_email` and `cancel_days` columns, in both Simple and Detailed. After saving, the temp-password export lists only the rows that got a temp password.
- **Onboarding Companies table**: the two fields in ⌄ row details (see [Companies table](../17-onboarding/02-wizard-steps.md#companies-table)).
- Every save needs `company.manage`. No new permission.

## Rules

- The `portal` middleware lets a user in when they are a company user, have an Active link at the property in the URL, and the property is Live. Anything else returns 404.
- A Suspended company can still view its bookings and statements. It cannot book or cancel.
- Company users never get staff roles.
- A company user sees plans with `portal_access` All, and Selected plans linked to their company. A Selected plan needs at least one linked company.
- A portal booking is refused, with no override, when:
    - The rooms are not available.
    - A restriction fails (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).
    - A child age is missing.
    - A room's `max_children` is exceeded.
    - The company's credit limit would be exceeded (see [Credit control](../15-city-ledger/04-credit-control-and-reports.md)).
    - A room has no guest name.
- A new portal booking has the **Company / Travel agent** [booking source](../12-reservations/11-booking-sources.md) (the same row staff use for company bookings), `company_id` set, `created_by` the company user, and the company as folio payer. Its status is Confirmed.
- Cancel: before `arrival_date - portal_cancel_cutoff_days`, the company user cancels. After it, or when the cut-off is empty, they can only request. A request sets `cancellation_requested_at` and `cancellation_requested_by` and notifies the property. Staff decide on any charge in the [Cancel reservation](../18-front-desk/01-check-in-and-check-out.md#cancel-reservation) Dialog, which shows the request and resolves it.
- A company user sees only reservations of the company linked at that property.
- Guest edits and cancellations go into the reservation's activity log with the company user as causer.
- Only a platform admin can deactivate a company user's login (later work).

## Actions

| Action                              | Does                                                                                                                                                                                                         |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `SetCompanyPortalLogin`             | Runs the [Portal login](#portal-login) table for one company. Called by the add, edit, bulk and onboarding saves in their own transaction. Logs `company.portal-login-set` or `company.portal-login-removed` |
| `AcceptCompanyLink`                 | The signed-in user activates their own Pending link. Logs `company-user.link-accepted`                                                                                                                       |
| `DeclineCompanyLink`                | The user declines their own Pending link (sets Revoked)                                                                                                                                                      |
| `ResendCompanyLinkInvitation`       | Sends a new in-app and mail notification for a Pending link. Sets `last_sent_at`                                                                                                                             |
| `ResetCompanyUserTemporaryPassword` | A new temp password, within the reset rule above                                                                                                                                                             |
| `CreatePortalReservation`           | Runs the checks above, then the shared reservation Actions from module 12                                                                                                                                    |
| `CancelPortalReservation`           | Cancels before the cut-off                                                                                                                                                                                   |
| `RequestPortalCancellation`         | Sets the request columns and notifies the property                                                                                                                                                           |
| `UpdatePortalReservationGuests`     | Edits guest names, phone and ID before arrival                                                                                                                                                               |
| `UpdatePortalCompanyDetails`        | Saves contact, tax numbers and billing address for the company of the property in the URL                                                                                                                    |

## Pages

```
/portal                                Properties: linked properties, open balance, upcoming arrivals,
                                       pending invitations [Accept] [Decline]
/portal/{code}                         Dashboard for one property
/portal/{code}/book                    Dates → rooms (pax + child ages) → plan → guest names → confirm
/portal/{code}/bookings                List (search by reference or company reference) and detail
                                       Detail: [Edit guests] [Cancel] or [Request cancellation]
/portal/{code}/statements              AR invoices, payments, statement PDF (read-only)
/portal/{code}/company                 Company details: contact, tax numbers and billing address (see below)
/account/...                           Profile, password, 2FA (shared account pages)
```

```
/portal
 Your properties
  Sandbay Resort     Borneo Travel   Open balance RM 4,200   3 arrivals   [Open]
  Kinabalu Lodge     Borneo Travel   Open balance RM 0       —            [Open]
 Invitations
  Tip Top Hostel     Borneo Travel                                       [Accept] [Decline]
```

## Company details

The company user keeps the company's contact, tax numbers and billing address up to date. Hotel staff may also edit the tax numbers and address until the company ticks **"Only we manage our tax and billing details"**, which sets `companies.profile_managed_by_company` (see [Companies](../15-city-ledger/01-companies.md#data-model)).

- Fields: the consent tick, contact email, contact phone, `brn`, `tin`, `sst_registration_number`, `billing_address_line1`, `billing_address_line2`, `billing_city`, `billing_state_code`, `billing_postal_code`, `billing_country_code`.
- Each property has its own company row, so a change applies only to the property in the URL.
- `UpdatePortalCompanyDetails` saves them and logs `company.updated` with the company user as causer.

## Notifications

`PortalBookingCreated`, `PortalBookingCancelled`, `PortalCancellationRequested` (to property staff) and `CompanyLinkInvitation` (to the company user). See [Notification types](../04-notifications/01-notification-types.md).

## Needs

City ledger (15), restriction check and child prices (08), reservation Actions (12), notifications (04), temp passwords (03).

## Lessons from wastays

- One agency account was linked to many hotels, with a "may book" switch per hotel (`hotel_corporate_accounts.agent_booking_enabled`). estatell keeps the one login across properties, but through per-property companies and `company_users`, with no global agency table.
- 11 controllers and 6 services, with 29 commits between March and September 2026.
- TA access had four modes (hidden, all, all except, only) and a separate `rate_plan_agency_rules` table. Selected covers "only these". "All except" is dropped.
- Gaps found in testing: agents could book restricted dates, exceed a room's child limit, and book several rooms with one unnamed.
