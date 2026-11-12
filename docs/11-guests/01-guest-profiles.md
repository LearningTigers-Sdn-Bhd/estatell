# Guest profiles

## Purpose

A simple guest CRM, scoped to one property.

## Data model

```
guests         id, property_id, full_name, name_key, email, phone (E.164), nationality_code,
               date_of_birth, gender (Gender), preferred_locale (Locale),
               address_line1, address_line2, city, state, postal_code, country_code, tin,
               is_vip, blacklisted_at, blacklist_reason, marketing_consent_at,
               merged_into_id (nullable), anonymized_at, timestamps
guest_notes    id, guest_id, author_id (nullable), body, timestamps
tags           id, property_id, name
guest_tag      guest_id, tag_id
```

- `name_key` is the normalized name used to find duplicates. See [Matching and merging](03-matching-and-merging.md). `CreateGuest` and `UpdateGuest` keep it in step.
- `tin` is the guest's LHDN tax number for e-invoices (`IG…`), uppercase letters and digits. Staff edit it on the guest form (E-invoice). It is not on the booking forms. When staff tick "Save to the guest profile" on an e-invoice, `SetGuestTaxDetails` saves the tax number and IC or passport once LHDN accepts it. See [E-invoices](../31-einvoice/01-lhdn-myinvois.md#checking-a-tax-number).
- `state` is free text, because guests come from any country. Country codes are ISO 3166-1 alpha-2.
- Email and phone are not unique. Family members often share them. [Matching and merging](03-matching-and-merging.md) handles duplicates.
- A blacklisted guest must have a reason (`CHECK`). `merged_into_id` cannot point to the guest itself.
- A tag name is unique per property, ignoring case (a unique index on `lower(name)`).
- `merged_into_id` and `anonymized_at` exist from the start. They are used by merging and privacy.
- Notes are add-only: no edit, no delete, no pinning. wastays had no guest notes, only booking notes that could be edited, with the edit history kept in jsonb.

## Enums

`Gender`: Male, Female. `preferred_locale` uses the existing `Locale` enum.

## Actions

`CreateGuest`, `UpdateGuest`, `SetVip`, `BlacklistGuest`, `UnblacklistGuest`, `AddGuestNote`, `SyncGuestTags`, `SetGuestTaxDetails`.

- Tags are saved with the guest as names. A name that matches an existing tag of the property, ignoring case, reuses it. Any other name creates a tag.
- Create, update, VIP, blacklist and unblacklist are written to the activity log. The update log lists the changed field names, not their values.
- `BlacklistGuest` needs a reason. Blacklisting a guest who is already blacklisted, unblacklisting one who is not, and editing a merged or anonymized guest return `false`. The controller shows an error toast.
- `UnblacklistGuest` clears both `blacklisted_at` and `blacklist_reason`.

## Services

