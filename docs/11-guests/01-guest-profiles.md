# Guest profiles

## Purpose

A simple guest CRM, scoped to one property.

## Data model

```
guests         id, property_id, full_name, name_key, email, phone (E.164), nationality_code,
               date_of_birth, gender (Gender), preferred_locale (Locale),
               address_line1, address_line2, city, state, postal_code, country_code,
               is_vip, blacklisted_at, blacklist_reason, marketing_consent_at,
               merged_into_id (nullable), anonymized_at, timestamps
guest_notes    id, guest_id, author_id (nullable), body, timestamps
tags           id, property_id, name
guest_tag      guest_id, tag_id
```

- `name_key` is the normalized name used to find duplicates. See [Matching and merging](03-matching-and-merging.md). `CreateGuest` and `UpdateGuest` keep it in step.
- `state` is free text, because guests come from any country. Country codes are ISO 3166-1 alpha-2.
- Email and phone are not unique. Family members often share them. [Matching and merging](03-matching-and-merging.md) handles duplicates.
- A blacklisted guest must have a reason (`CHECK`). `merged_into_id` cannot point to the guest itself.
- A tag name is unique per property, ignoring case (a unique index on `lower(name)`).
- `merged_into_id` and `anonymized_at` exist from the start. They are used by merging and privacy.
- Notes are add-only: no edit, no delete, no pinning. wastays had no guest notes, only booking notes that could be edited, with the edit history kept in jsonb.

## Enums

`Gender`: Male, Female. `preferred_locale` uses the existing `Locale` enum.

## Actions

`CreateGuest`, `UpdateGuest`, `SetVip`, `BlacklistGuest`, `UnblacklistGuest`, `AddGuestNote`, `SyncGuestTags`.

- Tags are saved with the guest as names. A name that matches an existing tag of the property, ignoring case, reuses it. Any other name creates a tag.
- Create, update, VIP, blacklist and unblacklist are written to the activity log. The update log lists the changed field names, not their values.
- `BlacklistGuest` needs a reason. Blacklisting a guest who is already blacklisted, unblacklisting one who is not, and editing a merged or anonymized guest return `false`. The controller shows an error toast.
- `UnblacklistGuest` clears both `blacklisted_at` and `blacklist_reason`.

## Services

- `GuestDirectory`: the server-paginated list (25 per page). Search matches name, email and phone. Filters: `status` (`vip` or `blacklisted`) and `tag`. Sorts: `full_name`, `created_at`. Merged guests are left out.
- `CountryOptions`: country names from the PHP `intl` extension.
- `GuestStayHistory` (stays, nights, revenue, last stay and no-shows, calculated from `unit_guests`, `unit_nights` and `folio_lines`, not stored) is **deferred** to module 13, because the folio tables do not exist yet. The reservation tables exist since module 12.

## Pages

Routes under `/p/{code}/guests` (`guests.*`, route file `guests.php`). They are in the Front desk context under Guests.

| Route                                    | Permission         | Page                                                                                                                                                                                                                                                                                     |
| ---------------------------------------- | ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `guests.index`                           | `guests.view`      | Index: search, status and tag filters, sort, pages. Filters are in the URL.                                                                                                                                                                                                              |
| `guests.store`                           | `guests.manage`    | Add guest: a Sheet on the index with 4 fields (name required, phone, email, nationality). No tags. Redirects to `guests.show`.                                                                                                                                                           |
| `guests.show`, `guests.notes.index`      | `guests.view`      | Detail with Details and Notes tabs, each its own URL. The flag icons and the added date sit in the page header, with no side card. For staff with `guests.manage` the Details tab is the always-open edit form (all fields and tags). Others, and anonymized guests, see read-only text. |
| `guests.update`                          | `guests.manage`    | Saves the Details form, then returns to the Details tab with a toast. There is no separate edit page.                                                                                                                                                                                    |
| `guests.vip.store`, `guests.vip.destroy` | `guests.manage`    | Mark or unmark VIP                                                                                                                                                                                                                                                                       |
| `guests.notes.store`                     | `guests.manage`    | Add a note (dialog on the Notes tab)                                                                                                                                                                                                                                                     |
| `guests.blacklist.store`, `.destroy`     | `guests.blacklist` | Blacklist with a reason (dialog), or remove from the blacklist (confirm)                                                                                                                                                                                                                 |

## Rules

- Guests are never shared across properties. A guest of another property returns 404.
- Phone numbers are validated and normalized to E.164 with `propaganistas/laravel-phone`. A number typed without a country code is read as a number of the property's country (Malaysia if unset). A number with a country code is accepted from any country.
- Email and phone stay plaintext for search and notifications. Rely on database encryption at rest.
- ID documents have their own Documents tab. See [Identity documents](02-identity-documents.md).
- A guest merged into another guest is hidden from the list and redirects to the survivor. See [Matching and merging](03-matching-and-merging.md).
- Left for later: `GuestPicker` (with the booking form), a settings page for tags, `marketing_consent_at` (captured on the registration card).

## Permissions

| Permission         | Guards                                          | Owner | Manager | Front desk | Finance | Housekeeping |
| ------------------ | ----------------------------------------------- | ----- | ------- | ---------- | ------- | ------------ |
| `guests.view`      | Guest list and guest page                       | ✓     | ✓       | ✓          | ✓       | –            |
| `guests.manage`    | Add and edit guests, tags, VIP mark, notes      | ✓     | ✓       | ✓          | –       | –            |
| `guests.blacklist` | Blacklist a guest and remove from the blacklist | ✓     | ✓       | –          | –       | –            |

## Lessons from wastays

`guests` had no property scope (only `created_by_hotel_id`), a PDPA risk. The blacklist was a boolean plus a jsonb list of property ids in `metadata`, with no reason and no date.
