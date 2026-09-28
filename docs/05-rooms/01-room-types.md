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
- A new room type is linked to "Basic Rate" when the sell unit fits. It must keep at least one active linked plan.
- A room type has no base price. Prices live only in [daily rates](../08-rates/02-daily-rates-and-restrictions.md).
- `terms_document_id` selects the registration terms for guests in this room type. Null means the property default (see [Terms resolution](../16-registration/03-terms-resolution-and-locales.md)).
- Photos use media library. No `photo_order` array columns.

## UI

The Rooms screen lists room types with sell unit, guests included, capacity and inventory. "N rate plans" opens the Rates calendar filtered to that room type. It has no price fields.

## Lessons from wastays

wastays set `sell_mode` on the hotel, could not change it after creation, and had no bed selling. It also stored amenities as jsonb arrays, and kept a `base_price` on the room type that competed with daily rates. Amenities are out of scope for the core; add a pivot table when needed.
