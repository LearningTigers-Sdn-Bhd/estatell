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
- `terms_document_id` has a foreign key to `terms_documents` (null on delete). The room type form has a **Terms document** Select, shown when the property has a second document. See [Terms documents](../16-registration/02-terms-documents-and-presets.md#ui).
- Archive hides a room type without deleting it. Restore puts it at the end of the list, and is refused when an active room type has the same name. The guard against future reservations arrives with reservations.
- `sell_unit` is locked once the room type has any room (see [Rooms](02-rooms-and-groups.md)).

## Permissions

`rooms.view` opens the list and the room type page (read-only). `rooms.manage` creates, edits, reorders, archives, restores and assigns rooms. The Manager default role has both.

## UI

Settings > Room types (`settings.room-types.index`) lists active room types with sell unit, guests included and capacity, with archived ones below. Up and down buttons reorder. The name opens the room type page. **New room type** opens a right-side Sheet; after create, the new room type's Details tab opens. The list has no edit Sheet. The "N rate plans" link is not built yet; it needs the rate calendar. It has no price fields.

### Room type page

The page copies the rate plan editor: a shell with a header and tab links. Each tab is its own URL and loads only its own data.

| Tab     | Route                            | Page                                  |
| ------- | -------------------------------- | ------------------------------------- |
| Details | `settings.room-types.edit`       | `pages/settings/room-types/edit.tsx`  |
| Rooms   | `settings.room-types.rooms.edit` | `pages/settings/room-types/rooms.tsx` |

```
Settings › Room types › Deluxe King
Deluxe King                                      [Archive]
[ Details ] [ Rooms (12) ]
```

- The header shows **Archive**, or **Restore** for an archived room type. An archived room type opens read-only.
- Staff with only `rooms.view` see both tabs read-only: fields disabled, no Save and no header buttons.

### Details tab

The former Sheet form, in one `SettingsSection`: Name, Description, Sold by, Guests included, Max adults, Max children, Smoking, Pets and Terms document. The fields and rules do not change. `PUT settings.room-types.update` saves and returns to this tab.

### Rooms tab

```
Rooms                                [Generate rooms] [+ Add rooms ⌄]
Rooms of this type and their group.
─────────────────────────────────────────────────────────────────────
Room    Group
101     [Floor 1      ⌄]
201     [Ungrouped    ⌄]
305     [Floor 3      ⌄]   From Standard Twin            [Undo]
                                                            [Save]
```

- One `SettingsSection` with `guardUnsavedChanges`. **Generate rooms** and **+ Add rooms** sit in the section header (the `actions` slot, which can be a function of the form state so Generate rooms can follow `isDirty`). On a phone they wrap below the description.
- Each active room of this type has a Group select: any group of the property, or Ungrouped.
- **+ Add rooms** is a searchable list of active rooms in other types, shown as "305 · Standard Twin". A picked room joins the list marked "From Standard Twin", with **Undo**. Nothing moves until **Save**.
- There is no Remove. A room always has a type, so it leaves this type only when it is added on another type's page.
- **Generate rooms** opens `GenerateRoomsSheet` with this room type fixed and its select hidden. It is disabled while the form has unsaved changes, with the tooltip "Save or discard your changes first." Rooms are created without beds; **Generate beds** stays on Settings > Rooms.
- An empty type shows "No rooms yet. Generate or add rooms." with no buttons of its own.

The Action returns a refusal message for rules 2, 4, 5 and 6, and the page shows it as an error toast; the Form Request covers rule 1 only. Archive and Restore on the room type page return to the room types list.

`PUT settings.room-types.rooms.update` sends `[{room_id, room_group_id}]` to `AssignRoomTypeRooms`. It saves all rows in one transaction, or none:

| #   | Rule                                                                                                                                                                                                  | Message                                                       |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| 1   | Each room is active and in this property. Each group is in this property, or null.                                                                                                                    | Validation                                                    |
| 2   | Every room now in this type is still in the list.                                                                                                                                                     | "Rooms can't be removed here. Add them on another room type." |
| 3   | A room from another type moves here (`room_type_id` changes).                                                                                                                                         | –                                                             |
| 4   | A room with beds cannot move into a type that sells rooms.                                                                                                                                            | "305 has beds. Choose a room type that sells beds."           |
| 5   | A room cannot change type while it, or one of its beds, has assigned unit nights, or an unreleased day use, on or after the business date. See [Rooms](02-rooms-and-groups.md#changing-a-rooms-type). | "305 has stays assigned from 20 Oct. Move them first."        |
| 6   | An archived room type is refused.                                                                                                                                                                     | ":name is archived. Restore it before editing."               |
| 7   | Rooms are locked; only rooms that differ change; each changed room logs one `room.updated` activity with the changed fields.                                                                          | Toast "Rooms saved."                                          |

## Lessons from wastays

wastays set `sell_mode` on the hotel, could not change it after creation, and had no bed selling. It also stored amenities as jsonb arrays, and kept a `base_price` on the room type that competed with daily rates. Amenities are out of scope for the core; add a pivot table when needed.
