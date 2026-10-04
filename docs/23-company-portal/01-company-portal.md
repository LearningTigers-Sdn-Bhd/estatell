# Company portal

## Purpose

Company users book rooms, manage their own stays and read their statements without calling the front desk. One login reaches every property that has linked it to a company. This is the last module.

Terms follow the [Glossary](../overview.md#glossary): **company** is the organisation, **company user** is the person, **company link** joins them.

## Decisions

| Area              | Decision                                                                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Account           | A company user is a `users` row with `is_company_user` and no `property_id`. Staff stay 1 user : 1 property.                                      |
| Links             | `company_users` joins a company user to a company. One login can hold links to companies at many properties. There is no global "agency".         |
| Same organisation | Not enforced. A user can be linked to "Borneo Travel" at one property and "Sabah Tours" at another. The user accepts each link.                   |
| Adding people     | Same menu as staff: Invite by email, Add with temp password, Bulk add (grid or CSV/XLSX).                                                         |
| Existing account  | Adding an email that already has a company user account creates a Pending link and an in-app invitation. The user accepts it.                     |
| Remove            | Revoke the link. A property never deactivates the login itself, because the login may serve other properties.                                     |
| Browsing          | One property at a time. `/portal` lists the linked properties; the user opens one to book. A search across properties is later work.              |
| Plan access       | `rate_plans.portal_access`: Hidden, All or Selected. Selected uses `company_rate_plans`.                                                          |
| Plans at the desk | A plan linked to any company is hidden at the desk until one of those companies is selected.                                                      |
| Booking           | Confirmed at once. There is no approval queue.                                                                                                    |
| Payment           | Billed to the company (folio payer), settled through the city ledger. No online payment. Statements are read-only; the property records payments. |
| Company reference | The company's voucher or file number goes in `reservations.external_reference`.                                                                   |
| Cancel            | Free until the company's cut-off. After it, "Request cancellation" notifies the property.                                                         |
| Changes           | Company users edit guest details only. Changes to dates or rooms go through the property.                                                         |
| URL               | `/portal` (property list), then `/portal/{property:code}/...`.                                                                                    |

## Data model

```
users              + is_company_user (bool, default false)
                     CHECK exactly one kind:
                       admin         is_admin,     NOT is_company_user, property_id IS NULL
                       company user  is_company_user, NOT is_admin,     property_id IS NULL
                       staff         NOT is_admin, NOT is_company_user, property_id IS NOT NULL
company_users      id, company_id, user_id (nullable), email, status (CompanyUserStatus),
                   token_hash (nullable), expires_at (nullable), last_sent_at (nullable),
                   invited_by, accepted_at, revoked_at, timestamps
                   UNIQUE (company_id, email) WHERE status IN (Pending, Active)
                   CHECK email = lower(email)
                   CHECK status = Active → user_id IS NOT NULL AND accepted_at IS NOT NULL
                   CHECK status = Revoked → revoked_at IS NOT NULL
                   CHECK token_hash IS NULL OR user_id IS NULL
companies          + portal_enabled (bool, default false)
                   + portal_cancel_cutoff_days (int, nullable)
rate_plans         + portal_access (PortalAccess), default Hidden
reservations       + cancellation_requested_at, cancellation_requested_by (nullable)
```

- `company_users` is both the link and its invitation. `staff_invitations` is not used for company users.
- `token_hash` is set only for an email invitation to a new person (no account yet). It is the SHA-256 of the token, as for staff.
- The property of a link is `companies.property_id`. Every portal query is scoped through the user's Active link for the property in the URL.

## Enums

- `CompanyUserStatus`: Pending, Active, Revoked.
- `PortalAccess`: Hidden, All, Selected.
- `ReservationSource`: `Agent` becomes `Portal`.

## Adding company users

The company's Portal tab has one **Add company user** menu, the same three items as [Staff](../03-staff/01-staff-and-invitations.md#add-staff-menu). Each email is checked the same way in all three:

| Email is…                      | Result                                                                                                                               |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| New                            | Invite: Pending link with a token, emailed. Temp password and bulk: create the company user with a temp password and an Active link. |
| An existing company user       | Pending link, no token. In-app and mail notification `CompanyLinkInvitation`.                                                        |
| A staff or admin account       | Error: "This email belongs to a hotel staff account."                                                                                |
| Already Pending or Active here | Error: "This person is already a portal user of this company."                                                                       |

```
Bulk add portal users — Borneo Travel
 #  Name     Email                     Result
 1  Aisyah   aisyah@borneotravel.my    ⓘ This person already has an estatell account.
                                         We'll send them an invitation. Ask them to accept it.
 2  Ravi     ravi@borneotravel.my      ✓ New, temp password
 3  Lim      lim@hotel-b.my            ✗ This email belongs to a hotel staff account.
```

- The message never names another property or company.
- The temp-password export lists only rows that got a temp password.
- A property can reset a company user's temp password only while the user still has `must_change_password` and has no Active link at another property.

## Rules

- The `portal` middleware lets a user in when they are a company user, have an Active link to a company of the property in the URL, the company has `portal_enabled`, and the property is Live. Anything else returns 404.
- A Suspended company can still view its bookings and statements. It cannot book or cancel.
- Company users never get staff roles. Every company user of a company has the same access.
- A company user sees plans with `portal_access` All, and Selected plans linked to their company. A Selected plan needs at least one linked company.
- A portal booking is refused, with no override, when:
    - The rooms are not available.
    - A restriction fails (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).
    - A child age is missing.
    - A room's `max_children` is exceeded.
    - The company's credit limit would be exceeded (see [Credit control](../15-city-ledger/04-credit-control-and-reports.md)).
    - A room has no guest name.
- A new portal booking has `source` Portal, `company_id` set, `created_by` the company user, and the company as folio payer. Its status is Confirmed.
- Cancel: before `arrival_date - portal_cancel_cutoff_days`, the company user cancels. After it, or when the cut-off is empty, they can only request. A request sets `cancellation_requested_at` and notifies the property. Staff decide on any charge.
- A company user sees only reservations of the company linked at that property.
- Guest edits and cancellations go into the reservation's activity log with the company user as causer.
- Only a platform admin can deactivate a company user's login.

## Actions

| Action                                   | Does                                                                                                                                          |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `InviteCompanyUser`                      | New email: Pending link with token, emailed. Existing company user: Pending link, in-app. Logs `company-user.invited`                         |
| `CreateCompanyUserWithTemporaryPassword` | New email only: creates the user and an Active link. Existing company user: same as invite                                                    |
| `BulkCreateCompanyUsers`                 | All or nothing, each row as above                                                                                                             |
| `AcceptCompanyLink`                      | Token (new person): creates the user, activates the link. Signed in: activates the user's own Pending link. Logs `company-user.link-accepted` |
| `DeclineCompanyLink`                     | The user declines their own Pending link (sets Revoked)                                                                                       |
| `ResendCompanyLinkInvitation`            | New token and expiry, or a new in-app notification                                                                                            |
| `RevokeCompanyLink`                      | Sets Revoked. Logs `company-user.link-revoked`                                                                                                |
| `CreatePortalReservation`                | Runs the checks above, then the shared reservation Actions from module 12                                                                     |
| `CancelPortalReservation`                | Cancels before the cut-off                                                                                                                    |
| `RequestPortalCancellation`              | Sets the request columns and notifies the property                                                                                            |
| `UpdatePortalReservationGuests`          | Edits guest names, phone and ID before arrival                                                                                                |

## Pages

```
/portal                                Properties: linked properties, open balance, upcoming arrivals,
                                       pending invitations [Accept] [Decline]
/portal/{code}                         Dashboard for one property
/portal/{code}/book                    Dates → rooms (pax + child ages) → plan → guest names → confirm
/portal/{code}/bookings                List (search by reference or company reference) and detail
                                       Detail: [Edit guests] [Cancel] or [Request cancellation]
/portal/{code}/statements              AR invoices, payments, statement PDF (read-only)
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

## Staff side

```
City ledger → Borneo Travel → tab "Portal"
[x] Portal enabled
Company users can cancel up to [7] days before arrival
Portal users                                   [Add company user ▾]
  aisyah@borneotravel.my   Active             [Revoke]
  ravi@borneotravel.my     Temp password · 9d [Copy message] [Revoke]
  ops@…                    Invited · expires 3 Oct  [Resend] [Revoke]
```

The Portal tab uses `company.manage`. No new permission.

## Notifications

`PortalBookingCreated`, `PortalBookingCancelled`, `PortalCancellationRequested` (to property staff) and `CompanyLinkInvitation` (to the company user). See [Notification types](../04-notifications/01-notification-types.md).

## Needs

City ledger (15), restriction check and child prices (08), reservation Actions (12), notifications (04), temp passwords (03).

## Lessons from wastays

- One agency account was linked to many hotels, with a "may book" switch per hotel (`hotel_corporate_accounts.agent_booking_enabled`). estatell keeps the one login across properties, but through per-property companies and `company_users`, with no global agency table.
- 11 controllers and 6 services, with 29 commits between March and September 2026.
- TA access had four modes (hidden, all, all except, only) and a separate `rate_plan_agency_rules` table. Selected covers "only these". "All except" is dropped.
- Gaps found in testing: agents could book restricted dates, exceed a room's child limit, and book several rooms with one unnamed.
