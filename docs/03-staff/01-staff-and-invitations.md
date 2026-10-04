# Staff and invitations

## Purpose

A property has many staff users. Staff join by an emailed invitation or are added with a temp password.

## Data model

```
users                id, property_id, name, email, password, is_admin, is_company_user, deactivated_at,
                     temporary_password, temporary_password_expires_at, must_change_password,
                     two_factor columns, timestamps
staff_invitations    id, property_id, email, role_id,
                     token_hash, expires_at, last_sent_at,
                     accepted_at, revoked_at, invited_by, timestamps
                     UNIQUE (property_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL
                     CHECK email = lower(email); CHECK accepted_at and revoked_at are not both set
```

- `token_hash` is the SHA-256 of the token. The plain token lives only in the emailed link.
- An invitation is **open** while it is neither accepted nor revoked (`StaffInvitation::open()`). An open invitation can be expired; resending opens it again.

- A user belongs to one property (`users.property_id`).
- `users.property_id` is null only for platform admins (`is_admin`) and company users (`is_company_user`). A CHECK enforces exactly one kind: admin, company user or staff. See [Platform admins](../01-platform/01-platform-admins.md).
- Company users (module 23) have no staff role and cannot open `/p/{code}` routes. They join through `company_users`, not `staff_invitations`. See [Company portal](../23-company-portal/01-company-portal.md).
- The owner is created by Fortify registration or by an admin invite (see [Account creation](../17-onboarding/01-account-creation.md)).

## Actions

| Action                  | Does                                                                                                                 |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `InviteStaff`           | Creates the invitation (valid 7 days, `InviteStaff::VALID_DAYS`), emails the link, logs `staff.invited`              |
| `ResendStaffInvitation` | New token and a new 7-day expiry, so the old link stops working. Logs `staff.invitation-resent`                      |
| `RevokeStaffInvitation` | Sets `revoked_at`, which frees the email for a new invitation. Logs `staff.invitation-revoked`                       |
| `AcceptStaffInvitation` | Creates the user (email verified), assigns the invited role, closes the invitation. Logs `staff.invitation-accepted` |
| `DeactivateStaff`       | Sets `deactivated_at` and a new `remember_token`. Logs `staff.deactivated`                                           |
| `ReactivateStaff`       | Clears `deactivated_at`; the user keeps their role. Logs `staff.reactivated`                                         |

Deactivate returns `false` for yourself, an already deactivated user, an Owner when the actor is not an Owner, or the last active Owner. Reactivate returns `false` for an active user, or an Owner when the actor is not an Owner.

Resend and revoke return `false` for a closed invitation; the controller shows an error toast. Accept returns `false` for a closed or expired invitation, or when the email got an account after the invitation was sent.

The email is `App\Notifications\StaffInvitationNotification`, queued after commit and sent on demand to the invited address.

## Add staff menu

The Staff page has one **Add staff** menu:

| Item                   | Flow                                                                                          |
| ---------------------- | --------------------------------------------------------------------------------------------- |
| Invite by email        | This page: invitation link, valid 7 days                                                      |
| Add with temp password | Dialog. See [Temp passwords and bulk staff](03-temp-passwords.md)                             |
| Bulk add               | Full page, grid or CSV/XLSX upload. See [Temp passwords and bulk staff](03-temp-passwords.md) |

## Pages and routes

| Page or action         | Route                                                                                          | Permission     |
| ---------------------- | ---------------------------------------------------------------------------------------------- | -------------- |
| Staff, Active tab      | `GET /p/{code}/settings/staff` (`settings.staff.index`)                                        | `staff.view`   |
| Staff, Deactivated tab | `GET /p/{code}/settings/staff/deactivated` (`settings.staff.deactivated`)                      | `staff.view`   |
| Deactivate             | `POST .../staff/{user}/deactivate` (AlertDialog)                                               | `staff.manage` |
| Reactivate             | `POST .../staff/{user}/reactivate`                                                             | `staff.manage` |
| Change role            | `PUT .../staff/{user}/role` (Dialog). See [Roles and permissions](02-roles-and-permissions.md) | `staff.manage` |
| Staff, Invited tab     | `GET /p/{code}/settings/staff/invited` (`settings.staff.invited`)                              | `staff.view`   |
| Invite                 | `POST .../staff/invitations` (Dialog: email, role)                                             | `staff.manage` |
| Resend                 | `POST .../staff/invitations/{id}/resend`                                                       | `staff.manage` |
| Revoke                 | `DELETE .../staff/invitations/{id}` (AlertDialog)                                              | `staff.manage` |
| Accept page            | `GET /invitations/{token}` (`invitations.show`), guest only, auth layout                       | Token          |
| Accept                 | `POST /invitations/{token}` (`invitations.accept`), throttled 6 per minute                     | Token          |

- Invitation routes use scoped bindings, so another property's invitation returns 404.
- The Invited tab lists open invitations, with expired ones marked. The sidebar shows Staff under **Team** for users with `staff.view`.
- Unknown, expired, revoked and accepted links show one notice page. It does not say which, so a link reveals nothing.

## Rules

- Deactivated users cannot log in: `Fortify::authenticateUsing` refuses them with a clear message after a correct password.
- A signed-in user who gets deactivated is signed out on their next request by `LogoutDeactivatedUsers`, appended to the `web` group. It covers account pages too, not only `/p` routes. Sessions live in Redis, so they are not deleted directly.
- A CHECK keeps platform admins from being deactivated.
- A property always keeps at least one active Owner.
- Emails are stored lower case. An email with an account (in any property) or an open invitation in this property cannot be invited.
- Only Owners can invite someone as Owner (`AssignableRoles`). The role must belong to the property.
- Invitations expire. Accepting an expired invitation fails with a clear message.

## Future

If one user needs access to several properties, move to a `property_user` pivot with the role on the pivot. Teams mode in the permission package already supports this.
