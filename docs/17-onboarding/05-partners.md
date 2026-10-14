# Partners

## Purpose

A partner is a reseller or consultant who brings properties to estatell and helps them onboard. Admins and partners are the two roles that manage hotel onboarding. A partner sees only the properties they brought. wastays called this a `super_agent`.

## Data model

```
partners                  id, name, code_prefix (char 2, unique), signup_code (char 6, unique),
                          status (PartnerStatus), can_create_properties (bool, default false),
                          suspended_at, timestamps
users                     + partner_id (nullable, restrict on delete)
properties                + partner_id (nullable, restrict on delete)
property_code_counters    prefix (char 2, primary key), last_number (bigint)
partner_invitations       id, partner_id (cascade on delete), email, token_hash (unique), expires_at,
                          last_sent_at, accepted_at, revoked_at, invited_by, timestamps
                          UNIQUE (partner_id) WHERE accepted_at IS NULL AND revoked_at IS NULL
                          UNIQUE (email) WHERE accepted_at IS NULL AND revoked_at IS NULL
                          CHECK email = lower(email); CHECK accepted_at and revoked_at are not both set
```

- `code_prefix`: 2 capital letters. The CHECK `partners_code_prefix_check` refuses anything but `^[A-Z]{2}$` and refuses `ES`, which is reserved for direct properties. Set by the admin when adding the partner, suggested from the name ("Andaman Stays" → `AS`) and editable. It never changes.
- One login per partner for now: one `users` row with `partner_id`. The table allows a team later.
- A user is exactly one of admin, staff, partner user or company user (see [Platform admins](../01-platform/01-platform-admins.md#data-model)).
- `PartnerStatus`: Invited, Active, Suspended.
- `can_create_properties`: may the partner create properties themselves? Off for every new partner, however it was added. The signup link works either way. wastays called this `can_create_hotels`.

| From      | Can move to                                                                        |
| --------- | ---------------------------------------------------------------------------------- |
| Invited   | Active (invitation accepted), or the row is deleted when the invitation is revoked |
| Active    | Suspended                                                                          |
| Suspended | Active                                                                             |

- `partner_invitations` has the same shape and token rules as `staff_invitations` (SHA-256 `token_hash`, unique, valid 7 days), without the property and role. See [Staff and invitations](../03-staff/01-staff-and-invitations.md).
- `partner_invitations.partner_id` cascades on delete, so revoking (which deletes the Invited partner) removes its invitation rows. The activity log on the partner keeps the history.
- One email can hold only one open invitation, across all partners. A second accept would always fail, so it is refused at invite time.
- `partners` is a platform table, so it has no `property_id`. Every partner query is scoped by `partner_id` instead.

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
- The partner finds the link in a card at the top of their Properties page (see [Partner portal](#partner-portal)), with **Copy link**, **Copy message** and ⋯ → **Regenerate link**.
- **Copy message** puts a ready-to-send text on the clipboard. The server sends `signupUrl` and the partner name; the browser builds the message, because it holds no secret:

    ```
    Hi! Set up your property on estatell with Andaman Stays.
    Register here: https://app.estatell.com/join/K7Q2MX
    We'll help you get started once you've signed up.
    ```

- **Regenerate link** asks first ("Regenerate your signup link? The old link stops working at once.") and issues a new code through `RegeneratePartnerSignupCode`. The old link stops working at once. Logged `partner.signup-link-regenerated`.
- Admins copy a partner's link from ⋯ → **Copy signup link** on the Active tab (link only, no message). It is a read, so it is not logged and has no `support.2fa`.
- The link works whether `can_create_properties` is on or off.
- The register page reads the partner from the session, so the link survives validation errors. `CreateNewUser` re-checks that the partner is still Active when it saves. If not, the property registers as `ES`.
- An Invited or Suspended partner's link shows the "no longer active" notice.

## Partner portal

`/partner`, with `auth` and `can:partner`. Its own layout (`layouts/partner-layout.tsx`) and menu (`App\Navigation\PartnerNavigation`): **Properties** only. **Account** is in the user menu.

```
┌ Andaman Stays ── Properties ───────────────────────────────────────┐
│ ┌ Your signup link ──────────────────────────────────────────────┐ │
│ │ app.estatell.com/join/K7Q2MX   [Copy link] [Copy message]  [⋯] │ │
│ │ Properties that register with this link appear here.           │ │
│ └──────────────────────────────────────── ⋯ → Regenerate link ───┘ │
│ Properties                                  [New property] ← if on │
│ Code     Name        Status      Created                           │
│ AS10101  Pulau Inn   Onboarding  2 days ago                        │
│ AS10102  Reef Lodge  Live        12 Sep                            │
└────────────────────────────────────────────────────────────────────┘
Empty list: "Share your signup link to add a property."
```

- **New property** shows only when `can_create_properties` is on. It opens the same Sheet as admin and calls `CreatePropertyForOwner` with the partner, so the code gets the partner's prefix. When the switch is off, `POST /partner/properties` returns 403.
- The list shows 25 per page, newest first.
- A row click opens the partner property page. Each row has a **View as** button (see [Impersonation](../01-platform/04-impersonation.md)). Step and percentage come later with the onboarding board.

### Property page

```
┌ ← Properties   Pulau Inn  [Onboarding] ────────────────────────────┐
│ Overview · Staff · Features                                        │
│ Staff                                                              │
│  Name         Email            Role    Verified  Created           │
│  Aisyah Rahim aisyah@pulau.my  Owner   No        2 days ago        │
│                                                                    │
│ Features                                                           │
│  Day use        Off                                                │
│  Boats          On                                                 │
│  Ask estatell support to change features.                          │
└────────────────────────────────────────────────────────────────────┘
```

- The same three tabs as the admin property page (see [Admin properties](../01-platform/05-admin-properties.md#show)), each with its own URL, rendering `pages/partner/properties/show.tsx` with a `tab` prop.
- **Staff** is read-only: the same columns, no **Send setup email**.
- **Features** is read-only: each `PlatformFeature` with On or Off, and the note "Ask estatell support to change features."
- The tab bodies live in `components/pms/properties/`, shared by the admin and partner pages. The Features tab takes `readOnly`.
- No suspend, reactivate or summary actions.

### Routes

| Page or action  | URL                                       | Name                          | Does                                              |
| --------------- | ----------------------------------------- | ----------------------------- | ------------------------------------------------- |
| Properties      | `GET /partner`                            | `partner.properties.index`    | The partner's properties and the signup link card |
| Property        | `GET /partner/properties/{code}`          | `partner.properties.show`     | Overview tab                                      |
| Staff tab       | `GET /partner/properties/{code}/staff`    | `partner.properties.staff`    | Read-only staff                                   |
| Features tab    | `GET /partner/properties/{code}/features` | `partner.properties.features` | Read-only features                                |
| New property    | `POST /partner/properties`                | `partner.properties.store`    | `CreatePropertyForOwner`; 403 when switched off   |
| Regenerate link | `POST /partner/link`                      | `partner.link.regenerate`     | `RegeneratePartnerSignupCode`                     |
| Join            | `GET /join/{signupCode}`                  | `join`                        | Guest. Stores the partner and opens `/register`   |

- Every partner query is scoped by `properties.partner_id`. Another partner's property returns 404.
- Partner writes carry `support.2fa` (does nothing unless `SUPPORT_REQUIRE_2FA` is on).
- Account pages (`/account`) work for partners like for admins, inside the partner layout. Staff-only account pages return 404.

## Admin

The admin sidebar gets one **Partners** item. Adding a partner works like adding staff, and each partner has one login, so adding a partner also creates its login.

```
Partners                                         [Add partner ▾]
 Active · Invited · Suspended                      ├─ Invite by email
                                                   ├─ Add with temp password
                                                   └─ Bulk add
┌──┬───────────────┬────────┬────────────────┬────────────┬────────────┬───────────────────┬───┐
│☐ │ Partner       │ Prefix │ Email          │ Properties │ Can create │ Status            │   │
│☐ │ Andaman Stays │ AS     │ ops@andaman.my │ 12         │ [●  ]      │ Active            │ ⋯ │
│☐ │ Kota Partners │ KP     │ kp@mail.com    │ 0          │ [  ○]      │ Temp password · 9d│ ⋯ │
└──┴───────────────┴────────┴────────────────┴────────────┴────────────┴───────────────────┴───┘
Selected (1): [Copy message] [Export ▾ CSV · XLSX · PDF] [Reset temp password]
Row ⋯ → Copy signup link · Suspend
```

| Staff                                      | Partner                                                         |
| ------------------------------------------ | --------------------------------------------------------------- |
| Active, Invited and Deactivated tabs       | Active, Invited and Suspended tabs                              |
| Invite by email (email, role)              | Invite by email (partner name, prefix, email)                   |
| Add with temp password (name, email, role) | Add with temp password (partner name, prefix, user name, email) |
| Bulk add (grid or CSV/XLSX upload)         | Bulk add, with the same four columns                            |
| Copy message, export, reset temp password  | The same                                                        |

### Invite by email

- **Invite by email** opens a Dialog with partner name, prefix (suggested from the name, editable) and email.
- `InvitePartner` creates the `partners` row as **Invited** at once through `CreatePartner`, with its `property_code_counters` row and signup code, so the prefix is reserved. Then it creates the invitation and emails the link (`PartnerInvitationNotification`, queued after commit). Logs `partner.invited`. Redirects to the Invited tab.
- `AcceptPartnerInvitation` (`/partner-invitations/{token}`, guest only, throttled 6 per minute): the person sets their name and password. Creates the user with the email verified, sets the partner Active and closes the invitation, then signs them in to `/partner`. Refused when the invitation is closed or expired, the partner is no longer Invited, or the email now has an account. Logs `partner.invitation-accepted`.
- `ResendPartnerInvitation`: a new token and a new 7-day expiry, so the old link stops working. Logs `partner.invitation-resent`.
- `RevokePartnerInvitation`: logs `partner.invitation-revoked`, then deletes the Invited partner and its counter row, which frees the prefix. Safe because an Invited partner has no user and no properties.
- An expired invitation keeps its prefix reserved until the admin resends or revokes it.
- Unknown, expired, revoked and accepted links show one notice page (`pages/partner-invitations/accept.tsx`, separate from the staff page): "This link has expired, was replaced by a newer one, or was already used. Ask estatell support for a new invitation."
- The Invited tab lists open invitations: Partner, Prefix, Email, Invited by, Sent, Expires (or **Expired**). Rows have ⋯ → **Resend** (no confirm) and **Revoke** (confirm: "Revoke the invitation to {email}? The link stops working. The prefix {prefix} is free for another partner."). No selection on this tab.
- Every action that refuses (closed, expired, not Invited) returns false and logs nothing.

### Add with temp password

- Dialog with partner name, prefix, user name and email. `CreatePartnerWithTemporaryPassword` creates the Active partner, counter row, signup code and user in one transaction. A Dialog then shows the copy message with **Copy**.
- Reuses the staff temp password pieces: the `users` temp columns, `TemporaryPasswordGenerator`, the 14-day expiry, `must_change_password` and `/account/set-password`. See [Temp passwords and bulk staff](../03-staff/03-temp-passwords.md).
- The expired message reads "This temporary password has expired. Ask estatell support for a new one."
- **Reset temp password**, **Copy message** and **Export** (CSV, XLSX, PDF) work on selected rows, as on the Staff page. Selection is on the Active tab only. Reset skips suspended partners (reactivate first) and the toast counts them. The CSV and XLSX name column is headed **Partner**, and the file is `temp-passwords-partners.{csv,xlsx,pdf}`. Copy message and Export also carry `support.2fa`, because they show live credentials.
- The copy message is fetched when its Dialog opens, also right after adding a partner, so temp passwords never sit in page props or the session. The message, export and reset Dialogs are shared with the Staff page (`components/pms/temporary-passwords/`), which passes its own routes.

### Bulk add

- A full page (`/admin/partners/bulk`), grid rows or a CSV/XLSX upload, with the columns partner name, prefix, user name and email. `BulkCreatePartners` saves all rows in one transaction or none, calling `CreatePartnerWithTemporaryPassword` per row. Each partner is logged on its own; there is no batch log.
- Upload headings are `partner_name`, `prefix`, `user_name`, `email`. Case and spaces are ignored ("Partner name" works). Up to 200 rows; blank lines are skipped. **Template** downloads the empty file as CSV or Excel. Uploaded rows are added to the grid.
- The prefix is suggested per row in the browser (the same suggestion as the Add dialog) while the row's prefix is untouched, and stays editable. The server does not suggest.
- Rows fail validation for a prefix that is not 2 letters, is `ES`, is taken, or repeats in the list, and for an email that has an account, has an open partner invitation, or repeats in the list. Errors show on their rows after **Create**.
- If another admin takes a prefix or email while saving, nothing is saved and the page shows "A prefix or email was taken while you saved. Nothing was saved."
- After saving, the page shows the new partners with **Copy all**, **Export** and **Done**, using the same message and export Dialogs. Only user ids, names and emails come back; the message is fetched when its Dialog opens. The Dialog says "partner", not "staff member".

### Routes

| Page or action              | URL                                                                                       | Name                                             |
| --------------------------- | ----------------------------------------------------------------------------------------- | ------------------------------------------------ |
| Active tab                  | `GET /admin/partners`                                                                     | `admin.partners.index`                           |
| Invited tab                 | `GET /admin/partners/invited`                                                             | `admin.partners.invited`                         |
| Suspended tab               | `GET /admin/partners/suspended`                                                           | `admin.partners.suspended`                       |
| Add with temp password      | `POST /admin/partners`                                                                    | `admin.partners.store`                           |
| Bulk add                    | `GET`, `POST /admin/partners/bulk`                                                        | `admin.partners.bulk.create`, `.store`           |
| Bulk upload, template       | `POST /admin/partners/bulk/import`, `GET /admin/partners/bulk/import-template.{csv,xlsx}` | `admin.partners.bulk.import`, `.import-template` |
| Invite                      | `POST /admin/partners/invitations`                                                        | `admin.partners.invitations.store`               |
| Resend                      | `POST /admin/partners/invitations/{id}/resend`                                            | `admin.partners.invitations.resend`              |
| Revoke                      | `DELETE /admin/partners/invitations/{id}`                                                 | `admin.partners.invitations.destroy`             |
| Copy message, export, reset | `/admin/partners/temporary-passwords/…`                                                   | `admin.partners.temporary-passwords.*`           |
| Suspend                     | `POST /admin/partners/{partner}/suspend`                                                  | `admin.partners.suspend`                         |
| Reactivate                  | `POST /admin/partners/{partner}/reactivate`                                               | `admin.partners.reactivate`                      |
| Can create properties       | `PUT /admin/partners/{partner}/can-create-properties`                                     | `admin.partners.can-create-properties.update`    |
| Accept page, accept         | `GET`, `POST /partner-invitations/{token}`                                                | `partner-invitations.show`, `.accept`            |
| Move a property             | `PUT /admin/properties/{code}/partner`                                                    | `admin.properties.partner.update`                |

### Other admin rules

- List columns: partner, prefix, user email, property count, **Can create properties**, status (with **Temp password · Nd** like staff), created.
- **Can create properties** is a `Switch` on Active rows only, with an optimistic update that rolls back on failure. `SetPartnerCanCreateProperties` saves it (`support.2fa`, body `allowed` bool) and logs `partner.create-properties-allowed` or `partner.create-properties-disallowed`. Setting the same value again does nothing. Invited and Suspended rows show no switch; suspending keeps the value.
- The Suspended tab has the same columns, with **Suspended** (the date) instead of status. Active rows have ⋯ → **Copy signup link** and **Suspend** with a confirm ("Suspend {name}? They can't sign in. Their properties keep running."). Suspended rows have ⋯ → **Reactivate** without a confirm. Only an Active partner can be suspended; an Invited one is revoked instead.
- Admin properties list gets a **Partner** column and filter.
- **Move to partner** / **Move to direct** on the admin property page. A direct property moves to an Active partner (a Dialog with a partner Select). A partner's property moves to direct (an AlertDialog, allowed even when the partner is suspended). A move from one partner to another is refused: go through direct. `MovePropertyToPartner` changes `partner_id` only. The code stays. The old partner loses view as at once. Logged as `property.partner-moved` with the old and new partner (id and name). The Partner row shows on the admin summary only.
- Every admin write carries `support.2fa` and is logged. Reading an upload writes nothing, so it does not.
- Add, Invite and Bulk add share one set of prefix and email rules (`App\Concerns\PartnerValidationRules`): emails are compared in lower case, and an email with an account or an open partner invitation is refused.
- The **Add partner** menu lives in `components/pms/partners/add-partner-menu.tsx`.

## Rules

- A partner can: list their properties, open a property page (staff and features read-only), create a property when the admin turned on **Can create properties**, share and regenerate their link, and view as staff of their properties during onboarding and after go-live (see [Impersonation](../01-platform/04-impersonation.md)).
- A partner cannot: suspend a property, toggle features, edit platform settings, manage partners or move properties.
- A suspended partner cannot sign in (blocked at login and logged out, like deactivated staff). `LogoutDeactivatedUsers` signs them out on their next request, with the message "Your partner account is suspended. Contact estatell support." Their properties keep running and stay linked.
- Sign-in sends partners to `/partner`. Partners get 404 on `/admin` and on `/p/{code}` outside view as.

## Actions and services

- `InvitePartner`, `ResendPartnerInvitation`, `RevokePartnerInvitation`, `AcceptPartnerInvitation`, `CreatePartnerWithTemporaryPassword`, `BulkCreatePartners`, `ResetPartnerTemporaryPassword`, `SuspendPartner`, `ReactivatePartner`, `SetPartnerCanCreateProperties`, `RegeneratePartnerSignupCode`, `MovePropertyToPartner` (`app/Actions/Platform/`).
- Partner creation shares one internal step, `CreatePartner` (partner row, counter row with `last_number` 10100, signup code), so the three ways cannot drift apart. `PartnerSignupCode` (`app/Services/Platform/`) generates the signup code and retries on a clash.
- `ResetPartnerTemporaryPassword` is separate from the staff `ResetTemporaryPassword` (no Owner role check). Copy and export reuse `TemporaryPasswordMessage` and `TemporaryPasswordFile`; `TemporaryPasswordSlipData::fromPartner` puts the partner name where staff slips show the property.
- `IssuePropertyCode` (`app/Actions/Property/`).
- `PartnerProperties` service: the partner's property list (`app/Services/Platform/`).
- `PropertyPolicy::viewAs`.

## Tests

- Codes: each prefix counts on its own; two sign-ups at once never share a code; moving a partner keeps the code.
- The link registers an `AS` property linked to the partner; a regenerated or suspended link registers `ES`.
- A partner sees only their properties; another partner's property returns 404.
- A partner creates a property with their prefix when switched on; when off, there is no button and the store returns 403.
- The signup link registers a property whether the switch is on or off.
- The partner property page shows staff and features with no write actions; the switch is logged.
- A suspended partner cannot sign in.
- Invite reserves the prefix; revoke frees it; accept makes the partner Active; an expired link fails.
- Invite refuses an email with an account or an open invitation for any partner; accept fails when the email got an account after the invite.
- Add with temp password forces a password change at first sign-in.
- Bulk add rejects `ES`, a taken prefix and a repeated prefix, and saves nothing on any error.
- Bulk add rejects an email with an account, an open invitation or a repeat in the list; upload reads CSV and XLSX and refuses a wrong heading; the result never carries a temp password.

## Lessons from wastays

- `super_agent` was a `users.role` value with `agent_code` on the user. estatell keeps the prefix and link on a `partners` row, so they outlive one login.
- wastays ignored an unknown code silently. estatell shows a notice, so the owner knows the link was dead.
