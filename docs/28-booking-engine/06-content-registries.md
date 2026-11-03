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
- The list is global, like `ota_channels`. Link tables replace the jsonb arrays wastays used. The wastays seed list is the starting data.
- A retired amenity (`active` off) leaves the pickers and stays on properties until they untick it.

| Who   | Does                                      | Where                     |
| ----- | ----------------------------------------- | ------------------------- |
| Admin | Adds, edits, orders and retires amenities | Admin › Amenities         |
| Owner | Ticks property amenities                  | Booking website › Content |
| Owner | Ticks room amenities                      | Room type page            |

Left out for now: per-amenity details, such as opening hours and fees (a concierge page need), and a Channex sync. The Channex `facilities` field is optional. If it is needed later, add `amenities.channex_id`, as wastays had.

## Nearby places

```
nearby_places           id, name, latitude, longitude, google_maps_url,
                        fingerprint (unique), status (NearbyPlaceStatus),
                        created_by_property_id (nullable), timestamps
property_nearby_places  id, property_id, nearby_place_id, description (nullable),
                        position, timestamps
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

- Owners and guests never see the status.
- Suggestions are Approved places within 25 km of the property, nearest first, at most 10.
- Distance is straight-line km, such as "3.8 km". Travel times would need a paid Google API.
- The notice reads: "Pangkor Jetty was removed from your nearby places."

### Reading a Google Maps link

`GoogleMapsLink` (a Service) ports the wastays parser (85 lines). It makes no Google API call and has no fee.

| Input                                 | Result                                                                          |
| ------------------------------------- | ------------------------------------------------------------------------------- |
| A `google.com/maps/place/...` address | Name and coordinates from the address                                           |
| A short `maps.app.goo.gl` link        | Follow one redirect on the server, for that host only, then read it             |
| No place name or coordinates          | "This link has no place name. Open the place on Google Maps and copy its link." |

The fingerprint is the normalized name and rounded coordinates, so the same place is not added twice. Left out from wastays: merge, archive states and the older `nearby_attractions` table.

## Property location and map

`properties` gains `latitude`, `longitude` and `google_maps_url`, set by pasting a Maps link in Booking website › General.

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

`/admin/amenities` (`admin.amenities.*`) and `/admin/nearby-places` (`admin.nearby-places.*`). Writes need `support.2fa`.
