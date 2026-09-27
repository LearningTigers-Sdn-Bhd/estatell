# Identity documents

## Purpose

Store guest IDs securely and allow exact search by ID number.

## Data model

```
guest_identity_documents    id, guest_id, type (IdentityDocumentType), number (encrypted),
                            issuing_country_code, expires_on, timestamps
blind_indexes               (spatie/laravel-ciphersweet)
```

## Enums

`IdentityDocumentType`: MyKad, Passport, Other.

## Rules

- `number` is encrypted with `spatie/laravel-ciphersweet`, with a blind index for exact search.
- Front and back scans are media files on a private disk.
- Viewing documents requires `guest.view-documents` and is logged.
- Registration cards reference the document used at signing.

## Lessons from wastays

Identity data was in three places: `guests.government_id` and `passport_*`, `bookings.guest_*`, `booking_guests.*_snapshot`.
