# Privacy (PDPA)

## Purpose

Meet Malaysian PDPA expectations for guest data.

## Rules

- Guests are scoped to one property.
- ID numbers are encrypted. Scans are on a private disk.
- `property_settings.id_document_retention_days` sets how long scans are kept. A scheduled job purges older scans.
- `AnonymizeGuest` removes personal data but keeps financial history. It sets `anonymized_at`.
- Access to ID documents is permission-controlled and logged.
- Marketing consent is an explicit opt-in (`marketing_consent_at`), captured on the registration card.

## Actions

`AnonymizeGuest`, `PurgeExpiredIdentityScans` (scheduled).
