# Guest profiles

## Purpose

A simple guest CRM, scoped to one property.

## Data model

```
guests         id, property_id, full_name, email, phone (E.164), nationality_code,
               date_of_birth, gender (Gender), preferred_locale,
               address_line1, address_line2, city, state_code, postal_code, country_code,
               is_vip, blacklisted_at, blacklist_reason, marketing_consent_at,
               merged_into_id (nullable), anonymized_at, timestamps
guest_notes    id, guest_id, author_id, body, is_pinned, timestamps
tags           id, property_id, name
guest_tag      guest_id, tag_id
```

## Services

`GuestStayHistory`: stays, nights, revenue, last stay and no-shows, calculated from `unit_guests`, `unit_nights` and `folio_lines`. Not stored.

## Actions

`CreateGuest`, `UpdateGuest`, `SetVip`, `BlacklistGuest`, `AddGuestNote`.

## Rules

- Guests are never shared across properties.
- Phone numbers are normalized to E.164 with `propaganistas/laravel-phone`.
- Email and phone stay plaintext for search and notifications. Rely on database encryption at rest.

## Permissions

`guest.view`, `guest.manage`, `guest.blacklist`.

## Lessons from wastays

`guests` had no property scope (only `created_by_hotel_id`), a PDPA risk.
