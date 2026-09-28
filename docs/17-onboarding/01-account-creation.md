# Account creation

## Purpose

Two ways to create a property. Both end in the same place: an owner in the onboarding wizard.

## Paths

| Path          | Flow                                                                                                                                                                                     |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Self sign-up  | Fortify register (name, email, password, property name) creates the property (status Onboarding) and owner > email verified > wizard                                                     |
| Admin creates | Admin enters property name, type, owner email, optional rooms and prices > owner gets a `URL::temporarySignedRoute` "Set your password" link > wizard with prefilled steps shown as done |

## Actions

- `RegisterPropertyOwner` (self sign-up)
- `CreatePropertyForOwner` (admin)
- `SeedPropertyDefaults` (both, once type and country are known; see [Seed property defaults](../02-property/03-seed-property-defaults.md))

## Rules

- `properties.onboarding_started_at` is set on creation.
- `RegisterPropertyOwner` creates the property and the owner in one transaction at registration, so the `users` CHECK (staff always have a property) holds. In the same transaction it seeds the default roles (`SeedDefaultRoles`) and gives the owner the Owner role (`AssignOwnerRole`), so the owner has every permission from the first login. `type` and `country_code` stay empty until the Basics step. The `verified` middleware keeps unverified owners out of the wizard.
- Properties created before roles existed were backfilled by a migration: default roles seeded, and each property's first user made the Owner.
- Platform admins are `users` with `is_admin` and never register here (see [Platform admins](../01-platform/01-platform-admins.md)).
