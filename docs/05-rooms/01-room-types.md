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
- A Bed room type accepts only PerBed rate plans (see [Rate plans](../08-rates/01-rate-plans.md)).
- `terms_document_id` selects the registration terms for guests in this room type. Null means the property default (see [Terms resolution](../16-registration/03-terms-resolution-and-locales.md)).
- Photos use media library. No `photo_order` array columns.

## Lessons from wastays

wastays set `sell_mode` on the hotel, could not change it after creation, and had no bed selling. It also stored amenities as jsonb arrays. Amenities are out of scope for the core; add a pivot table when needed.
