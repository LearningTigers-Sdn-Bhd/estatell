# Roles and permissions

## Purpose

Control what each staff member can do, per property.

## Package

`spatie/laravel-permission` v8.3 in **teams mode**.

- `config/permission.php`: `teams => true`, `team_foreign_key => property_id`.
- Set these **before** running the package migrations.
- `EnsurePropertyAccess` (see [Routing](../routing.md)) resolves the property from the raw `{property}` code and calls `setPermissionsTeamId($property->id)`. Register it before `SubstituteBindings`, so route model bindings and policies already see the right team.

## Data model

```
roles    spatie columns + property_id (NOT NULL, FK) + default_role (nullable)
         UNIQUE (property_id, default_role) WHERE default_role IS NOT NULL
```

- `App\Models\Role` extends the package model (`config/permission.php`) and casts `default_role` to `DefaultRoleEnum`.
- `default_role` marks a seeded role. Custom roles leave it null. The role `name` is the label at seed time.
- Every role belongs to one property. There are no global roles.

## Enums

- `PermissionEnum`: every permission name. Permissions are added **per module**: each module adds its own cases and their default-role mapping in its own commit.
- `DefaultRoleEnum`: Owner, Manager, FrontDesk, Housekeeping, Accounts. `permissions()` returns each role's defaults. Owner returns every case.

## Default permissions

| Permission        | Guards                                       | Owner | Manager | Front desk, Housekeeping, Accounts |
| ----------------- | -------------------------------------------- | ----- | ------- | ---------------------------------- |
| `settings.manage` | Property settings pages                      | ✓     | ✓       | –                                  |
| `staff.view`      | Staff list                                   | ✓     | ✓       | –                                  |
| `staff.manage`    | Invite, deactivate, reactivate, assign roles | ✓     | ✓       | –                                  |
| `roles.manage`    | Create roles, edit role permissions          | ✓     | –       | –                                  |

Later modules add rows here. Their module docs list their permissions.

## Seeding and syncing

- `SeedDefaultRoles` creates the five default roles for a property with their default permissions. It runs when the property is created. It is idempotent: existing roles keep their permissions, so it never undoes an owner's edits.
- `SyncPermissions` creates a permission row for every new `PermissionEnum` case. It grants **only the permissions created in that run** to the default roles whose defaults include them. The Owner role therefore always gets every permission. Custom roles are never touched, and a permission an owner removed from a role stays removed. It is idempotent.
- A migration calls `SyncPermissions`. A module that adds permissions adds a migration that calls it again.

## Rules

- Permissions are global rows created from `PermissionEnum`.
- Default roles are seeded per property.
- Owners can create custom roles per property. Only the Owner role has `roles.manage` by default.
- The Owner role is locked: its permissions cannot be edited.
- Policies check the permission **and** that `property_id` matches.

## Actions

- `SyncPermissions`
- `SeedDefaultRoles`
- `AssignOwnerRole`: gives a user their property's Owner role. It sets and restores the permission team, so it also works outside `/p` routes (registration, migrations).
- `CreateRole`: a custom role with no permissions. The name is unique in the property (60 characters at most). Logs `role.created`.
- `UpdateRolePermissions`: replaces a role's permissions and clears the permission cache. Returns `false` for the Owner role. Logs `role.permissions-updated` with old and new lists.
- `AssignStaffRole`: gives a staff user one role, replacing the old one. Returns `false` for yourself, for an Owner when the actor is not an Owner, or when it would leave no active Owner. Logs `staff.role-assigned`. The request only accepts roles from `AssignableRoles` (Owner only for Owners, and only this property's roles).

## Pages and routes

| Page or action    | Route                                                                 | Permission     |
| ----------------- | --------------------------------------------------------------------- | -------------- |
| Roles list        | `GET /p/{code}/settings/roles` (`settings.roles.index`)               | `roles.manage` |
| New role          | `POST /p/{code}/settings/roles` (Dialog: name), then the role page    | `roles.manage` |
| Role page         | `GET /p/{code}/settings/roles/{role}` (`settings.roles.edit`)         | `roles.manage` |
| Save permissions  | `PUT /p/{code}/settings/roles/{role}`                                 | `roles.manage` |
| Change staff role | `PUT /p/{code}/settings/staff/{user}/role` (Dialog on the Staff page) | `staff.manage` |

- The roles list shows default roles first, then custom roles, with active staff and permission counts. The Owner role shows "All".
- The role page lists permissions as checkboxes grouped by `PermissionEnum::group()`. The Owner role is shown read-only.
- Role routes use scoped bindings, so another property's role returns 404.
- The sidebar shows Roles under **Team** for users with `roles.manage`.
- Not built yet: renaming and deleting custom roles.

## Permission list

Each module doc lists its own permissions. `PermissionEnum` is the single source.
