# Agent portal

## Purpose

Travel agents and companies book rooms, manage their own stays and read their statements without calling the front desk. This is the last module.

## Decisions

| Area              | Decision                                                                                     |
| ----------------- | -------------------------------------------------------------------------------------------- |
| Account           | 1 user = 1 property = 1 agency (company). Same `users` table, with `company_id`.             |
| Onboarding        | Hotel staff invite agents from the company page. A company can have several users.           |
| Plan access       | `rate_plans.agent_access`: Hidden, All or Selected. Selected uses `company_rate_plans`.      |
| Plans at the desk | A plan linked to any company is hidden at the desk until one of those companies is selected. |
| Booking           | Confirmed at once. There is no approval queue.                                               |
| Payment           | Billed to the company (folio payer), settled through the city ledger. No payment gateway.    |
| Agent reference   | The agency's voucher or file number goes in `reservations.external_reference`.               |
| Cancel            | Free until the company's cut-off. After it, "Request cancellation" notifies the hotel.       |
| Changes           | Agents edit guest details only. Changes to dates or rooms go through the hotel.              |
| URL               | `/agent/...`. The property comes from the user's company.                                    |

## Data model

Changes to existing tables. There are no new tables.

```
users                  + company_id (nullable, FK companies)
                         CHECK company_id IS NULL OR is_admin = false
companies              + portal_enabled (bool, default false)
                       + agent_cancel_cutoff_days (int, nullable)
rate_plans             + agent_access (AgentAccess), default Hidden
staff_invitations      + company_id (nullable); role_id becomes nullable
                         CHECK exactly one of role_id, company_id is set
reservations           + created_by (FK users)
                       + cancellation_requested_at, cancellation_requested_by (nullable)
```

## Enums

- `AgentAccess`: Hidden, All, Selected.
- `ReservationSource`: adds Agent.

## Rules

- The `agent` middleware lets a user in when `company_id` is set, the company has `portal_enabled`, and the property is Live.
- A Suspended company can still view its bookings and statements. It cannot book or cancel.
- An agent's `property_id` is the company's property. Agents never get staff roles. Every agent user of a company has the same access.
- An agent sees plans with `agent_access` All, and Selected plans linked to their company. A Selected plan needs at least one linked company.
- An agent booking is refused, with no override, when:
    - The rooms are not available.
    - A restriction fails (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).
    - A child age is missing.
    - A room's `max_children` is exceeded.
    - The company's credit limit would be exceeded (see [Credit control](../15-city-ledger/04-credit-control-and-reports.md)).
    - A room has no guest name.
- A new agent booking has `source` Agent, `company_id` set, `created_by` the agent, and the company as folio payer. Its status is Confirmed.
- Cancel: before `arrival_date - agent_cancel_cutoff_days`, the agent cancels. After it, or when the cut-off is empty, the agent can only request. A request sets `cancellation_requested_at` and notifies the hotel. Staff decide on any charge.
- An agent sees only reservations of their own company.
- Guest edits and cancellations go into the reservation's activity log with the agent as causer.

## Actions

| Action                         | Does                                                                                   |
| ------------------------------ | -------------------------------------------------------------------------------------- |
| `InviteAgent`                  | `staff_invitations` row with `company_id`, emails the link. Logs `agent.invited`       |
| `AcceptAgentInvitation`        | Creates the user with `property_id` and `company_id`. Logs `agent.invitation-accepted` |
| `CreateAgentReservation`       | Runs the checks above, then the shared reservation Actions from module 12              |
| `CancelAgentReservation`       | Cancels before the cut-off                                                             |
| `RequestAgentCancellation`     | Sets the request columns and notifies the hotel                                        |
| `UpdateAgentReservationGuests` | Edits guest names, phone and ID before arrival                                         |

Resend, revoke, deactivate and reactivate reuse the staff Actions.

## Pages

```
/agent                 Dashboard: upcoming arrivals, in house, open balance
/agent/book            Dates → rooms (pax + child ages) → plan → guest names → confirm
/agent/bookings        List (search by reference or agent reference) and detail
                       Detail: [Edit guests] [Cancel] or [Request cancellation]
/agent/statements      AR invoices, payments, statement PDF (read-only)
/account/...           Profile, password, 2FA (shared account pages)
```

## Staff side

```
City ledger → Borneo Travel → tab "Portal"
[x] Portal enabled
Agents can cancel up to [7] days before arrival
Portal users          [Invite agent]
  aisyah@borneotravel.my   Active    [Deactivate]
  (invited) ops@…          Expires 3 Oct  [Resend] [Revoke]
```

The Portal tab uses `company.manage`. No new permission.

## Notifications

`AgentBookingCreated`, `AgentBookingCancelled` and `AgentCancellationRequested`. See [Notification types](../04-notifications/01-notification-types.md).

## Needs

City ledger (15), restriction check and child prices (08), reservation Actions (12), notifications (04).

## Lessons from wastays

- One agency account was linked to many hotels, with a "may book" switch per hotel (`hotel_corporate_accounts.agent_booking_enabled`). estatell keeps one agency per property.
- 11 controllers and 6 services, with 29 commits between March and September 2026.
- TA access had four modes (hidden, all, all except, only) and a separate `rate_plan_agency_rules` table. Selected covers "only these". "All except" is dropped.
- Gaps found in testing: agents could book restricted dates, exceed a room's child limit, and book several rooms with one unnamed.
