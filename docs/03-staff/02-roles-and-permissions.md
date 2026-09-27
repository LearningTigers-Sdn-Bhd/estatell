# Roles and permissions

## Purpose

Control what each staff member can do, per property.

## Package

`spatie/laravel-permission` v8.3 in **teams mode**.

- `config/permission.php`: `teams => true`, `team_foreign_key => property_id`.
- Set these **before** running the package migrations.
- `EnsurePropertyAccess` (see [Routing](../routing.md)) resolves the property from the raw `{property}` code and calls `setPermissionsTeamId($property->id)`. Register it before `SubstituteBindings`, so route model bindings and policies already see the right team.

## Enums

- `PermissionEnum`: every permission name, for example `reservations.create`, `folio.post-charge`, `folio.void`, `rates.edit`, `night-audit.run`, `staff.manage`.
- `DefaultRoleEnum`: Owner, Manager, FrontDesk, Housekeeping, Accounts, with each role's default permissions.

## Rules

- Permissions are global rows seeded from `PermissionEnum`.
- Default roles are seeded per property.
- Owners can create custom roles per property.
- The Owner role is locked: its permissions cannot be edited.
- Policies check the permission **and** that `property_id` matches.

## Actions

- `AssignStaffRole`
- `CreateRole`
- `UpdateRolePermissions`

## Permission list

Each module doc lists its own permissions. `PermissionEnum` is the single source.
