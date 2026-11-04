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
- `site_template_id` is a foreign key to `site_templates`, and a template in use cannot be deleted.
- **Built so far:** the flag, the tables, the three permissions, the General tab with Publish and Unpublish, the Amenities and Nearby places tabs, the property location read from the Maps link, and the checklist service (`BookingSiteChecklist`) with the Template, Required images and Location rows, the [template registry](08-microsite.md#template-registry), and the Design and SEO tabs. Each later item adds its own checklist row. Not built yet: the about text on the General tab, the admin switch for the mode, and the admin change of a published slug.

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

The rows follow the saved template's [image slots](08-microsite.md#template-registry), so they appear after the template is saved. Images are media on `PropertyBookingSite`, on a public disk, with the slot key as a custom property. Each file uploads as soon as it is picked. Changing the template keeps images in slots the new template also has, because images are matched by slot key.

- The picker lists Available Standard templates, the property's own Available Custom templates, and the template already in use, even if it was retired. The server refuses any other template.
- The main colour shows only when the saved template allows it. It is a six digit hex value, such as `#0F766E`, and it is cleared when the template does not allow one.
- "Required images added" on the checklist lists the required slots of the saved template that are still empty.
- Design and SEO answer 404 for an API only property. Until the admin switch for the mode is built, the tabs show for every property.

### Amenities

```
┌─ Amenities ────────────────────────────────────────────┐
│ [ Search ]                           ( Grouped | List )│
│ Available   Amenity                                    │
│ ▸ General (3 of 27)                                    │
│ ▾ Parking (2 of 8)                                     │
│ (on )       Bike parking                               │
│ (off)       Free parking                               │
└────────────────────────────────────────────────────────┘
```

Each switch saves at once. See [Amenities](06-content-registries.md#amenities).

### Nearby places

One table. Each action saves on its own. On places come first, in the owner's order. Off places and suggestions follow, nearest first.

```
┌─ Nearby places ─────────────────────────── [ + Add place ] ┐
│ On site  Place                  Distance                   │
│ (on )    Pangkor Jetty            6.4 km   [↑][↓][ Edit ]  │
│          Ferry to the island                               │
│ (on )    Dutch Fort               3.8 km   [↑][↓][ Edit ]  │
│ (off)    Imago Shopping Mall      1.2 km      Suggested    │
│ (off)    Teluk Nipah Beach        5.0 km         [ Edit ]  │
└────────────────────────────────────────────────────────────┘
```

| Row       | Switch | Arrows | Edit | Guests see it |
| --------- | ------ | ------ | ---- | ------------- |
| On        | On     | Yes    | Yes  | Yes           |
| Off       | Off    | No     | Yes  | No            |
| Suggested | Off    | No     | No   | No            |

- **Add place** opens a dialog for the Google Maps link. A link it cannot read keeps the dialog open with the error.
- **Edit** opens a dialog for the property's own name and the description, with **Remove place**. Removing deletes the row, the name and the description, and an Approved place within 25 km comes back as a suggestion.
- Turning a suggestion on adds it to the property. Turning a place back on puts it at the end of the on places.

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
