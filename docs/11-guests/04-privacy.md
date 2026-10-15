# Privacy (PDPA)

## Purpose

Meet Malaysian PDPA expectations for guest data.

## Rules

- Guests are scoped to one property.
- ID numbers are encrypted. Scans are on a private disk. See [Identity documents](02-identity-documents.md).
- Access to ID documents is permission-controlled and logged.
- Marketing consent is an explicit opt-in (`marketing_consent_at`), captured on the registration card.
- `property_settings.id_document_retention_days` sets how long scans are kept. Blank keeps them. A value is at least 1 day.
- Archiving is a manual action, started by staff on request. Nothing is archived automatically.

## Archive a guest

`AnonymizeGuest` removes a guest's personal data and keeps the record, so financial history stays intact. It cannot be undone.

The UI calls this **Archive**. Internal Action names, routes, permissions, `anonymized_at` and the `guest.anonymized` event stay unchanged. This is permanent data removal, not recoverable storage.

| Removed or cleared                                                                                          | Kept                                                             |
| ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| Name, replaced with `Archived User #<id>`                                                                   | The `guests` row, its id and its property                        |
| Email, phone, nationality, date of birth, gender, language, address and country                             | `created_at`                                                     |
| VIP mark, blacklist and its reason, marketing consent                                                       | `anonymized_at`, now set                                         |
| All notes, all tag links (the tags stay), all ID documents with their numbers, blind indexes and scan files | Reservations that point to the guest. Later: folios and payments |
| The blacklist `reason` inside earlier activity log entries for the guest (it can name people)               |                                                                  |

- It returns `false` and writes nothing when the guest is already anonymized, was merged into another guest, or is in house. The controller shows an error toast.
- **In house** means the guest is an occupant (`unit_guests`) of a reservation unit with status CheckedIn. `GuestInHouse` (module 12) answers it. A booker who does not stay is not in house.
- **Known gap:** a guest with only upcoming Confirmed stays can still be archived. The stay keeps working, but the name on it becomes `Archived User #id`. Review this when the front desk module (18) is built.
- One `guest.anonymized` entry is logged: the counts of notes and documents removed. No personal values.
- Modules that keep balances add their own refusal here, for example a guest with an open balance (folios, module 13). Reservations added the in-house refusal in module 12.
- Permission: `guests.anonymize`. Only the Owner role has it by default.
- The guest page asks the staff member to type `Confirm` before **Archive guest** turns on. The check is case-sensitive and ignores surrounding spaces. The check is in the browser only. The dialog warns that personal data is permanently deleted and the action cannot be undone.
- A data migration renames existing `Anonymized guest #<id>` placeholders and updates their name keys, only for guests with `anonymized_at` set. Other names, timestamps, financial records and issued-document snapshots stay unchanged.
- The guest list also supports [bulk Archive](01-guest-profiles.md#bulk-selection-and-actions). It uses the same Action, refusals and per-guest audit entries. The server validates the typed `Confirm` for bulk requests.
- The guest-page **Archive** button and the confirmation submit button use the destructive variant. Other header actions stay unchanged.

An archived guest:

- Shows an Archived notice and badge on the guest page. The VIP, Blacklist, Merge and Archive buttons, the Add note card, the Details form and the Documents tab are hidden.
- Is refused by every write Action (`UpdateGuest`, `SetVip`, `BlacklistGuest`, `AddGuestNote`, `AddIdentityDocument`, `MergeGuests`). The Details tab shows read-only text for an anonymized guest, and the add-document request returns an error toast.
- Is left out of the guest list unless the `Archived` status filter is chosen, and never matches in [duplicate matching](03-matching-and-merging.md). The filter value stays `anonymized`.
- Stays reachable by its URL, because later reservations and folios link to it.

## Purge expired ID scans

`PurgeExpiredIdentityScans` runs every night at 03:30 (server time). It is a scheduled call in `routes/console.php`, on one server only, named `purge-expired-identity-scans`.

- For each property with `id_document_retention_days` set, it deletes the front and back scan files uploaded more than that many days ago. The age is counted from the upload date of the file. A property without a value keeps its scans.
- The document and its encrypted number stay, so the number is still searchable. Only the scan files go.
- One `guest.document_scans_purged` entry is logged per guest: the document ids, the number of scans and the retention. No personal values.
- Each property is handled on its own. A failure is reported and the others still run. Running it again finds nothing more to delete.
- The Documents tab tells staff how many days scans are kept when the property has a value.
- Later, once reservations exist, the age can be counted from the last check-out instead of the upload date.

## Actions

`AnonymizeGuest`, `PurgeExpiredIdentityScans` (scheduled).
