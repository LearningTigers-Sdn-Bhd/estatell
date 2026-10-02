# Matching and merging

## Purpose

Avoid duplicate guests without blocking staff.

## Matching

`GuestMatcher` finds guests of one property that may be the same person as the details given. It only suggests. It never blocks saving.

| Reason                      | How it matches                                                               | Strength |
| --------------------------- | ---------------------------------------------------------------------------- | -------- |
| Phone                       | Exact E.164 number. Input is parsed like the guest form.                     | Strong   |
| Email                       | Exact, ignoring case and spaces.                                             | Strong   |
| ID number                   | Exact, through the blind index. Only for staff with `guests.view-documents`. | Strong   |
| Same name and date of birth | Same `name_key` and the same date of birth.                                  | Strong   |
| Same name                   | Same `name_key`.                                                             | Weak     |

- `guests.name_key` holds the normalized name. `GuestName` makes it: lowercase, accents removed, punctuation dropped, the particles `bin`, `binti`, `bte`, `bt`, `b.`, `a/l` and `a/p` dropped, and the words sorted. "Aisyah binti Rahman" and "Rahman, Aisyah" give the same key. `CreateGuest` and `UpdateGuest` set it. `full_name` is never changed. If nothing is left after normalizing, the lowercase name is the key.
- There is no fuzzy matching, so typos are not found. `pg_trgm` can be added later on top of `name_key`.
- Strong matches come first, then more reasons, then the name. At most 10 are returned.
- Merged and anonymized guests are never returned. Blacklisted guests are returned, so a blacklisted guest coming back under a new profile is seen. Other properties' guests are never returned.
- A name alone is weak. The warning says "A guest with the same name exists".

## Where it is used

| Where                               | What                                                                                              |
| ----------------------------------- | ------------------------------------------------------------------------------------------------- |
| Guest form                          | A warning after the name, phone, email or date of birth field is left. Matches open in a new tab. |
| ID document form                    | A warning after the number field is left: the number is already on another guest.                 |
| Guest page, Details tab             | A **Possible duplicates** list from the guest's own details and ID numbers, with a Merge button.  |
| Booking form (module 12)            | `DuplicateWarning` shows "Use existing" and "Create new anyway" under the guest tab fields.       |
| Channel bookings (module 21, later) | Booking ingestion runs guests through `GuestMatcher`.                                             |

The forms use `POST guests/matches` (`guests.matches`, `guests.view`, JSON). It returns up to 10 matches with their reasons and a `GuestProfileData`, so "Use existing" can fill a form without a second request.

## Merging

`MergeGuests` merges guest B into guest A. A always survives. It is started from A's page.

- Notes, tags and ID documents move to A. Documents keep their scans and blind indexes. Tags already on A are not duplicated.
- Fields that are empty on A are filled from B: email, phone, nationality, date of birth, gender, language and the address fields. Fields A already has are never changed.
- A becomes VIP if either guest was. If B was blacklisted and A is not, the blacklist and its reason move to A. If both are blacklisted, A keeps its own reason. A takes B's marketing consent date if it has none.
- B stays as a row with `merged_into_id` set. Its email, phone, nationality, date of birth, gender, language, address, VIP mark, blacklist and consent are cleared. Only the name stays, for the audit trail. A merge **cannot be undone**.
- Earlier merges into B are re-pointed to A, so a merged guest always points to the final guest.
- It returns `false` and writes nothing when A and B are the same guest, belong to different properties, or either one was already merged or anonymized. The controller shows an error toast.
- One `guest.merged` entry is logged on A: the merged guest's id, the counts moved, the field names filled, and whether VIP and blacklist moved. No personal values are logged.
- `EnsureGuestIsNotMerged` is on the guest routes. A GET to a merged guest's page redirects to the guest it was merged into, with a toast. Any other request to a merged guest returns 404.
- **Reservations are re-pointed.** `MergeGuests` calls `RepointGuestReservations` (module 12) inside its transaction. B's `reservations.booker_guest_id` and B's `unit_guests` rows move to A. When A and B are both occupants of one unit, B's row is removed, and A's row becomes primary if B's was. The log entry adds `reservations_moved`, `unit_guests_moved` and `unit_guest_duplicates_removed`.
- **Folios are not moved yet.** The folio tables arrive with module 13, which adds its own re-pointing (payer) by calling its own Action. Modules never reach into each other's models.

## Routes

| Route                | Permission     | What                                             |
| -------------------- | -------------- | ------------------------------------------------ |
| `guests.matches`     | `guests.view`  | JSON suggestions for the forms                   |
| `guests.merge.store` | `guests.merge` | Merge the guest in `duplicate_id` into `{guest}` |

## Permissions

| Permission     | Guards                 | Owner | Manager | Front desk | Finance | Housekeeping |
| -------------- | ---------------------- | ----- | ------- | ---------- | ------- | ------------ |
| `guests.merge` | Merge duplicate guests | ✓     | ✓       | –          | –       | –            |

## Lessons from wastays

About 150 lines of identity-conflict logic lived inside `CreateManualBooking`.
