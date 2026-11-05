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
- **Built so far:** the flag, the tables, the three permissions, the General tab with Publish and Unpublish, the Amenities and Nearby places tabs, the property location read from the Maps link, and the checklist service (`BookingSiteChecklist`) with the Template, Required images, Property photos, Room photos and Location rows, the [template registry](08-microsite.md#template-registry), the Design and SEO tabs with Choose from album, and the Rates tab with the Public rate plan row. Each later item adds its own checklist row. The about text on the General tab (up to 1000 characters, empty clears it). The admin switch for the mode (see [Mode switch](#mode-switch)). Not built yet: the admin change of a published slug.

## Booking website settings

Settings › Booking website (`/p/{code}/settings/booking-website`, `settings.booking-website.*`) appears when the flag is on. Each tab is its own URL, controller method and page.

| Tab           | Microsite | API only | Holds                                      | Right rail                    | Permission                                                           |
| ------------- | --------- | -------- | ------------------------------------------ | ----------------------------- | -------------------------------------------------------------------- |
| General       | Yes       | Yes      | Status, web address, Maps link, about text | Checklist, Publish or Go live | `booking-website.manage`; publishing needs `booking-website.publish` |
| Design        | Yes       | No       | Template, main colour                      | Images                        | `booking-website.manage`                                             |
| Amenities     | Yes       | Yes      | Property amenities                         | None                          | `booking-website.manage`                                             |
| Nearby places | Yes       | Yes      | Add a place, suggestions, your places      | None                          | `booking-website.manage`                                             |
| Rates         | Yes       | Yes      | Which rate plans guests can book           | None                          | `booking-website.manage`                                             |
| SEO           | Yes       | No       | Google title and description               | Google preview                | `booking-website.manage`                                             |
| API           | No        | Yes      | Token, return addresses                    | Developer quick start         | `booking-website.api`                                                |

The header is the same on every tab: the title, "Let guests book and pay online.", then a badge row with the publish status (Published in success, Not published in neutral) and the mode (Microsite with a globe icon, API only with a code icon, both in info). The `booking-engine` middleware shares the badges as `bookingWebsiteHeader` (`BookingWebsiteHeaderData`).

The tabs use `Workspace` with `scroll="page"`: bordered panels as on the reservation page that fill the height, but the page scrolls and the rail's content stays in view. Below `lg` the rail comes after the tab content. A tab with no rail has one full-width panel.

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
│ ✓ Required images added                                │
│ ✗ Property photos        None added yet           Fix  │
│ ✗ Room photos            2 room types have none   Fix  │
│ ✗ Public rate plan       None chosen yet          Fix  │
│ ✓ Cancellation policy set                              │
│ ✓ Payment set up by estatell                           │
│                                                        │
│                                 [ Publish (disabled) ] │
└────────────────────────────────────────────────────────┘
```

The checklist is calculated from data. In API only mode, Publish becomes **Go live**, and the template and required images rows are left out. Unpublishing takes the site offline, and booked guests keep their Manage booking link.

- **Property photos** is done when the [property album](../02-property/01-properties.md#photos) has at least one photo. Both modes have this row.
- **Room photos** is done when every active room type has at least one photo. Both modes have this row.

### Design

```
┌─ Design ─────────────────────────────────────────────────┐
│ Template      [ Choose a template                     ▾ ]│
│                                                          │
│ Hero image *  [ beach.jpg ]                              │
│               [ Choose from album ] [ Upload ] [ Remove ]│
│ Logo          [ logo.png  ]                              │
│               [ Choose from album ] [ Upload ] [ Remove ]│
│ About image   Empty                                      │
│               [ Choose from album ] [ Upload ]           │
│ Main colour   [ e.g. #0F766E ]                           │
│                                                  [ Save ]│
└──────────────────────────────────────────────────────────┘
```

The rows follow the saved template's [image slots](08-microsite.md#template-registry), so they appear after the template is saved. Images are media on `PropertyBookingSite`, on a public disk, with the slot key as a custom property. Changing the template keeps images in slots the new template also has, because images are matched by slot key.

The owner fills each slot in one of two ways. Both replace what the slot held and save at once.

| Action            | What happens                                                                     | Added to the album?   |
| ----------------- | -------------------------------------------------------------------------------- | --------------------- |
| Choose from album | Copies a photo from the [property album](../02-property/01-properties.md#photos) | It stays in the album |
| Upload            | Saves the file to the slot only                                                  | No                    |

```
┌─ Choose the Hero image ──────────────────────── [ x ] ┐
│ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐     │
│ │✓beach │ │ lobby │ │ pool  │ │ cafe  │ │garden │     │
│ └───────┘ └───────┘ └───────┘ └───────┘ └───────┘     │
│                                                       │
│                              [ Cancel ]  [ Use photo ]│
└───────────────────────────────────────────────────────┘
```

- A slot always owns its image. Deleting or reordering album photos never changes the website, so the album needs no delete warnings.
- Replacing a photo in the album does not update the website. The owner chooses it again on this tab.
- An upload can be a website-only image, such as a banner with text. It never appears in the property gallery.
- `CopyAlbumPhotoToSite` (`app/Actions/BookingEngine/`) checks that the slot exists and the photo belongs to the property. It uses the media library's `copy()`. Route: `POST design/images/{slot}/from-album`, `settings.booking-website.design.images.copy`.
- The dialog needs `booking-website.manage`. With an empty album, it says so and links to the Photos tab. The album itself is managed with `settings.manage`.

- The picker lists Available Standard templates, the property's own Available Custom templates, and the template already in use, even if it was retired. The server refuses any other template.
- The main colour shows only when the saved template allows it. It is a six digit hex value, such as `#0F766E`, and it is cleared when the template does not allow one.
- "Required images added" on the checklist lists the required slots of the saved template that are still empty.
- Design and SEO answer 404 for an API only property, and the API tab answers 404 for a Microsite property. The `booking-engine` middleware shares `bookingMode`, so the tab links show only the tabs of the property's mode.

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

Only active, nightly plans with `company_access` Everyone appear (`RatePlan::scopeOfferableOnline`), in the Rate plans order. Package plans are listed. Each switch saves `rate_plans.bookable_online` at once (`SetRatePlanBookableOnline`, route `settings.booking-website.rates.update`).

- The same switch is a **Book online** column on Settings › Rates › Rate plans, and a **Book online** switch inside the Details tab form (saved with Save details, and a refusal stops the save). They show only when the flag is on and the user has `booking-website.manage`, and it is disabled for a plan the Rates tab would not list. The list column saves at once. Both use `SetRatePlanBookableOnline`, so the same rules apply. Without the permission, the Details form ignores the field.
- A plan can only be turned on if it is listed. A plan that is later archived or made company-only stays flagged, but guests never see it. Readers use `scopeBookableOnline`, which checks active, nightly, Everyone and the flag.
- On a published site, turning off the last bookable plan is refused with an error toast. On an unpublished site the owner can turn every plan off.
- The Public rate plan row on the General tab is done when at least one plan is bookable.

### SEO

```
┌─ SEO ────────────────────────────────┐ ┌─ Google preview ──────────────┐
│ Page title  [ e.g. Sand Bay Resort ] │ │ Sand Bay Resort, Pangkor      │
│ Description [ e.g. Beachfront ...  ] │ │ booking.estatell.com/sand-bay │
│                             [ Save ] │ │ Beachfront rooms with ...     │
└──────────────────────────────────────┘ └───────────────────────────────┘
```

## What stays elsewhere

| Item                                 | Where                                 |
| ------------------------------------ | ------------------------------------- |
| Property photos                      | Settings › Details › Photos           |
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

### Mode switch

Admins set the mode on the admin property page's **Features** tab. A Microsite / API only toggle shows under the Booking engine switch while the flag is on. Partners see the mode read-only.

| Item    | Rule                                                                                                                                       |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Route   | `PUT /admin/properties/{code}/booking-engine-mode` (`admin.properties.booking-engine-mode.update`), `can:admin` and `support.2fa`          |
| Action  | `SetBookingEngineMode`. Refuses, writing nothing, when the flag is off or the mode is unchanged. Logs `booking-website.mode-changed`       |
| Effects | The new mode starts offline: a published site is unpublished. Leaving API only revokes the API token, so the owner's website stops at once |
| Confirm | The toggle asks first, because the change takes the current online booking offline                                                         |

- Every publish, unpublish and template change is logged with who did it.
- Partners see the flag read-only, as on the Features tab.
