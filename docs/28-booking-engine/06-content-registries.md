# Content registries

## Purpose

Give both booking modes the same property content: amenities, nearby places and a map. Admins keep shared lists, so properties pick rather than retype.

## Amenities

```
amenities             id, name, type (AmenityType), category, icon,
                      position, active, timestamps
property_amenities    property_id, amenity_id     unique pair
room_type_amenities   room_type_id, amenity_id    unique pair
```

- `AmenityType`: Property, Room.
- The list is global, like `ota_channels`. Link tables replace the jsonb arrays wastays used. The wastays seed list is the starting data: `AmenitySeeder` loads `database/seeders/data/amenities.json` (487 rows, 301 Property and 186 Room) and is safe to run again.
- A name is unique per type, ignoring case. The type never changes after an amenity is added, because properties already link to it. `icon` keeps the wastays icon key for the microsite; no screen edits it yet.
- The pickers are plain tick boxes grouped by category, in list order. A ticked retired amenity shows a "Retired" badge until it is unticked.
- Saving refuses an id that is the wrong type, unknown, or retired and not already ticked, and writes nothing. Room amenities cannot change on an archived room type. Every change is logged (`property.amenities_changed`, `room_type.amenities_changed`, `amenity.*`).
- A retired amenity (`active` off) leaves the pickers and stays on properties until they untick it.

| Who   | Does                                      | Where                       |
| ----- | ----------------------------------------- | --------------------------- |
| Admin | Adds, edits, orders and retires amenities | Admin › Amenities           |
| Owner | Ticks property amenities                  | Booking website › Amenities |
| Owner | Ticks room amenities                      | Room type page              |

Left out for now: per-amenity details, such as opening hours and fees (a concierge page need), and a Channex sync. The Channex `facilities` field is optional. If it is needed later, add `amenities.channex_id`, as wastays had.

## Nearby places

**Built.** The Nearby places tab adds, describes, reorders and removes places, and suggests Approved ones. Admins add and review at `/admin/nearby-places`.

```
nearby_places           id, name, latitude, longitude, google_maps_url,
                        fingerprint (unique), status (NearbyPlaceStatus),
                        created_by_property_id (nullable), timestamps
property_nearby_places  id, property_id, nearby_place_id, display_name (nullable),
                        description (nullable), position, active (default true),
                        timestamps
                        unique (property_id, nearby_place_id)
```

```
Owner pastes a Google Maps link
        │
        ▼
Read name and coordinates from the link
        │
        ├── Already in the registry ─► link to that place
        │
        └── New ─► add as Pending and link to this property
                        │
                        ▼
                Admin approves ─► suggested to nearby properties
```

| Status (admin only) | On the property's own site                     | Suggested to others |
| ------------------- | ---------------------------------------------- | ------------------- |
| Pending             | Yes                                            | No                  |
| Approved            | Yes                                            | Yes                 |
| Rejected            | No: removed, and the owner gets a short notice | No                  |

- "On the property's own site" applies only while the owner's switch is on. The switch sets `active`: an off place keeps its row and description, and the guest API must return only on places. A rejected place leaves the property even when it is off.
- Owners and guests never see the status.
- Suggestions are Approved places within 25 km of the property, nearest first, at most 10.
- Distance is straight-line km, such as "3.8 km". Travel times would need a paid Google API.
- The notice is an in-app notification, `NearbyPlaceRemoved`, sent to the property's first active Owner. It reads: "Pangkor Jetty was removed from your nearby places." It stays in the notification list until read. See [Notification types](../04-notifications/01-notification-types.md).

### Admin adds a place

The Add place dialog takes a Google Maps link. A link it cannot read shows the error in the dialog. Otherwise the dialog closes and admin stays on the open tab.

| Place in the registry | Result                         | Toast                                                 |
| --------------------- | ------------------------------ | ----------------------------------------------------- |
| None                  | Added as Approved, no property | "Pangkor Jetty added."                                |
| Pending               | Becomes Approved               | "Pangkor Jetty was pending. It is now approved."      |
| Approved              | No change                      | "Pangkor Jetty is already in the list."               |
| Rejected              | No change                      | "Pangkor Jetty was rejected. Use Approve on its row." |

"Added by" shows "Admin" for a place with no `created_by_property_id`.

### Owner sets a name for the property

The owner's Edit dialog has an optional "Name on your site". `display_name` shows on that property only and needs no admin review. Other properties and suggestions keep the shared name. Empty, or the same as the shared name, stores null, so the property follows later admin renames. An override stays when an admin renames the place, and it goes when the owner removes the place. The guest API must return `display_name`, then the shared name. The rejection notice uses the name the owner sees.

### Admin renames a place

Google names can be odd, so admins can rename a place in any status from the row's menu. The new name shows on every property that has it. The status, coordinates, link and fingerprint do not change, so pasting the same link again still finds the place and keeps the new name. If Google later changes the name, the same pin becomes a second place, because admins cannot merge or delete a place yet. A permanently closed place should be rejected, not renamed.

### Reading a Google Maps link

`GoogleMapsLink` (a Service) ports the wastays parser (85 lines). It accepts `https` links on `google.com`, `www.google.com` and `maps.google.com`. The pin coordinates (`!3d..!4d..`) win over the map view (`@lat,lng`). It makes no Google API call and has no fee.

| Input                                 | Result                                                                          |
| ------------------------------------- | ------------------------------------------------------------------------------- |
| A `google.com/maps/place/...` address | Name and coordinates from the address                                           |
| A short `maps.app.goo.gl` link        | Follow one redirect on the server, for that host only, then read it             |
| No place name or coordinates          | "This link has no place name. Open the place on Google Maps and copy its link." |

The fingerprint is the normalized name and rounded coordinates, so the same place is not added twice. Left out from wastays: merge, archive states and the older `nearby_attractions` table.

## Property location and map

`properties` gains `latitude`, `longitude` and `google_maps_url`, set by pasting a Maps link in Booking website › General. The link is read when it changes; removing it clears the coordinates. The checklist has a **Location set** row, and a site cannot be published without it.

| Item             | Plan                                                                                                |
| ---------------- | --------------------------------------------------------------------------------------------------- |
| Map              | Google's Maps Embed API, lazy-loaded. Free with no usage limit, but needs a key and billing account |
| Key              | Kept in the microsite, and restricted to `booking.estatell.com`                                     |
| Place search box | Left out, to avoid per-use Places fees                                                              |
| API only mode    | The API returns the same data, and the owner's developer shows the map                              |

Source: [Maps Embed API usage and billing](https://developers.google.com/maps/documentation/embed/usage-and-billing).

## Room photos

The `photos` collection on room types has an upload UI: the Photos tab of the room type page (see [Room types](../05-rooms/01-room-types.md)). Images the guest sees use the public media disk, because the media library's default disk is private. `MEDIA_PUBLIC_DISK` is `public` locally and `r2-public` in production: a separate public R2 bucket (`R2_PUBLIC_BUCKET`) served on a custom domain (`R2_PUBLIC_URL`, for example `https://media.estatell.com`). It reuses the `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` and `R2_ENDPOINT` of the private disk, and the endpoint must have no bucket name in it.

## Admin routes

`/admin/amenities` (`admin.amenities.*`, built: All, Property and Room tabs (the open tab is in the URL as `?type=`, default All) and search, up and down buttons within the filtered rows, and a dialog to add or edit) and `/admin/nearby-places` (`admin.nearby-places.*`, built: All, Pending, Approved and Rejected tabs (the open tab is in the URL as `?status=`, default All) and search, an Add place dialog, and a row menu with Edit name, Approve and Reject). Writes need `support.2fa`.
