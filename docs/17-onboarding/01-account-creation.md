# Account creation

## Purpose

Two ways to create a property. Both end in the same place: an owner in the onboarding wizard.

## Paths

| Path          | Flow                                                                                                                                                                                     |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Self sign-up  | Fortify register (name, email, password, property name) > email verified > property created with status Onboarding > wizard                                                              |
| Admin creates | Admin enters property name, type, owner email, optional rooms and prices > owner gets a `URL::temporarySignedRoute` "Set your password" link > wizard with prefilled steps shown as done |

## Actions

- `RegisterPropertyOwner` (self sign-up)
- `CreatePropertyForOwner` (admin)
- `SeedPropertyDefaults` (both)

## Rules

- `properties.onboarding_started_at` is set on creation.
- Platform admins use the separate `admins` guard (see [Platform admins](../01-platform/01-platform-admins.md)).
