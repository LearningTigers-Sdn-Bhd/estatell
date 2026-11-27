# Property groups and multi-property access

## Purpose

One business can run more than one property (Tanini: Sepilok and Kinabatangan). A manager of both signs in once and switches between them. Front desk staff stay at their own lodge unless someone gives them access to the other. This is Tanini requirement 1.1. The group dashboard and combined reports (1.2, 7.4) come next and read through the same memberships.

**Status: built** (2026-11-22). Decided with the owner: companies stay per property; a group is created by a platform admin; a staff member sees a property only if given access there, with a role there; sign-in lands on the property opened last.

## Data model

```
property_groups       id, name (unique, ignoring case), timestamps
properties            + property_group_id (null, null on delete)
property_memberships  id, property_id, user_id, deactivated_at, timestamps
                      UNIQUE (property_id, user_id); INDEX (user_id, deactivated_at)
users                 + last_property_id (null, null on delete): the property opened last
```

| Fact                                                     | Where                                                                                                                                  |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| The home property: where the user was invited or created | `users.property_id` (unchanged, still required for staff by the CHECK)                                                                 |
| Which properties the user may open                       | `property_memberships` with `deactivated_at` null. Every staff user has one for the home property.                                     |
| The role at each property                                | The permission tables, unchanged (teams mode keyed on `property_id`). A user can be Manager at one property and Front desk at another. |
| Signed in at all                                         | `users.deactivated_at`, set only when no membership is active                                                                          |

The migration gave every existing staff user one membership at their home property, with their old `deactivated_at`, so nothing changed on the day it shipped.

## Rules

- **Access.** `EnsurePropertyAccess` lets a staff user into `/p/{code}` only with an active membership there (and an active account). Anyone else gets a 404, as before. It reads the user's active property ids once (`StaffProperties::activePropertyIds()`) and shares them with the switcher.
- **Roles never leak.** The middleware sets the permission team to the property and drops any roles or permissions already loaded, so a check never uses another property's role.
- **Granting** (`GrantPropertyAccess`, Settings › Staff › Add staff › **Add from another property**, `staff.manage` at the target property): only for a staff member whose home property is in the same group as this one, whose home membership is active, and who has no membership here yet. The actor picks a role here from the roles they may give (only Owners give Owner). Logged as `staff.access-granted`.
- **Deactivating** (`DeactivateStaff`) works per property: it deactivates the membership there. When no membership stays active, the account is deactivated too, so sign-in stops and sessions end (`LogoutDeactivatedUsers`). Owner rules count Owners with an active membership at that property. **Reactivating** (`ReactivateStaff`) restores the membership and the account.
- **Changing the role** (`AssignStaffRole`) changes the role at that property only.
- **Temp passwords** stay with the home property: only it resets or shares a user's temp password, because a password works at every property. The Staff page hides Reset password for staff from another property.
- **Invitations** are unchanged: an email that already has an account cannot be invited; use Add from another property instead.
- **Groups** are managed by platform admins in **Admin › Property groups** (`support.2fa` on every write): create, rename, add a property that has no group, take one out. Taking a property out is refused while any active membership crosses it (staff of another property with access here, or its staff with access elsewhere); deactivate those first. Being in a group gives nobody access by itself.

## Switching

- The property identity in the navbar becomes a menu when the user may open other properties (`propertySwitcher`, the other active memberships by name; suspended properties left out). Each item opens that property's dashboard.
- Opening a property stores it in `users.last_property_id` (one write, only when it changes; not while a support user views as staff; only on a page visit, not a hover prefetch or the logo image). It also clears the group of group mode (see 05-group-mode.md).
- With no property in the URL (sign-in, account pages, the command palette, feature flags) the user works in `StaffProperties::defaultFor()`: the property opened last, else the home property, else the first by name, with an active membership. A user with none gets a 403 on the home redirect.

## Staff of a property

Every "staff of this property" read goes through memberships, so staff given access from the group show up everywhere their role allows, and deactivated ones nowhere:

| Read                                                                                   | Scope                                                                                                                                                 |
| -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Settings › Staff (Active, Deactivated, counts), onboarding team, go-live review        | `Property::users()`, a many-to-many through `property_memberships` (pivot `deactivated_at`). Staff from another property show "From {home property}". |
| Route `staff/{user}`                                                                   | Scoped bindings resolve through `Property::users()`, so only members of the property bind.                                                            |
| Housekeepers, sales people, guest request assignees, report staff filter, view-as list | `User::activeMemberOf()` (or `memberOf()` for the Payments staff filter, which lists who took payments)                                               |
| Notifications to staff with a permission                                               | `PropertyStaff::withPermission()`, one class for the seven senders                                                                                    |
| Temp password lists                                                                    | `Property::homeStaff()` (home property only)                                                                                                          |

## Code

| Part                                                                                                                           | Role                                                                                            |
| ------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| `PropertyGroup`, `PropertyMembership`                                                                                          | Models                                                                                          |
| `StaffProperties`                                                                                                              | Active property ids, the default property, the switcher, the group colleagues that can be added |
| `PropertyStaff`                                                                                                                | Active staff of a property with a permission, for notifications                                 |
| `PropertyOwners::isOwner()`, `hasActiveOwnerOtherThan()`                                                                       | Owner rules per property, shared by deactivate, reactivate and change role                      |
| `GrantPropertyAccess`, `StaffAccessController`, `GrantPropertyAccessRequest`                                                   | Add from another property                                                                       |
| `CreatePropertyGroup`, `RenamePropertyGroup`, `AddPropertyToGroup`, `RemovePropertyFromGroup`, `Admin\PropertyGroupController` | Admin › Property groups                                                                         |
| `app-navbar.tsx`                                                                                                               | The switcher                                                                                    |

## Tests

`tests/Feature/Staff/PropertyGroupAccessTest.php`:

- access per property inside a group
- the role per property
- who can be given access, and with which role
- deactivating per property and the account
- the switcher and the property sign-in lands on
- staff lists and notifications
- temp passwords at the home property only
- admin groups, including refusing to take out a shared property
- a constant query count however many properties a user has

## Not built yet

- Combined timeline and reports for users with access to several properties (plan 1.2, 7.4): see [Group mode](05-group-mode.md); the group dashboard is built.
- Shared companies across a group: companies stay per property by decision.
