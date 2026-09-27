# Registration card fields

## Purpose

Each property chooses which fields appear on its registration card.

## Data model

```
registration_card_fields    id, property_id, field (RegistrationCardField),
                            visibility (FieldVisibility), position
                            unique (property_id, field)
```

## Enums

- `RegistrationCardField`: FullName, Nationality, IdentityDocument, DateOfBirth, Gender, Address, Phone, Email, VehiclePlate, MarketingConsent, and others.
- `FieldVisibility`: Hidden, Optional, Required.

## Rules

- Defaults are seeded per property type.
- `MarketingConsent` is a separate opt-in checkbox. When ticked it sets `guests.marketing_consent_at`.
- Nationality drives the `foreign_guests_only` tax rule.

## Lessons from wastays

The field list was the `hotels.guest_registration_card_fields` jsonb column.