- `GuestDirectory`: the server-paginated list (25 per page). Search matches name, email and phone. Filters: `status` (`vip` or `blacklisted`) and `tag`. Sorts: `full_name`, `created_at`. Merged guests are left out.
- `CountryOptions`: country names from the PHP `intl` extension.
- `GuestStayHistory`: each guest's stays, nights, spend, last visit and source. See [Stay history](#stay-history).

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
| `guests.stays.index`                     | `guests.view`      | Stays tab. See [Stay history](#stay-history).                                                                                                                                                                                                                                            |

## Rules

- Guests are never shared across properties. A guest of another property returns 404.
- Phone numbers are validated and normalized to E.164 with `propaganistas/laravel-phone`. A number typed without a country code is read as a number of the property's country (Malaysia if unset). A number with a country code is accepted from any country.
- `PhoneInput` has a country picker with flags. It starts on the property's country, formats the number as it is typed, also for an edit in the middle (the cursor stays after the same digit), and shows a number typed without its leading 0 (`11 2293 3232` after +60) in the international grouping. It shows an error after the first blur once the number is edited, or at once when the number is too long to ever be valid. A valid number shows a check icon and an error shows a warning icon in the same place. It uses `libphonenumber-js` and sends E.164 when the number is valid. These checks are for the user: the server still decides. A saved number that is not edited is posted unchanged.
- Email and phone stay plaintext for search and notifications. Rely on database encryption at rest.
- Details has one **IC / Passport number** field with its type selector inside, saved with the profile. Scans and additional IDs stay on the Documents tab. Manual entry needs no reader. See [Identity documents](02-identity-documents.md).
- A guest merged into another guest is hidden from the list and redirects to the survivor. See [Matching and merging](03-matching-and-merging.md).
- The profile rules live in `GuestProfileRules`, shared by `GuestRequest` and the booking form (the form's fields are `GuestDetailsFields`).
- `GuestPicker` and `guests.search` come with the [reservation page](../12-reservations/06-reservation-page.md#guests-tab).
- Left for later: a settings page for tags, `marketing_consent_at` (captured on the registration card).

## Stay history

A simple view of how often a guest stays, how much they spend and where they come from. It is calculated on each request from `unit_guests`, `reservation_units` and `folio_lines`. Nothing is stored, so a merge, a reversal or a cancellation is right at once.

### Definitions

| Figure             | Rule                                                                                                                                                                                                                   |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stay               | A reservation unit where the guest is an occupant (`unit_guests`) and the status is Checked in or Checked out. Cancelled, no-show and confirmed units are not stays. Old, past and migrated stays count.               |
| Stays in 12 months | Stays whose departure is on or after the property's today minus one year.                                                                                                                                              |
| Nights             | Departure date minus arrival date of each overnight stay. An early check-out moves the departure, so it counts the nights stayed. A day use counts 0 nights and is counted on its own.                                 |
| Last visit         | The latest departure of a checked-out stay. A guest with a Checked in stay also shows **In house now**.                                                                                                                |
| Source             | The latest stay's company (Perfect Holiday, Sandakan Travel) when the reservation has one, else its booking source.                                                                                                    |
| Spend              | Charge and Adjustment lines (discounts) after reversals. Tax and Payment lines and the Opening balance code are left out. Lines copied from the old system count. Counted whoever pays, so a company-paid stay counts. |
| Returning          | 2 or more stays. One stay is **First stay**. A returning guest gets a `RefreshCw` flag ("Returning guest" tooltip) next to the name, with VIP and Blacklisted, on the guest list and the guest page.                   |

**Which stay a line belongs to.** As in `LineSourceUnits`: a tax or discount child takes its parent's source. A unit night, an add-on or a reservation unit source gives the unit. With no unit source, the line takes its folio's unit. A line on a Master or Third party folio with no unit source takes the reservation's first stay (lowest unit id). Lines of units that are not stays are not counted.

**Who gets the spend.** The unit's primary guest only, so two guests in one room do not count it twice. The other occupants see the stay with no spend. Each line counts once across all guests.

### Guest page

The header shows five tiles above the tabs, on every tab:

```
┌────────────┬────────────┬──────────────┬───────────────┬──────────────────────────┐
│ Stays      │ Nights     │ Spend        │ Last visit    │ Source                   │
│ 6          │ 14         │ MYR 4,820.00 │ 2 Nov 2026    │ Sandakan Travel          │
│ 2 in 12 mo │ 1 day use  │ Without tax  │ In house now  │ Company                  │
└────────────┴────────────┴──────────────┴───────────────┴──────────────────────────┘
```

A guest with no stays shows 0 and "No stays yet". An archived guest keeps the tiles: financial history is kept.

**Contact banner.** When the email or the phone is empty, a warning Alert under the header says "No email." or "No phone." (or both) and "Ask the guest at the next stay." On the other tabs, staff with `guests.manage` get an **Edit details** link to the Details tab, which is the edit form. Archived guests show no banner.

**Stays tab** (`GET /p/{code}/guests/{guest}/stays`, `guests.stays.index`, `guests.view`): every stay, newest arrival first. Columns: Booking (short code, links to the reservation), Dates, Room, Source, Status, Nights, Spend (MYR). Spend shows "—" when the guest is not the primary guest. A footer row sums Nights and Spend. The list is bounded by one guest's stays, so it is not paginated.

### Guest list

- Columns: Last visit, Stays and Spend (MYR), each sortable (`last_visit`, `stays`, `spend`, descending first). Sorting puts guests with no stays last. Source shows under Last visit in small muted text.
- Email and Phone: an empty value shows a warning "No email" or "No phone". Archived guests show "—".
- Filters, in the URL: `filter[source]` (a booking source id: guests with any stay from it), `filter[returning]` (`first` or `returning`) and `filter[contact]` (`no_email`, `no_phone` or `missing`, which is either).
- The figures come from one grouped subquery joined to the page query, so the list stays one query whatever the page size.
- Sorts on Email, Phone and Nationality (`email`, `phone`, `nationality`). Email sorts ignoring case. Nationality sorts by the country name shown (China, Germany, Japan), not by the code: the server passes the codes in name order as one array and sorts by the position in it. Empty values go last in both directions, then by name.

### Guest list layout

The guest list uses the shared report [column layout](../22-reports/01-core-reports.md#column-layouts-per-property): one layout per property, shared by every user.

- `ReportKey::Guests` (slug `guests`) holds the columns. It is not listed in the Reports menu, like Housekeeping tasks. It has **no exports**: no PDF, no spreadsheet and no Preview PDF. `ReportKey::hasExports()` is false for it, and the export and preview routes take only keys that have exports, so `guests` returns 404 there. `report()` has no rows for it.
- Columns, in default order, all visible: Name, Email, Phone, Nationality, Tags, Last visit, Stays (right), Spend (right), Added. Keys: `full_name`, `email`, `phone`, `nationality`, `tags`, `last_visit`, `stays`, `spend`, `created_at`.
- **Name always shows**, because it opens the guest. It is in the Sheet so it can move and align, but its switch is locked on (`lockedKeys` on `ReportLayoutSheet`). The page shows it even if a stored layout says hidden. The checkbox column stays first.
- Staff with `settings.manage` (and `reports.view`, as the layout routes sit under Reports) see a **Layout** button next to Add guest. It opens `ReportLayoutSheet` without the Orientation choice and without Preview PDF (`printable={false}`). Hide, show, reorder and align work as on reports, and the table behind the Sheet follows the draft.
- A sort on a hidden column falls back to Name. The page gets the sort it used.

### Registration card

The Email and Phone fields on the guest screen show "Please fill in." under the field while it is empty and the Layout tab makes it Optional. A Required field already has its star. See [Card fields](../16-registration/01-card-fields.md).

## Bulk selection and actions

The guest list reuses `DataTable` selection and `BulkActionBar`. No new dependency is required.

- Staff see checkboxes only when they can use at least one bulk action. The Archived filter has no selection controls.
- The header checkbox selects or clears the current page (25 guests). Selections stay across pages and sorting, but clear after search or filter changes.
- A selection holds up to 100 guests. There is no select-all-matching action.
- The bar floats at the bottom, within the page content. On desktop it shows the count, **VIP**, **Blacklist**, destructive **Archive**, and Clear.
- On mobile, the count and Clear occupy the first row. Actions occupy the second row. Both menus open upwards.
- Clear or Esc removes the selection. Esc does not clear it while a menu or dialog is open. Clear and actions are disabled during a request.

| Action                | Permission         | Confirmation                                                  |
| --------------------- | ------------------ | ------------------------------------------------------------- |
| Mark as VIP           | `guests.manage`    | None                                                          |
| Unmark as VIP         | `guests.manage`    | None                                                          |
| Blacklist             | `guests.blacklist` | Dialog with one required reason (up to 500 characters)        |
| Remove from blacklist | `guests.blacklist` | AlertDialog warns that reasons are cleared                    |
| Archive               | `guests.anonymize` | AlertDialog with the permanent-deletion warning and `Confirm` |

Only permitted actions appear. Blacklist applies the same reason to each newly blacklisted guest. Existing blacklist reasons stay unchanged.

The `guests.bulk.*` POST routes are `mark-vip`, `unmark-vip`, `blacklist`, `unblacklist` and `anonymize`. Each route checks its existing permission. Requests accept 1–100 distinct guest IDs. Archive also requires the case-sensitive text `Confirm`, ignoring surrounding spaces, on the server.

`GuestDirectory::selected` resolves all IDs within the property before the Action runs. A missing or foreign ID makes the whole request return 404, with no writes.

`BulkUpdateGuests` locks and handles each guest in its own transaction through the existing single-guest Actions. Archived and merged guests are skipped. Guests already in the requested state count as unchanged and produce no audit entry. Archive retains its in-house and open-balance refusals.

The response flashes `GuestBulkResultData` (changed, unchanged and skipped IDs) and a count summary. The list reloads with its current filters, sorting and page. Changed and unchanged guests leave the selection. Refused guests stay selected for review.

## Permissions

| Permission         | Guards                                          | Owner | Manager | Front desk | Finance | Housekeeping |
| ------------------ | ----------------------------------------------- | ----- | ------- | ---------- | ------- | ------------ |
| `guests.view`      | Guest list and guest page                       | ✓     | ✓       | ✓          | ✓       | –            |
| `guests.manage`    | Add and edit guests, tags, VIP mark, notes      | ✓     | ✓       | ✓          | –       | –            |
| `guests.blacklist` | Blacklist a guest and remove from the blacklist | ✓     | ✓       | –          | –       | –            |

## Lessons from wastays

`guests` had no property scope (only `created_by_hotel_id`), a PDPA risk. The blacklist was a boolean plus a jsonb list of property ids in `metadata`, with no reason and no date.
