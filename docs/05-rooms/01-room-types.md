# Room types and sell units

## Purpose

A room type is what a property sells: a room or a bed.

## Data model

```
room_types    id, property_id, name, description, sell_unit (SellUnit),
              max_adults, max_children, base_occupancy,
              smoking_allowed, pets_allowed,
              terms_document_id (nullable), position, archived_at, timestamps
```

## Enums

`SellUnit`: Room, Bed.

## Rules

- `sell_unit` is set on the room type, not on the property. A hostel can sell private rooms and dorm beds.
- A Bed room type has rooms with [beds](03-beds.md). Inventory is counted in beds.
- `base_occupancy` is "Guests included": the number of guests a PerRoom price covers. It lives only on the room type, because a villa and a standard room differ.
- A room type sells many rate plans through `rate_plan_room_types`. A Bed room type links only PerBed and Package plans (see [Rate plans](../08-rates/01-rate-plans.md)).
- A new room type is linked to the first active rate plan (by position) whose pricing basis fits its sell unit, normally "Basic Rate". If none fits, it stays unlinked. Changing the sell unit removes links that no longer fit and links the first plan that does. Restoring an archived room type links it again when it has no active plan.
- A room type must keep at least one active linked plan. The [rate plan guards](../08-rates/01-rate-plans.md#guards) enforce this when a plan unlinks the room type or is archived.
- A room type has no base price. Prices live only in [daily rates](../08-rates/02-daily-rates-and-restrictions.md).
- `terms_document_id` selects the registration terms for guests in this room type. Null means the property default (see [Terms resolution](../16-registration/03-terms-resolution-and-locales.md)).
- Photos use media library. No `photo_order` array columns. The `photos` collection is registered on the model; the upload UI is not built yet.
- Names are unique per property among active room types, ignoring case. An archived room type frees its name.
- `terms_document_id` has no foreign key yet. The registration item adds it with `terms_documents`.
- Archive hides a room type without deleting it. Restore puts it at the end of the list, and is refused when an active room type has the same name. The guard against future reservations arrives with reservations.
- `sell_unit` is locked once the room type has any room (see [Rooms](02-rooms-and-groups.md)).

## Permissions

`rooms.view` opens the list. `rooms.manage` creates, edits, reorders, archives and restores. The Manager default role has both.

## UI

Settings > Room types (`settings.room-types.index`) lists active room types with sell unit, guests included and capacity, with archived ones below. A right-side Sheet creates and edits. Up and down buttons reorder. The "N rate plans" link is not built yet; it needs the rate calendar. It has no price fields.

## Lessons from wastays

wastays set `sell_mode` on the hotel, could not change it after creation, and had no bed selling. It also stored amenities as jsonb arrays, and kept a `base_price` on the room type that competed with daily rates. Amenities are out of scope for the core; add a pivot table when needed.
