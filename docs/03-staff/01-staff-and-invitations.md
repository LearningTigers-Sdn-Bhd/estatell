# Staff and invitations

## Purpose

A property has many staff users. Staff join only by invitation.

## Data model

```
users                id, property_id, name, email, password, deactivated_at, two_factor columns, timestamps
staff_invitations    id, property_id, email, role_id, token, expires_at, accepted_at, invited_by, timestamps
```

- A user belongs to one property (`users.property_id`).
- The owner is created by Fortify registration or by an admin invite (see [Account creation](../17-onboarding/01-account-creation.md)).

## Actions

- `InviteStaff`
- `AcceptStaffInvitation`
- `DeactivateStaff`
- `ReactivateStaff`

## Rules

- Deactivated users cannot log in.
- A property always keeps at least one active Owner.
- Invitations expire. Accepting an expired invitation fails with a clear message.

## Future

If one user needs access to several properties, move to a `property_user` pivot with the role on the pivot. Teams mode in the permission package already supports this.
