# Booking sources

## Purpose

Record where every booking came from, in the property's own words (Phone, WhatsApp, Booking.com), so the dashboard Channel mix is right even before the channel manager is on.

**Status: planned.** It replaces the `ReservationSource` enum and `reservations.channel_name`. It lands before the dashboard Performance tab.

## Data model

```
booking_sources   id, property_id (FK, cascade), name (string 60), type (BookingSourceType),
                  ota_channel_id (FK ota_channels, restrict, nullable), is_system (bool, default false),
                  is_active (bool, default true), sort_order (int),
                  badge_icon (BookingSourceIcon, nullable), badge_letters (string 2, nullable),
                  badge_color (SwatchColor), timestamps
                  UNIQUE (property_id, lower(name))
                  UNIQUE (property_id, type) WHERE is_system
                  CHECK (type = 'ota') = (ota_channel_id IS NOT NULL)
                  CHECK type NOT IN ('company', 'walk_in') OR is_system
                  CHECK (badge_icon IS NULL) <> (badge_letters IS NULL)
reservations      + booking_source_id (FK booking_sources, restrict, not null), indexed
                  − source, − channel_name
```

- Each property has its own list. A reservation points to one row, so renaming a source renames it on every booking.
- The row's type carries the rules. Nothing else in the code branches on the name.
- Company and Walk-in are one system row each. The code finds them by type alone.
- An OTA row points to an [OTA channel](../21-channels/01-connections-and-feature-gate.md#ota-channels). Each real channel has at most one row per property. The **Other** channel can have many rows, one per name (for example Traveloka), so this rule lives in the Actions, not in an index.

## Enums

`BookingSourceType`: label, tone and the rules.

| Case    | Label                  | Tone    | Staff can add | Rule                                                           |
| ------- | ---------------------- | ------- | ------------- | -------------------------------------------------------------- |
| Direct  | Direct                 | neutral | Yes           | None                                                           |
| Ota     | OTA                    | info    | Yes           | Needs an OTA channel. The booking needs an External reference. |
| Company | Company / Travel agent | warning | No (system)   | The booking needs a company.                                   |
| WalkIn  | Walk-in                | success | No (system)   | Only in Walk-in mode. Every unit needs a room or bed.          |

- `options()` lists the types staff can add: Direct and OTA.
- `requiresOtaChannel()`, `requiresExternalReference()`, `requiresCompany()` and `isWalkIn()` hold the rules.

`BookingSourceIcon`: the icons a badge can show. The value is the Lucide name, and `label()` is the picker's accessible name. The frontend maps each case to its Lucide component in one typed `Record`, so a missing icon fails `tsc`.

| Case              | Lucide               | Label    |
| ----------------- | -------------------- | -------- |
| Phone             | `phone`              | Phone    |
| MessageCircle     | `message-circle`     | Chat     |
| Mail              | `mail`               | Email    |
| Globe             | `globe`              | Website  |
| Users             | `users`              | People   |
| Handshake         | `handshake`          | Partner  |
| BriefcaseBusiness | `briefcase-business` | Business |
| Building2         | `building-2`         | Office   |
| Footprints        | `footprints`         | Walk-in  |
| Ticket            | `ticket`             | Voucher  |
| Megaphone         | `megaphone`          | Campaign |
| Star              | `star`               | Star     |

`SwatchColor`: Gray, Red, Orange, Amber, Green, Teal, Blue, Indigo, Violet, Pink, with `label()` and `options()`. Each case is a pair of [swatch tokens](../design-system/02-tokens-and-typography.md#tokens) (`bg-swatch-blue text-swatch-blue-foreground`), never a hex value or a palette class. `forOtaChannelCode(string)` gives the default color of a new OTA row: `booking_com` Blue, `agoda` Red, `expedia` Amber, `airbnb` Pink, `hostelworld` Orange, anything else Gray.

## Badge

Every source has a badge: a small rounded square with **an icon or one or two letters** on a **background color**. The property picks both.

```
[☎]  Phone          (B)  Booking.com       [💼]  Company / Travel agent
gray, icon          blue, letter           amber, icon
```

- Letters are upper case, one or two characters, letters or digits (`B`, `AG`, `T1`).
- A new Direct source starts with the Globe icon on Gray. A new OTA source starts with the first letter of its name on `SwatchColor::forOtaChannelCode()`.
- Color never carries meaning alone: the badge always has the source name as its accessible name and tooltip, and the List view shows the name beside it.
- `components/pms/booking-source-badge.tsx` (`BookingSourceBadge`, sizes `sm` and `default`) draws it from `BookingSourceBadgeData` (name, type, icon, letters, color). The settings page and the reservations list use the same component.

### Where it shows

| Place                                                       | Shows                                                                                                       |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| [Reservation card](06-reservation-page.md#reservation-card) | The badge in the badges row, after `StatusBadge`, in place of the old source icon. Tooltip: the source name |
| [List view](06-reservation-page.md#list-view) Source column | The badge, then the source name. OTA bookings add `external_reference` under the name                       |
| Settings → Booking sources                                  | The badge before the name in each row, and a live preview in the Dialog                                     |

The reservation page header and summary card keep the source name as text.

## Defaults

`SeedBookingSources` runs inside [`SeedPropertyDefaults`](../02-property/03-seed-property-defaults.md). It is idempotent: a repeat run restores missing system rows and keeps property changes.

| Name                   | Type    | System | Badge                     |
| ---------------------- | ------- | ------ | ------------------------- |
| Phone                  | Direct  | No     | Phone icon, Gray          |
| WhatsApp               | Direct  | No     | MessageCircle icon, Green |
| Email                  | Direct  | No     | Mail icon, Blue           |
| Company / Travel agent | Company | Yes    | BriefcaseBusiness, Amber  |
| Walk-in                | WalkIn  | Yes    | Footprints icon, Teal     |

OTA rows come from the onboarding [OTA lists](../17-onboarding/02-wizard-steps.md#channel-manager-table). Basics seeds the defaults before the OTA lists step, so `SaveOtaCredentials` calls `EnsureOtaBookingSource` for each saved row: a real channel gets a row with the channel's name, and Other gets a row with the typed channel name. Removing a credential row never removes its source.

## Rules

- A Company / Travel agent booking needs `company_id`. A Direct or OTA booking may still have a company.
- Staff and the [company portal](../23-company-portal/01-company-portal.md) both use the one Company / Travel agent row. A portal booking is the one whose `created_by` is a company user.
- An OTA booking needs `external_reference`, whether staff type it in or [ingestion](../21-channels/05-booking-ingestion.md) creates it.
- Ingestion puts each booking on the OTA row of its channel and creates the row when it is missing. Manual and automatic bookings for the same OTA share one row.
- Renaming an OTA channel on the admin Channels page does not rename the property's source.
- A source used by a booking is never deleted. Staff turn it off instead. An inactive source leaves the booking form and stays on its bookings and in the list filter.
- The type and OTA channel of a source are locked once a booking uses it.
- System rows cannot be renamed, turned off, reordered or deleted. Their badge can change.
- Names are unique within a property, ignoring case.

## Settings page

`GET /p/{code}/settings/booking-sources` (`settings.booking-sources.index`), needs `settings.manage`. It follows the [Charge items](../06-catalogues/02-charge-items.md#pages) list.

```
Settings › Booking sources
Booking sources                                                   [+ New booking source]
Where your bookings come from. Staff pick one on every booking.

Search booking sources
[ e.g. WhatsApp                                   ]

┌───────────┬────────────────────────────────┬─────────┬─────────────┬──────┬──────────────────┐
│ Available │ Booking source                 │ Type    │ OTA channel │ Used │                  │
├───────────┼────────────────────────────────┼─────────┼─────────────┼──────┼──────────────────┤
│   [●]     │ [☎] Phone                      │ Direct  │ –           │ 12   │  ↑  ↓       Edit │
│   [●]     │ [💬] WhatsApp                  │ Direct  │ –           │ 0    │  ↑  ↓       Edit │
│   [●]     │ (B) Booking.com                │ OTA     │ Booking.com │ 3    │  ↑  ↓       Edit │
│   [ ]     │ [✉] Email                      │ Direct  │ –           │ 0    │  Inactive   Edit │
│   [●]🔒   │ [💼] Company / Travel agent  System │ Company │ –      │ 4    │             Edit │
│   [●]🔒   │ [👣] Walk-in                 System │ Walk-in │ –      │ 1    │             Edit │
└───────────┴────────────────────────────────┴─────────┴─────────────┴──────┴──────────────────┘
```

New or Edit booking source Dialog:

```
┌ Edit booking source ─────────────────────────────┐
│ Name         [Booking.com                      ] │
│ Type         OTA 🔒        OTA channel  Booking.com 🔒 │
│ Badge        ( Icon | ●Letter )   Letters [B ]   │
│              icon grid when Icon: ☎ 💬 ✉ 🌐 👥 🤝 💼 🏢 👣 🎫 📣 ★ │
│ Color        ● ● ● ● ● ● ●(✓) ● ● ●              │
│ Preview      (B) Booking.com                     │
│ In use by 3 bookings. Turn it off with Available.│
│                               [Cancel]  [Save]   │
└──────────────────────────────────────────────────┘
```

| Part                   | Behaviour                                                                                                                                                                                                                                                                           |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Available** switch   | First column. Saves at once with an optimistic update, and rolls back with an error toast when refused. Disabled while it saves.                                                                                                                                                    |
| Order                  | Active rows first, in `sort_order`, with ↑ ↓. Inactive rows below with an **Inactive** badge and no arrows. Turning a row back on puts it at the end of the active list.                                                                                                            |
| Search                 | Name, type and OTA channel.                                                                                                                                                                                                                                                         |
| Used                   | The number of reservations on the source (`BookingSourceUsage`).                                                                                                                                                                                                                    |
| **New booking source** | A Dialog: **Name** ("e.g. WhatsApp"), **Type** (Direct or OTA), and for OTA an **OTA channel** select of the active channels. Other also asks for a **Channel name**, which becomes the name. Toast: "WhatsApp created."                                                            |
| Badge                  | **Icon** or **Letter**, a `ToggleGroup`. Icon shows a grid of the `BookingSourceIcon` cases (one `ToggleGroup`, each item with its label as `aria-label`). Letter shows **Letters** (max 2, "e.g. B"). Switching keeps the other value until Save, which sends only the chosen one. |
| Color                  | A radio group of the ten `SwatchColor` swatches, each with its label as accessible name and a check on the chosen one.                                                                                                                                                              |
| **Edit**               | The same Dialog for the row. Type and OTA channel are locked once the source is used. **Delete** shows only while no booking uses it, with an AlertDialog. A used source says "In use by N bookings. Turn it off with Available."                                                   |
| System rows            | **System** badge, switch locked on with the tooltip "Always available", no arrows. **Edit** opens the Dialog with only Badge and Color editable. They sit at the end.                                                                                                               |
| Empty search           | "No matching booking sources", with "Change the search or add a new booking source."                                                                                                                                                                                                |

| Route                                               | Name                               |
| --------------------------------------------------- | ---------------------------------- |
| `POST …/settings/booking-sources`                   | `settings.booking-sources.store`   |
| `PUT …/settings/booking-sources/order`              | `settings.booking-sources.reorder` |
| `PUT …/settings/booking-sources/{bookingSource}`    | `settings.booking-sources.update`  |
| `DELETE …/settings/booking-sources/{bookingSource}` | `settings.booking-sources.destroy` |

All need `settings.manage` and use `scopeBindings`. The controller is `Reservations/BookingSourceController`, the page `pages/settings/booking-sources/index.tsx`.

## Actions and Services

| Class                                 | Kind    | Does                                                                                                                                                                         |
| ------------------------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Reservations/SeedBookingSources`     | Action  | The defaults above                                                                                                                                                           |
| `Reservations/CreateBookingSource`    | Action  | Adds a Direct or OTA row at the end of the active list. Logs `booking_source.created`                                                                                        |
| `Reservations/UpdateBookingSource`    | Action  | Name, Available and badge, and type or OTA channel while unused. On a system row only the badge changes; anything else returns false. Logs `booking_source.updated`          |
| `Reservations/ReorderBookingSources`  | Action  | Like `ReorderChargeItems`: returns false unless the ids are exactly the active rows that are not system rows ("The booking sources changed. Reload the page and try again.") |
| `Reservations/DeleteBookingSource`    | Action  | Returns false when the source is used or a system row. Logs `booking_source.deleted`                                                                                         |
| `Reservations/EnsureOtaBookingSource` | Action  | `(Property, OtaChannel, ?name): BookingSource`. Finds or creates the OTA row under a lock. Used by `SaveOtaCredentials` and, later, `IngestChannelBooking`                   |
| `Reservations/BookingSourceOptions`   | Service | The [booking form](05-booking-form.md#sections) list and the [list filter](06-reservation-page.md#controls) options                                                          |
| `Reservations/BookingSourceUsage`     | Service | Reservations per source, for the Used column and the delete check                                                                                                            |

Expected refusals return false and the controller shows an error toast, as in `.ai/rules/actions.md`.

## Backfill

One migration, with plain DB writes (no Actions), in this order:

1. Create `booking_sources`. Insert the defaults for every property, with their badges, and one OTA row for each `property_ota_credentials` row (first letter, `forOtaChannelCode` color).
2. Add `booking_source_id` as nullable and map the old values:

| Old `source`               | New row                                                                                                                                                                                         |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `phone`                    | Phone                                                                                                                                                                                           |
| `walk_in`                  | Walk-in                                                                                                                                                                                         |
| `portal`                   | Company / Travel agent                                                                                                                                                                          |
| `direct`                   | A Direct row named "Direct" (Globe icon, Indigo), created only in properties with such bookings. Staff can rename it (for example "Website") or turn it off. `company_id` is not used to guess. |
| `channel` + `channel_name` | The OTA row of the channel whose name matches, ignoring case. No match: an OTA row on Other with that name. No name: an OTA row on Other named "OTA".                                           |

3. Make `booking_source_id` not null. Drop `source` and `channel_name`.
4. `down()` maps back from the type, with the name as `channel_name` for OTA rows.

Before running it in production or demo, check `select source, channel_name, count(*) from reservations group by 1, 2`. On local data (2026-10-18): direct 54 (4 with a company), walk_in 1, phone 1, channel 1 ("Booking.com"), portal 0.

## Build order

Four commits after the docs commit, as in the [checklist](../checklist.md#next):

1. **Replace the reservation source with booking sources.** `BookingSourceType`, `BookingSourceIcon`, `SwatchColor` and the swatch tokens, `booking_sources`, `BookingSource` with its factory, `SeedBookingSources` and the backfill, with tests. `ReservationFactory` gets a source of the same property, with `walkIn()`, `ota()` and `company()` states. `NewReservationData` and `NewBookingData` take a `BookingSource` (no `source`, no `channelName`). `CreateReservation` checks that the source belongs to the property and logs its id and name. `StoreBookingRequest`, `ReservationBookingController` and the booking form. `ReservationData` (`sourceName`), `ReservationListItemData`, `ReservationDirectory`, the list filter, the subtitle and the reservation page. The seeders (Sandbay, Boreno, BookingScenarios). Delete `ReservationSource`. Regenerate Wayfinder and the TypeScript types. This commit drops `source` and `channel_name`, so every reader and writer changes in it.
2. **Show the booking source badge.** `BookingSourceBadge` and `BookingSourceBadgeData` (`ReservationListItemData.source`) on the card and the List view.
3. **Manage booking sources.** The settings page and its Actions.
4. **Add OTA lists to booking sources.** `EnsureOtaBookingSource` in `SaveOtaCredentials`.

## Lessons from wastays

- wastays had one global `booking_sources` list, managed by admins (`kind` = manual or ota). Hotels could not add WhatsApp or a business invitation.
- `bookings.source` copied the source key as a string, not a foreign key. estatell links each booking to its row.
- wastays badges stored hex colors (`badge_color`, `badge_text_color`) and an initial. estatell keeps the idea with a fixed set of swatch tokens, so every pair passes contrast in light and dark.
