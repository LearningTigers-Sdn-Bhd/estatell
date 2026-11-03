# Settings and permissions

## Purpose

Let the property set up its booking website, and let support help without extra rules.

## Data model

```
property_booking_sites   id, property_id (unique), mode (BookingEngineMode),
                         site_template_id (nullable), main_color (nullable),
                         about_text (nullable), seo_title (nullable),
                         seo_description (nullable), published_at (nullable),
                         timestamps
properties               + slug (unique, nullable), latitude, longitude, google_maps_url
rate_plans               + bookable_online (bool, default false)
```

- `BookingEngineMode`: Microsite, ApiOnly.
- Slugs are lower case letters, digits and hyphens, 3 to 50 characters. A property gets one from its name when the General tab is first opened (`SetUpBookingSite`, which also creates the `property_booking_sites` row as Microsite). Until it is published, the owner can edit it on the General tab. After publishing, only admins change it, because a change breaks links already shared.
- `site_template_id` has no foreign key yet. The template registry item adds it.
- **Built so far:** the flag, the tables, the three permissions, the General tab with Publish and Unpublish, the Amenities and Nearby places tabs, the property location read from the Maps link, and the checklist service (`BookingSiteChecklist`) with the Template and Location rows. Each later item adds its own checklist row. Not built yet: the about text on the General tab, the admin switch for the mode, and the admin change of a published slug.

## Booking website settings

Settings › Booking website (`/p/{code}/settings/booking-website`, `settings.booking-website.*`) appears when the flag is on. Each tab is its own URL, controller method and page.

| Tab           | Microsite | API only | Holds                                                 | Permission                                                           |
| ------------- | --------- | -------- | ----------------------------------------------------- | -------------------------------------------------------------------- |
| General       | Yes       | Yes      | Status, checklist, web address, Maps link, about text | `booking-website.manage`; publishing needs `booking-website.publish` |
| Design        | Yes       | No       | Template, images, main colour                         | `booking-website.manage`                                             |
| Amenities     | Yes       | Yes      | Property amenities                                    | `booking-website.manage`                                             |
| Nearby places | Yes       | Yes      | Add a place, suggestions, your places                 | `booking-website.manage`                                             |
| Rates         | Yes       | Yes      | Which rate plans guests can book                      | `booking-website.manage`                                             |
| SEO           | Yes       | No       | Google title and description                          | `booking-website.manage`                                             |
| API           | No        | Yes      | Token, return addresses, docs link                    | `booking-website.api`                                                |

### General

```
┌─ General ──────────────────────────────────────────────┐
│ Status         ○ Not published                         │
│ Web address    booking.estatell.com/sand-bay           │
│                [ Copy link ]  [ Open site ]            │
│ Google Maps    [ e.g. https://www.google.com/maps/...] │
│ About          [ e.g. A quiet beach resort in Pangkor ]│
│                                                        │
│ Before you publish                                     │
│ ✓ Template chosen                                      │
│ ✓ Hero image added                                     │
│ ✗ Room photos            2 room types have none   Fix  │
│ ✗ Public rate plan       None chosen yet          Fix  │
│ ✓ Cancellation policy set                              │
│ ✓ Payment set up by estatell                           │
│                                                        │
│                                 [ Publish (disabled) ] │
└────────────────────────────────────────────────────────┘
```

The checklist is calculated from data. In API only mode, Publish becomes **Go live**, and the template and hero image rows are left out. Unpublishing takes the site offline, and booked guests keep their Manage booking link.

### Design

```
┌─ Design ───────────────────────────────────────────────┐
│ Template      [ Choose a template                   ▾ ]│
│                                                        │
│ Hero image    [ beach.jpg ]                [ Replace ] │
│ Logo          [ logo.png  ]                [ Replace ] │
│ About image   [ Empty     ]                [ Upload  ] │
│ Main colour   [ e.g. #0F766E ]                         │
│                                                [ Save ]│
└────────────────────────────────────────────────────────┘
```

The rows follow the chosen template's [image slots](08-microsite.md#template-registry). Images are media on `PropertyBookingSite`, on a public disk, with the slot key as a custom property.

### Amenities

```
┌─ Amenities ────────────────────────────────────────────┐
│ [✓] Wi-Fi  [✓] Pool  [ ] Gym  [✓] Parking              │
│                                      [ Save amenities ]│
└────────────────────────────────────────────────────────┘
```

### Nearby places

Each action saves on its own.

```
┌─ Nearby places ────────────────────────────────────────┐
│ Google Maps link                                       │
│ [ e.g. https://www.google.com/maps/place/Pangkor...  ] │
│                                                [ Add ] │
│ Suggested near you                                     │
│ Teluk Nipah Beach      2.1 km                  [ Add ] │
│                                                        │
│ Your places                                            │
│ Pangkor Jetty          6.4 km                     ⋯    │
│ Dutch Fort             3.8 km                     ⋯    │
└────────────────────────────────────────────────────────┘
```

### Rates

```
┌─ Rates ────────────────────────────────────────────────┐
│ Bookable     Rate plan          Room types             │
│ [●]          Basic Rate         Deluxe King, Twin      │
│ [●]          Breakfast          Deluxe King            │
│ [ ]          3D2N Island Escape Deluxe King            │
│                                                        │
│ Company-only and Day use plans are not listed.         │
└────────────────────────────────────────────────────────┘
```

Only active plans with `company_access` Everyone appear. Each switch saves `rate_plans.bookable_online` at once.

### SEO

```
┌─ SEO ──────────────────────────────────────────────────┐
│ Page title     [ e.g. Sand Bay Resort, Pangkor       ] │
│ Description    [ e.g. Beachfront rooms with breakfast ]│
│                                                        │
│ Google preview                                         │
│ Sand Bay Resort, Pangkor                               │
│ booking.estatell.com/sand-bay                          │
│ Beachfront rooms with breakfast...                     │
│                                                [ Save ]│
└────────────────────────────────────────────────────────┘
```

## What stays elsewhere

| Item                                 | Where                                 |
| ------------------------------------ | ------------------------------------- |
| Room photos and room amenities       | Room type page                        |
| Room name, description, guest limits | Room type page                        |
| Prices and restrictions              | Rate calendar                         |
| Cancellation policy                  | Settings › Room revenue               |
| Online bookings                      | Reservations, source "Booking Engine" |
| Commission and payouts               | Commission fees                       |

## Permissions

| Permission                | Allows                                     | Default role |
| ------------------------- | ------------------------------------------ | ------------ |
| `booking-website.manage`  | Edit every tab except API; pick a template | Owner        |
| `booking-website.publish` | Publish or unpublish                       | Owner        |
| `booking-website.api`     | Manage the API token and return addresses  | Owner        |

Each role can change in [Roles and permissions](../03-staff/02-roles-and-permissions.md). A migration syncs the three permissions.

| Who                               | Access                                                                                                                         |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Property staff                    | Through their role                                                                                                             |
| Admin or partner on property tabs | Through [view as](../01-platform/04-impersonation.md), with the chosen staff member's role, if the owner allows support access |
| Admin only                        | The flag, the mode, Curlec setup, slug changes after publishing, and the three registries                                      |

- API token and return address changes use `block.impersonation`, like passwords.
- Every publish, unpublish and template change is logged with who did it.
- Partners see the flag read-only, as on the Features tab.
