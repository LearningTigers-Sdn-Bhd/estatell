# Partners

## Purpose

A partner is a reseller or consultant who brings properties to estatell and helps them onboard. Admins and partners are the two roles that manage hotel onboarding. A partner sees only the properties they brought. wastays called this a `super_agent`.

## Data model

```
partners                  id, name, code_prefix (char 2, unique), signup_code (char 6, unique),
                          status (PartnerStatus), suspended_at, timestamps
users                     + partner_id (nullable, restrict on delete)
properties                + partner_id (nullable, restrict on delete)
property_code_counters    prefix (char 2, primary key), last_number (bigint)
```

- `code_prefix`: 2 capital letters, CHECK `^[A-Z]{2}$`. Set by the admin when creating the partner. It never changes. `ES` is reserved for direct properties.
- One login per partner for now: one `users` row with `partner_id`. The table allows a team later.
- A user is exactly one of admin, staff, partner user or company user (see [Platform admins](../01-platform/01-platform-admins.md#data-model)).
- `PartnerStatus`: Active, Suspended.

## Property codes

Each prefix has its own counter. A partner's properties get the partner's prefix (`AS10101`, `AS10102`). Direct properties get `ES`. The code never changes, even when the property moves to another partner. See [Properties](../02-property/01-properties.md#property-code).

## Signup link

```
app.example.com/join/K7Q2MX
        │  JoinController: an Active partner with this signup_code?
        ├─ yes ─► stores partner_id in the session ─► /register ("Joining via Andaman Stays")
        └─ no ──► /register with the notice "This link is no longer active"
                          │
                          ▼
          RegisterPropertyOwner(input, partner)  →  AS10102, partner_id set
```

- `signup_code`: 6 characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0/O, 1/I), random and unique. It is separate from the prefix, so the link is hard to guess.
- **Regenerate link** (partner or admin) issues a new code. The old link stops working at once.
- The register page reads the partner from the session, so the link survives validation errors. `CreateNewUser` re-checks that the partner is still Active when it saves. If not, the property registers as `ES`.
- A suspended partner's link shows the "no longer active" notice.

## Partner portal

`/partner`, with `auth` and `can:partner`. Its own layout (`layouts/partner-layout.tsx`) and menu (`App\Navigation\PartnerNavigation`).

```
┌ Andaman Stays ── Properties · New property · Signup link · Account ┐
│ Code     Name        Status      Step       Last active            │
│ AS10101  Pulau Inn   Onboarding  Rates 60%  2 days ago   [View as] │
│ AS10102  Reef Lodge  Live        –          today        [View as] │
└────────────────────────────────────────────────────────────────────┘
```

| Page         | URL                        | Name                       | Does                                                                         |
| ------------ | -------------------------- | -------------------------- | ---------------------------------------------------------------------------- |
| Properties   | `GET /partner`             | `partner.properties.index` | The partner's properties. Step and percentage come with the onboarding board |
| New property | `POST /partner/properties` | `partner.properties.store` | The same Sheet and `CreatePropertyForOwner` as admin, with the partner       |
| Signup link  | `GET /partner/link`        | `partner.link.show`        | Copy the link. **Regenerate link**                                           |
| Regenerate   | `POST /partner/link`       | `partner.link.regenerate`  | `RegeneratePartnerSignupCode`                                                |

- Every partner query is scoped by `properties.partner_id`.
- Partner writes carry `support.2fa` (does nothing unless `SUPPORT_REQUIRE_2FA` is on).
- Account pages (`/account`) work for partners like for admins. Staff-only account pages return 404.

## Admin

The admin sidebar gets one **Partners** item.

| Page       | URL                                         | Name                              |
| ---------- | ------------------------------------------- | --------------------------------- |
| List       | `GET /admin/partners`                       | `admin.partners.index`            |
| Create     | `POST /admin/partners`                      | `admin.partners.store`            |
| Suspend    | `POST /admin/partners/{partner}/suspend`    | `admin.partners.suspend`          |
| Reactivate | `POST /admin/partners/{partner}/reactivate` | `admin.partners.reactivate`       |
| Move       | `PUT /admin/properties/{code}/partner`      | `admin.properties.partner.update` |

- **Create partner** Sheet: partner name, prefix, user name, email, and an emailed setup link or an initial password (the same choices as an owner). `CreatePartner` creates the partner, its user, its `property_code_counters` row and its signup code in one transaction.
- List columns: name, prefix, user email, status, property count, created.
- Admin properties list gets a **Partner** column and filter.
- **Move to partner** on the admin property page: pick a partner or "Direct". `MovePropertyToPartner` changes `partner_id` only. The code stays. The old partner loses view as at once. Logged with the old and new partner.
- Every admin write carries `support.2fa` and is logged.

## Rules

- A partner can: list their properties, create a property, share and regenerate their link, and view as staff of their properties during onboarding and after go-live (see [Impersonation](../01-platform/04-impersonation.md)).
- A partner cannot: suspend a property, toggle features, edit platform settings, manage partners or move properties.
- A suspended partner cannot sign in (blocked at login and logged out, like deactivated staff). Their properties keep running and stay linked.
- Sign-in sends partners to `/partner`. Partners get 404 on `/admin` and on `/p/{code}` outside view as.

## Actions and services

- `CreatePartner`, `SuspendPartner`, `ReactivatePartner`, `RegeneratePartnerSignupCode`, `MovePropertyToPartner` (`app/Actions/Platform/`).
- `IssuePropertyCode` (`app/Actions/Property/`).
- `PartnerProperties` service: the partner's property list (`app/Services/Platform/`).
- `PropertyPolicy::viewAs`.

## Tests

- Codes: each prefix counts on its own; two sign-ups at once never share a code; moving a partner keeps the code.
- The link registers an `AS` property linked to the partner; a regenerated or suspended link registers `ES`.
- A partner sees only their properties; another partner's property returns 404.
- A partner creates a property with their prefix.
- A suspended partner cannot sign in.

## Lessons from wastays

- `super_agent` was a `users.role` value with `agent_code` on the user. estatell keeps the prefix and link on a `partners` row, so they outlive one login.
- wastays ignored an unknown code silently. estatell shows a notice, so the owner knows the link was dead.
