# Identity documents

## Purpose

Store guest IDs securely and allow exact search by ID number.

## Data model

```
guest_identity_documents    id, guest_id, type (IdentityDocumentType), number (encrypted),
                            issuing_country_code, expires_on, timestamps
blind_indexes               (spatie/laravel-ciphersweet)
media                       two single-file collections on each document: id_front, id_back
```

- `type` and the country code have `CHECK` constraints. Deleting a guest deletes its documents.
- The number is not unique. It is encrypted, so the database cannot check it. [Matching and merging](03-matching-and-merging.md) finds duplicates.

## Enums

`IdentityDocumentType`: MyKad, Passport, Other. `formatNumber()` shows a MyKad as `900101-14-1234`.

## Rules

- `number` is encrypted with `spatie/laravel-ciphersweet`, with a blind index (`number_index`) for exact search.
- The number is stored **normalized**: uppercase letters and digits only. `900101-14-1234` and `900101141234` are the same number. `IdentityNumber` does this, and the blind index applies the same transformation. A MyKad must be 12 digits. Any number needs at least 4 characters.
- Front and back scans are media files. Each collection keeps one file: a new file replaces the old one and deletes it. JPEG, PNG, WebP or PDF, up to 10 MB.
- New scans are stored on the disk chosen in [platform settings](../01-platform/02-platform-settings.md#media-storage). Each `media` row keeps its own disk, so older files stay readable.
- The number is **masked** (`•••• 1234`) everywhere except the Reveal button and the edit sheet. Page props never carry the full number. Both fetch it with the logged `reveal` request.
- Revealing a number, opening the edit sheet (it fetches the number the same way) and opening a scan are written to the activity log on the guest: `guest.document_number_viewed` and `guest.document_scan_viewed`. Adding, changing and removing are logged too. No log entry contains the number.
- Scans open through `guests.documents.scan`. It checks the permission, logs the view, then redirects to a temporary link that works for 5 minutes (a presigned URL on R2, a signed route on the `local` disk).
- Removing a document deletes the row, its blind index and its scan files. Registration cards (module 16) must keep their own snapshot of what was signed, so a card does not depend on the live document. See [Card fields](../16-registration/01-card-fields.md).
- A guest that was merged into another guest or anonymized cannot get or change documents.

## Search

`GuestDirectory` also matches an exact ID number (through the blind index) when the staff member has `guests.view-documents`. Staff without it cannot learn that a number exists.

## Actions

`AddIdentityDocument`, `UpdateIdentityDocument`, `RemoveIdentityDocument`, `RecordIdentityDocumentAccess`.

## Pages

Routes under `/p/{code}/guests/{guest}/documents` (`guests.documents.*`).

| Route                      | Permissions                                 | What                                                                                                                                                                                       |
| -------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `index`                    | `guests.view-documents`                     | Documents tab on the guest page, with masked numbers                                                                                                                                       |
| `reveal` (POST, JSON)      | `guests.view-documents`                     | The full number for the Reveal button                                                                                                                                                      |
| `scan` (`front` or `back`) | `guests.view-documents`                     | Redirect to a short-lived scan link                                                                                                                                                        |
| `store`, `update`          | `guests.view-documents` and `guests.manage` | Add and edit in one Sheet on the Documents tab (uploads inside a small Sheet are allowed, see [form containers](../design-system/05-form-containers.md)). There is no create or edit page. |
| `destroy`                  | `guests.view-documents` and `guests.manage` | Remove a document (confirm dialog)                                                                                                                                                         |

The Documents tab shows only to staff with `guests.view-documents`. An expired document shows an Expired badge, using the property's date.

## Permissions

| Permission              | Guards                                                                      | Owner | Manager | Front desk | Finance | Housekeeping |
| ----------------------- | --------------------------------------------------------------------------- | ----- | ------- | ---------- | ------- | ------------ |
| `guests.view-documents` | See, reveal and open ID documents. With `guests.manage`: add, edit, remove. | ✓     | ✓       | ✓          | –       | –            |

## Lessons from wastays

Identity data was in three places: `guests.government_id` and `passport_*`, `bookings.guest_*`, `booking_guests.*_snapshot`.
