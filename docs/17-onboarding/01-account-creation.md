# Account creation

## Purpose

Two ways to create a property. Both end in the same place: an owner in the onboarding wizard.

## Paths

| Path          | Flow                                                                                                                                                       |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Self sign-up  | Fortify register (name, email, password, property name) creates the property and owner > email verified > wizard                                           |
| Admin creates | Admin enters property name, type, owner name and email > chooses an emailed setup link or an initial password > owner verifies or completes setup > Basics |

## Actions

- `RegisterPropertyOwner` (self sign-up)
- `CreatePropertyForOwner` (admin)
- `CompleteOwnerSetup` (emailed setup link)
- `SendOwnerSetupLink` (admin resend)
- `SeedPropertyDefaults` (both, once type and country are known; see [Seed property defaults](../02-property/03-seed-property-defaults.md))

## Rules

- `properties.onboarding_started_at` is set on creation.
- `RegisterPropertyOwner` creates the property and the owner in one transaction at registration, so the `users` CHECK (staff always have a property) holds. In the same transaction it seeds the default roles (`SeedDefaultRoles`) and gives the owner the Owner role (`AssignOwnerRole`), so the owner has every permission from the first login. `type` and `country_code` stay empty until the Basics step. The `verified` middleware keeps unverified owners out of the wizard.
- Properties created before roles existed were backfilled by a migration: default roles seeded, and each property's first user made the Owner.
- `CreatePropertyForOwner` creates the Malaysia property and owner in one transaction. It seeds property settings and roles, assigns Owner, and logs `property.created` without password data.
- **Email setup link:** the owner receives a queued, after-commit notification with a seven-day temporary signed link. The guest page sets the password, verifies the email and signs the owner in. An account can complete setup once.
- **Initial password:** the admin sets and shares the password separately. The password is never emailed. The admin can mark the email as verified after confirming that the owner controls the address. Otherwise, Laravel sends the normal verification email.
- An admin can send a fresh seven-day setup link to any unverified Owner from the Staff tab. Sending a new link does not revoke an older unexpired link. Verifying the account makes every setup link unusable.
- Creating an already verified Owner logs `owner.email-verified` and dispatches Laravel's `Verified` event. The option is not available with an emailed setup link because that link is how the Owner sets a usable password.
- A verified Owner of a non-live property goes to the prefilled Basics step after login. Other staff, live Owners and admins keep their normal destinations.
- Platform admins are `users` with `is_admin` and never register here (see [Platform admins](../01-platform/01-platform-admins.md)).
