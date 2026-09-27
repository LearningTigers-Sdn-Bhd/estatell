# Platform admins

## Purpose

Platform admins run estatell itself. They create properties for owners, watch onboarding progress, toggle features and decide platform-wide settings. They are not property staff.

## Data model

```
admins    id, name, email, password, two_factor columns, last_login_at, remember_token, timestamps
```

- A separate `admins` table and a separate `admin` auth guard.
- Admins never appear in `users` and never belong to a property permission team.

## Seeding the first admin

`AdminSeeder` (called by `DatabaseSeeder`) creates the first admin from `ADMIN_SEED_EMAIL` and `ADMIN_SEED_PASSWORD` (read through `config/seeding.php`).

- Skipped when either value is empty.
- Fails on an invalid email or a password shorter than 8 characters.
- Does nothing when an admin with that email exists, so `db:seed` is safe to run again. It never resets a password.

## Capabilities

- Create a property and invite its owner (see [Account creation](../17-onboarding/01-account-creation.md)).
- View the onboarding board (see [Engagement and admin board](../17-onboarding/04-engagement-and-admin-board.md)).
- Toggle features per property (see [Feature flags](03-feature-flags.md)).
- Edit [Platform settings](02-platform-settings.md).
- Change the channel dorm strategy (see [Dorm strategies](../21-channels/03-dorm-strategies.md)).
- View a property as its owner or staff (see [Impersonation](04-impersonation.md)).
- Suspend a property.

## Rules

- Admin routes live under their own prefix and middleware group (`auth:admin`).
- Every admin write is recorded in the activity log with the admin as causer.
