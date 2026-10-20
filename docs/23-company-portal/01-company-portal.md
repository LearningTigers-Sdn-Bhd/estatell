# Company portal

## Purpose

Companies book rooms, manage their own stays and read their statements without calling the front desk. A company signs in with one portal login. The same login reaches every property that has set it on its own company record. This is the last module.

Terms follow the [Glossary](../overview.md#glossary): **company** is the organisation, **company user** is its portal login, **company link** joins the login to a company record at one property.

## Decisions

| Area              | Decision                                                                                                                                                                                                                                                                                                   |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| One login         | A company has at most one portal login at a property. There is no list of portal users and no roles inside a company.                                                                                                                                                                                      |
| Account           | The login is a `users` row with `is_company_user` and no `property_id`. Staff stay 1 user : 1 property.                                                                                                                                                                                                    |
| Many properties   | One login can be linked at many properties, one company record at each. There is no global "agency".                                                                                                                                                                                                       |
| Same organisation | Not enforced. Each property names its own record: "Borneo Travel" at one, "Borneo Travel Sdn Bhd" at another.                                                                                                                                                                                              |
| Where it is set   | A **Portal login email** field on the company: the Add company Sheet, the company's Portal tab, Bulk add and the onboarding Companies table. No separate user management.                                                                                                                                  |
| New email         | Saving creates the login with a temp password. It takes the company name. The property shares the sign-in details with **Copy message**.                                                                                                                                                                   |
| Existing login    | Saving creates a Pending link and an invitation (in-app and mail). The company accepts it in the portal.                                                                                                                                                                                                   |
| Portal on or off  | The portal is on while the company has an Active link. Clearing the email turns it off. There is no separate switch.                                                                                                                                                                                       |
| Login email       | Separate from the contact email. The contact email gets invoices (often accounts@…); the login is usually the booking person (booking@…).                                                                                                                                                                  |
| Remove            | Clearing the email revokes the link. A property never deactivates the login itself, because other properties may use it. Only a platform admin can.                                                                                                                                                        |
| Browsing          | One property at a time. `/portal/properties` lists the linked properties; the user opens one to book, or switches in the sidebar. A search across properties is later work.                                                                                                                                |
| Plan access       | `rate_plans.company_access` (Everyone, All, Selected) with `company_in_portal`, and `company_rate_plans.in_portal` per listed company. Set on the rate plan's Companies tab.                                                                                                                               |
| Plan market       | A plan can be limited to Local or International companies (`rate_plans.company_market`). The portal shows a plan only when it is open to every market or matches the company's `market`. A company with no market sees only plans open to every market. See [Market](../08-rates/01-rate-plans.md#market). |
| Plans at the desk | By the same setting: All companies plans with Front desk on, and Only these companies plans for listed companies with Front desk on, show only once a company is picked.                                                                                                                                   |
| Booking           | Confirmed at once. There is no approval queue.                                                                                                                                                                                                                                                             |
| Booking form      | Follows the staff booking form layout. The rate plan is picked before the room type. Each line has a Rooms quantity. Guests are new records, with no autocomplete.                                                                                                                                         |
| Payment           | Billed to the company (folio payer), settled through the city ledger. No online payment. Statements are read-only; the property records payments.                                                                                                                                                          |
| Company reference | The company's voucher or file number goes in `reservations.external_reference`.                                                                                                                                                                                                                            |
| Cancel            | Free until the company's cut-off. After it, "Request cancellation" notifies the property.                                                                                                                                                                                                                  |
| Changes           | Company users edit guest details only. Changes to dates or rooms go through the property.                                                                                                                                                                                                                  |
| URL               | `/portal` (dashboard), `/portal/properties` (property cards), then `/portal/{property:code}/...`.                                                                                                                                                                                                          |

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
reservations       cancellation_requested_at, cancellation_requested_by are built
rate_plans         + company_access (CompanyAccess), company_at_desk, company_in_portal
company_rate_plans + at_desk, in_portal
```

- `company_users` is the link. The first unique index keeps one login per company. The second keeps one company per login at each property, so `/portal/{code}` always knows which company the user books for.
- `property_id` is copied from the company by the Action. Every portal query is scoped by it.
- `user_id` is never null: a new email creates the login at once, with a temp password. There are no email tokens. `staff_invitations` is not used.

## Enums

- `CompanyUserStatus`: Pending, Active, Revoked.
- `CompanyAccess`: Everyone, All, Selected. See [Who can use this rate](../08-rates/01-rate-plans.md#who-can-use-this-rate).
- `ReservationSource`: `Agent` became `Portal`. [Booking sources](../12-reservations/11-booking-sources.md) replace the enum: the portal uses the property's **Company / Travel agent** system source.

## Portal login

One optional field, **Portal login email**, wherever a company is added or edited. Next to it is **Free cancellation until [n] days before arrival** (`portal_cancel_cutoff_days`). Saving runs the same checks everywhere:

| Email is…                                            | Result                                                                                                        |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Empty                                                | No portal. An open link is revoked.                                                                           |
| Unchanged                                            | Nothing happens.                                                                                              |
| New to estatell                                      | Creates the company user (named after the company) with a temp password, a verified email and an Active link. |
| An existing company user                             | Pending link. In-app and mail notification `CompanyLinkInvitation`.                                           |
| A staff, admin or partner account                    | Error: "This email belongs to another kind of estatell account."                                              |
| An open staff or partner invitation                  | The same error as a staff, admin or partner account.                                                          |
| A company user whose login an admin deactivated      | Error: "This login is not available."                                                                         |
| The portal login of another company at this property | Error: "This email is already the portal login of another company here."                                      |

- A changed email is checked first. A refusal keeps the old link; otherwise the old link is revoked, then the table applies.
- A Suspended company shows the Portal fields disabled and keeps its link.
- The portal login email may be the same as the contact email.
- The messages never name another property or company.
- **Email verification.** A login made by a property is created with `email_verified_at` set, like staff and partner temp-password accounts. The company signs in with the temp password, sets its own password and uses the portal at once, with no email step. Many companies (for example in China) cannot receive our email. **Accepted risk:** property staff could create a login for an email they don't own, and if another property later sets the same email, accept that property's invitation with it. This needs dishonest staff and a guessed email; the real owner can take the login back with Forgot password. If it is ever abused, require a verified email only in `AcceptCompanyLink`.
- A property can reset the temp password only while the user still has `must_change_password` and has no Active link at another property.

**Company page → Portal tab** (`GET .../companies/{company}/portal`, the Globe icon, between Rates and Payments), a **Portal** form:

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
- **Onboarding Companies table**: the two fields in ⌄ row details (see [Companies table](../17-onboarding/02-wizard-steps.md#companies-table)). "Ready after go-live" shows only there; on the Portal tab, Copy message and Reset password always work.
- **Sign-in before the portal pages exist**: a company user lands on a plain `/portal` page ("The company portal is coming soon"). The home redirect, sign-in responses and shared page props handle a user with no property. The portal property list replaces the page.
- Every save needs `company.manage`. No new permission.

## Rules

- The `portal` middleware lets a user in when they are a company user, have an Active link at the property in the URL, and the property is Live. Anything else returns 404.
- A Suspended company can still view its bookings and statements. It cannot book or cancel.
- Company users never get staff roles.
- A company user sees active nightly plans that are All companies with Portal on, and Only these companies plans where their company's row has Portal on. Everyone plans and Day use plans are never offered. Only derived plans can be Only these companies.
- A company user sees only reservations of the company linked at that property. Another company's or property's booking or document returns 404.

### Booking

The form follows the staff [booking form](../12-reservations/05-booking-form.md): a Stay card, a lines table, a guests table and a summary rail. It keeps only the portal's fields, and it picks the **rate plan before the room type**, because a company books on its own contract rate.

```
/portal/{code}/bookings/new
┌ Stay ───────────────────────────────────────────────┐  ┌ Summary ──────────────────┐
│ Arrival [21 Oct 2026] (2 nights) Departure [23 Oct] │  │ 21 Oct – 23 Oct · 2 nights│
│ Your reference [e.g. BT-2026-118]                   │  │ 2 × Deluxe King  RM 1,120 │
│ Group name     [e.g. Tour BT-118]                   │  │ 2 beds · Dorm    RM   160 │
└─────────────────────────────────────────────────────┘  │ Total            RM 1,280 │
┌──────────────────┬──────────────────┬─────┬──────┬─────┬──────────┬──┐ │ Includes Breakfast.       │
│ RATE PLAN        │ ROOM TYPE        │ROOMS│ADULTS│CHILD│     RATE │  │ │ Billed to Borneo Travel   │
├──────────────────┼──────────────────┼─────┼──────┼─────┼──────────┼──┤ │ Free cancellation until   │
│ BT Contract    ▾ │ Deluxe King    ▾ │ [2] │ [2]  │ [1] │RM 1,120 ⓘ│✕ │ │ 14 Oct 2026               │
│ Breakfast        │ 3 left           │     │      │     │          │  │ │ Guests                    │
│   Age of child 1 [6]        (applies to each room)               │  │ │  Tan Wei Ming  Booker     │
│ BT Contract    ▾ │ Dorm (mixed)   ▾ │ Beds [2]         │  RM 160 ⓘ│✕ │ │  1 room without a name    │
│ BAR            ▾ │ Pick a room type │ [1] │ [2]  │ [0] │        — │✕ │ │ [Confirm booking]         │
└──────────────────┴──────────────────┴─────┴──────┴─────┴──────────┴──┘ └───────────────────────────┘
 4 rooms · up to 20 lines                                  [+ Add line]

 GUESTS  (one lead guest per room or bed)
┌───┬────────────────────────────┬──────────────────────┬────────────────┐
│ ▾ │ [Tan Wei Ming    ] Booker  │ [+60 12-345 6789   ] │ Deluxe King #1 │
│   │ ID (optional) [IC ▾] [e.g. 900101-14-5678]                        │
│ ▸ │ [e.g. Tan Wei Ming]        │ [e.g. +60 12-345 678]│ Deluxe King #2 │
├───┴────────────────────────────┴──────────────────────┴────────────────┤
│ 1 room without a name                                                  │
└────────────────────────────────────────────────────────────────────────┘

Rate plan ▾                          Room type ▾ (for the picked plan)
 BT Contract   Breakfast         ✓    Deluxe King    RM 560 / room · 3 left   ✓
 BAR           Room only              Dorm (mixed)   RM 80 / bed · 8 beds left
 Reef Escape   3-night package        Family Suite   Min stay 3 nights        (disabled)
                                      Sea View King  Not available on 22 Oct  (disabled)
```

The layout breakpoints are the staff form's: below `xl` the summary rail moves under the guests, and below `lg` each line is a stacked card (Rate plan, Room type, Rooms, Adults, Children, Rate) and each guest is a stacked collapsible card.

**Stay**

- Arrival and Departure (no times), the nights between them, **Your reference** (optional, `external_reference`) and **Group name** (optional, shown with 2 or more units; empty shows the booker's name). The arrival cannot be before the property's business date.
- There is no Source, Company, Reservation mode or Notes. The source is always **Company / Travel agent** and the company is the one of the link.

**Lines**

- Columns: Rate plan, Room type, Rooms, Adults, Children, Rate, ✕. Up to 20 lines, the same limit as the staff form. A booking with 2 or more units is a [group](../12-reservations/02-group-bookings.md).
- **Rate plan** lists only the plans the company can see (see [Rules](#rules)). Under each name is a short line: the plan's inclusions (for example "Breakfast"), "Room only" when it has none, or "{n}-night package" for a Package plan. No price shows here, because the price depends on the room type.
- A new line takes the rate plan of the line above. With one visible plan, it is preset.
- **Room type** is disabled until a plan is picked ("Pick a rate first"). It lists only the active room types the plan sells. Each option shows its price for the line's party ("RM 560 / room", "RM 80 / bed") and what is left ("3 left", "8 beds left"). A blocked room type is disabled and shows its reason (not available on a date, a restriction, a stay length that does not fit a package). Changing the plan keeps the room type when the new plan sells it and clears it when it does not.
- **Rooms** (1–99) books that many units with the same plan and party. A dorm line shows **Beds** (1–99) instead of Rooms, Adults and Children, and books one adult for each bed.
- **Children:** one required age (0 to 17) per child, under the line. Adults, children and ages apply to each room of the line.
- **Left** is the room type's free rooms or beds on every night of the stay, less what the lines above with the same room type take. Asking for more gives "Only {n} left for these dates." on Rooms or Beds.
- **Rate** shows the line total (rooms × the price, with the auto-add items) from the server, with ⓘ for the night breakdown. There is no ✎ override. A line with no open price shows "—".
- Prices update by themselves: after a change to the dates or a line, the page asks `offers` again (debounced) and shows a spinner in the Rate cell. There is no Search button.
- When a picked room type becomes blocked after a change, the line shows ⚠ with the reason, and its Rate shows "—".
- Too many adults or children for the room type shows on Adults or Children, with the room type's limit.
- **Package plans** are offered like any nightly plan. The stay dates apply to every line and never move. When the nights of the stay differ from the package's fixed nights, every room type of that plan is blocked with the stay-length reason.

**Guests**

- One row per unit (each room, each dorm bed), built from the lines in order: "Deluxe King #1", "Deluxe King #2", "Dorm · Bed 1". The rows follow the lines. Names stay when a line changes, and a row is removed only with its unit.
- The table looks like the staff [Guests section](../12-reservations/05-booking-form.md#guests-section): one collapsible row per guest.
    - Main row: chevron, **Name** (required), **Phone** (optional) and **Stays in** (fixed text). No ✕ and no **+ Add guest**: every unit has exactly one lead guest.
    - Expanded row: **Email**, the personal details (nationality, date of birth, gender, preferred language) and the address, all optional, from the same `GuestDetailsFields` as the staff form. Then **ID** (optional): the type (`IdentityDocumentType`) and the number, the same fields as Edit guests.
    - The booker (first row) starts open, and a row with an error opens itself. The only badge is **Booker**.
    - Footer: "{n} rooms without a name".
- Name and Phone are plain inputs. There is no autocomplete and no guest search. Every guest is a new guest record.

**Summary rail**

- The stay dates and nights, one row per line (quantity, room type, line total), the total with the auto-add items ("Includes Breakfast."), **Billed to {company}**, the free-cancellation date ("Free cancellation until {date}", or "Free cancellation has ended for these dates. You can ask the property to cancel."), the guest names and **Confirm booking**.
- **Confirm booking** is disabled until every line has an open price and every unit has a guest name.
- There is no billing select: billing is always MasterPays.

**Shared with the staff form**

- The lines table header and column layout, `BookingQuoteCell` with `canOverride={false}`. The portal line rows, guest rows and summary rail (`book-summary.tsx`) are their own components, because the staff rows call staff-only endpoints (`reservations.quote`, `guests.search`, the room picker).
- Left out of the portal: arrival and departure times, Reservation mode, Source, Company, the Room / Bed column, ✎ price overrides, the restriction override, Billing, the Discounts, Add-ons and Notes tabs, guest search, Not staying and the Existing, New, VIP and Blacklisted badges.

**Saving**

- Prices come from the same `BookingQuote` as the staff form. Auto-add charge items are added by the server, shown in the summary, and cannot be removed (see [Add-ons](../12-reservations/04-add-ons.md#added-automatically)).
- The page sends the total it showed (`expected_total`). The server prices every line again and refuses when the total differs.
- A new portal booking has the **Company / Travel agent** [booking source](../12-reservations/11-booking-sources.md) (the same row staff use for company bookings), `company_id` set, billing mode MasterPays with the company as folio payer, `created_by` the company user, and the company's reference in `external_reference`. Its status is Confirmed.
- The first unit's guest is the booker. A guest ID is saved as the guest's first ID document, the same way as Edit guests. The number is never sent back to the browser.
- A portal booking is refused, with no override, and writes nothing:

| Case                                           | Message                                                                                                                                                       |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Suspended company                              | "Your company can't book at this property right now. Contact the property."                                                                                   |
| Rooms not available                            | "Not available on {date}."                                                                                                                                    |
| More rooms or beds than are left               | "Only {n} left for these dates." on the line                                                                                                                  |
| Restriction fails                              | The restriction text, e.g. "Min stay 2 nights" (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md))                           |
| Stay length does not fit a package             | The stay-length reason from the quote, on the line                                                                                                            |
| Child age missing, too many children or adults | Shown on the line                                                                                                                                             |
| A unit has no guest name                       | "Enter a guest name for each room."                                                                                                                           |
| Credit limit exceeded                          | "This booking would take the company over its credit limit. Contact the property." (see [Credit control](../15-city-ledger/04-credit-control-and-reports.md)) |
| Plan not visible to the company                | "This rate is not available. Pick another rate."                                                                                                              |
| Room type not sold by the plan                 | "This room type is not sold on this rate. Pick another room type."                                                                                            |
| Price changed                                  | "The price changed. Check the new total and confirm again." The page asks for new prices.                                                                     |
| Any other refusal                              | "This booking cannot be made. Contact the property." The code is logged, never shown.                                                                         |

### Bookings

```
/portal/{code}/bookings           [ Search our ref or yours ]
 SBR-1042  BT-2026-118  Tour BT-118  21–23 Oct  9 rooms  RM 1,280  Confirmed
 SBR-1051  BT-2026-120  Tan Wei Ming 24–25 Oct  1 room   RM 280    Cancel requested
```

- 20 per page. Search by our reference or the company's reference.
- Detail: rooms, plans, guests, add-ons, total and references. No folio internals and no staff notes.
- **Edit guests:** names, phone and ID of the booking's own guests, until check-in and not after the arrival date. Dates, rooms, plans and prices cannot change.
- **Cancel:** whole reservation only. On or before `arrival_date - portal_cancel_cutoff_days` (the page shows the date), the company user cancels with no charge. After it, or when the cut-off is empty, they can only request, once. A request sets `cancellation_requested_at` and `cancellation_requested_by` and notifies the property. Staff decide on any charge in the [Cancel reservation](../18-front-desk/01-check-in-and-check-out.md#cancel-reservation) Dialog, which shows the request and resolves it. Changes to some rooms go through the property.
- A Suspended company sees its bookings. Book, Edit guests, Cancel and Request are refused and their buttons are hidden.

### Statements

```
/portal/{code}/statements         From [1 Sep] To [19 Oct]   [Statement PDF]
 Invoices   AR-0087  1 Oct  due 31 Oct  RM 1,200  Balance 0      Settled  [PDF]
            AR-0091  9 Oct  due 8 Nov   RM 3,000  Balance 3,000  Open     [PDF]
 Payments   RCP-0040  5 Oct  Bank transfer  BT-PAY-77  RM 1,200  [Receipt]
```

- Open and Settled AR invoices, non-void AR payments, the statement with aging, and the invoice, receipt and statement PDFs, from the staff city ledger services. The invoice PDF is the staff one, including payments made at the hotel.
- Voided invoices and payments are hidden; their PDFs return 404. Allocations are not shown.
- Every document is looked up through the linked company at the property in the URL, never by id alone.
- The statement shows the true closing balance: a credit is negative. A Suspended company keeps full access.
- Guest edits and cancellations go into the reservation's activity log with the company user as causer.
- Only a platform admin can deactivate a company user's login (later work).

## Actions

| Action                              | Does                                                                                                                                                                                                                                                                             |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SetCompanyPortalLogin`             | Runs the [Portal login](#portal-login) table for one company. Called by the add, edit, bulk and onboarding saves in their own transaction. Logs `company.portal-login-set` or `company.portal-login-removed`                                                                     |
| `AcceptCompanyLink`                 | The signed-in user activates their own Pending link. Refused when the company is Suspended or the property is not Live ("This property is not open yet."), or when the one-login rules no longer hold. Marks the invitation notification read. Logs `company-user.link-accepted` |
| `DeclineCompanyLink`                | The user declines their own Pending link (sets Revoked). Marks the invitation notification read. Logs `company-user.link-declined`. The property can set the email again                                                                                                         |
| `ResendCompanyLinkInvitation`       | Sends a new in-app and mail notification for a Pending link. Sets `last_sent_at`                                                                                                                                                                                                 |
| `ResetCompanyUserTemporaryPassword` | A new temp password, within the reset rule above                                                                                                                                                                                                                                 |
| `CreatePortalReservation`           | Runs the [Booking](#booking) checks, then the shared booking Actions from module 12. Notifies `PortalBookingCreated` after commit                                                                                                                                                |
| `CancelPortalReservation`           | Cancels the whole reservation on or before the cut-off, with no charge, through `CancelUnits`. Notifies `PortalBookingCancelled`                                                                                                                                                 |
| `RequestPortalCancellation`         | After the cut-off: sets the request columns once and notifies `PortalCancellationRequested`                                                                                                                                                                                      |
| `UpdatePortalReservationGuests`     | Edits the booking's guest names, phone and ID until check-in and not after the arrival date                                                                                                                                                                                      |
| `UpdatePortalCompanyDetails`        | Saves the tick, contact, tax numbers and billing address for the company of the property in the URL. Logs `company.updated`                                                                                                                                                      |

## Pages

```
/portal                                Dashboard: pending invitations [Accept] [Decline], upcoming arrivals
/portal/properties                     Properties: linked properties, open balance, arrivals next 7 days
/portal/{code}                         Dashboard for one property
/portal/{code}/bookings                List (search by reference or company reference) [New booking]
/portal/{code}/bookings/new            Stay → lines (rate plan → room type → rooms, pax) → guests → confirm
/portal/{code}/bookings/{id}           Detail: [Edit guests] [Cancel] or [Request cancellation]
/portal/{code}/statements              AR invoices, payments, statement PDF (read-only)
/portal/{code}/company                 Company details: contact, tax numbers and billing address (see below)
/account/...                           Profile, password, 2FA (shared account pages)
```

The shell (navbar, breadcrumbs, sidebar and property switcher) is in [App shell](../design-system/09-app-shell.md#company-portal-shell).

```
/portal
 Invitations
  Tip Top Hostel     Borneo Travel                                       [Accept] [Decline]
 Upcoming arrivals (next 7 days)
  Sandbay Resort   SBR-1042  BT-2026-118  Tour BT-118   21 Oct   [View]

/portal/properties
 Sandbay Resort     Borneo Travel   Open balance RM 4,200   3 arrivals   [Open]
 Kinabalu Lodge     Borneo Travel   Open balance RM 0       —            [Open]
```

- **Dashboard** has two sections, each with an empty state. **Arrivals** list Confirmed reservations of the user's Active links at Live properties, arriving in the next 7 days from each property's business date: property, our reference, company reference, guest or group name, arrival date and a link to the booking. They are scoped by each linked company and property. There is no total open balance, because currencies differ. A full-width **Properties** card (up to 5 properties, **View all** opens `/portal/properties`) comes next, then the full-width **Recent bookings** card below them lists the 5 newest bookings across the linked Live properties (`PortalRecentBookings`): property, our reference, company reference, booking, stay, status.
- Sign-in always lands on `/portal` (the dashboard), even with one property.
- **Open balance** is the company's unpaid invoices at that property, never below 0. Stays not yet invoiced are left out. A credit shows only on the statement.
- **Arrivals** on a property card counts Confirmed reservations arriving in the next 7 days from the property's business date.
- An Active link at a property that is not Live shows greyed out with "Not open yet", or "Suspended", and no **Open**.
- Invitations show for every property, on the dashboard. estatell has no notification bell yet, so that list and the email are where an invitation is seen.
- **Property dashboard** (`/portal/{code}`): scoped to this property and the company of the user's link.

```
Sandbay Resort  [● Active]                                  [Book a stay]
Borneo Travel · Free cancellation until 7 days before arrival

[Open balance] [Overdue] [Arrivals, 7 days] [Credit available]
[Upcoming arrivals]                [Open invoices]
[Recent bookings (5)                                  View all]
```

- **Header:** the company status as a badge, and the company name with the cancellation rule ("Free cancellation until N days before arrival", or "Cancellations by request only" when the cut-off is empty). **Book a stay** is in the header. A Suspended company sees a warning ("Your company can't book at this property right now. Contact the property.") in its place, and the Book buttons in the empty states are hidden. Stats and lists stay.
- **Stats:** Open balance (unpaid AR invoices, never below 0, with the count of open invoices); Overdue (open invoices past their due date on the business date, with amount and count); Arrivals in the next 7 days from the business date, with the units checked in now; Credit available (the limit minus the credit exposure, "of {limit} limit", or "No limit"). Credit comes from the staff `CreditExposure` service, so it matches the over-limit refusal at booking. The company sees its own credit limit.
- **Lists,** each with an empty state: Upcoming arrivals (our reference, company reference, guest or group, arrival date, link to the booking), Open invoices (number, date, due, amount, balance, PDF; overdue has a danger badge, **View statements** opens the statements page) and Recent bookings (the 5 newest, **View all** opens Bookings).
- The lists reuse `PortalArrivals` and `PortalRecentBookings` with a property filter. The query count does not grow with the data.
- **Built:** the `/portal` list with Accept and Decline, the `portal` middleware (`EnsurePortalAccess`), the portal layout and sidebar (Properties; Dashboard inside a property), the property dashboard, the Statements page with its statement, invoice, payment and PDF views (`PortalStatements`), and the Company page with `UpdatePortalCompanyDetails` and the tick. Book and Bookings are built too (see below).
- **Built (Book and Bookings):** `rate_plans.company_access` (`CompanyAccess`, CHECK), `PortalRatePlans` (the visible plans and room types), `PortalBookingQuote` (offers and totals on `BookingQuote` with the auto-add items), `CreatePortalReservation`, `PortalBookingMessages` (the message table above), `PortalBookings` (list and detail), `PortalBookingRules` (cut-off and edit window on the property's business date), `UpdatePortalReservationGuests`, `CancelPortalReservation`, `RequestPortalCancellation`, the three notifications and the pages `portal/bookings/create`, `portal/bookings/index` and `portal/bookings/show`.
- **Built (portal shell):** the three-row shell, `PortalNavigation` for both sidebars, the property switcher (`portalSwitcher`), the dashboard with `PortalArrivals`, `/portal/properties`, and the booking URLs under `/bookings`. The old `/book` URLs are gone (404).
- **How it works (built):** the booking form in [Booking](#booking).
    - `create` sends the visible plans: id, name and the short line (inclusions, "Room only" or "{n}-night package").
    - `POST /portal/{code}/bookings/offers` (JSON, throttled) takes `rate_plan_id`, adults, child ages, beds and rooms for each line. It returns the room types the plan sells, each with its total, what is left (less the lines above) and a reason when blocked. It checks that the plan is visible and uses `BookingQuote`.
    - Store: each line has `rooms` (1–99), and each unit's guest has an optional email, personal details and address (the `GuestProfileRules` fields), and an optional `id_type` and `id_number`. The details go into the new guest's profile. `CreatePortalReservation` expands `rooms` into units, one guest each, refuses more than are left, and saves the ID through the Edit guests path. The server prices again and refuses `price_changed` when the total differs from `expected_total`.
    - A dorm line sends its beds and books one adult for each bed; its lead guest stays in the first bed. An ID typed in Edit guests (kind and number, no scan) replaces the number on the guest's first ID document or adds one; the number is never sent back to the browser.
    - The page uses its own `book-summary.tsx` for the summary rail, not the staff `BookingSummaryRail`, because the portal has no billing select, source or notes.
    - Tests: `PortalOffersTest`, `PortalBookingCreateTest` and `book-lines.test.ts`.

## Company details

The company user keeps the company's contact, tax numbers and billing address up to date. Hotel staff may also edit the tax numbers and address until the company ticks **"Only we manage our tax and billing details"**, which sets `companies.profile_managed_by_company` (see [Companies](../15-city-ledger/01-companies.md#data-model)).

- Fields: the consent tick, contact email, contact phone, `brn`, `tin`, `sst_registration_number`, `billing_address_line1`, `billing_address_line2`, `billing_city`, `billing_state_code`, `billing_postal_code`, `billing_country_code`.
- Each property has its own company row, so a change applies only to the property in the URL.
- `UpdatePortalCompanyDetails` saves them and logs `company.updated` with the company user as causer.
- The company can untick the box; hotel staff can then edit again. Staff never see the tick.
- A Suspended company can still edit its details.

## Notifications

`PortalBookingCreated`, `PortalBookingCancelled`, `PortalCancellationRequested` (to property staff) and `CompanyLinkInvitation` (to the company user). See [Notification types](../04-notifications/01-notification-types.md).

## Needs

City ledger (15), restriction check and child prices (08), reservation Actions (12), notifications (04), temp passwords (03).

## Lessons from wastays

- One agency account was linked to many hotels, with a "may book" switch per hotel (`hotel_corporate_accounts.agent_booking_enabled`). estatell keeps the one login across properties, but through per-property companies and `company_users`, with no global agency table.
- 11 controllers and 6 services, with 29 commits between March and September 2026.
- TA access had four modes (hidden, all, all except, only) and a separate `rate_plan_agency_rules` table. Only these companies covers "only these". "All except" is dropped.
- Gaps found in testing: agents could book restricted dates, exceed a room's child limit, and book several rooms with one unnamed.
