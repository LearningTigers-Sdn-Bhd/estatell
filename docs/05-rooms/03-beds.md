# Beds

## Purpose

Beds are the sellable units of a Bed room type (dorms).

## Data model

```
beds    id, room_id, label, position, archived_at, timestamps
        unique (room_id, lower(label)) where archived_at is null
        check: label is trimmed and not empty
```

There is no `property_id`. A bed reaches its property through its room, so the property is stored once. Every query goes through `$property->beds()` or a room of the property.

## Rules

- Beds exist only in rooms whose room type has `sell_unit = Bed`. A new bed in an archived room, or in a room of a Room type, is refused.
- Labels are free text (max 30). They are unique per room among active beds, ignoring case. An archived bed frees its label. Two rooms may use the same label.
- Archive beds instead of deleting them, because bookings reference them. Restore is refused when an active bed in the room has the same label, or when the room is archived.
- Archiving a room leaves its beds as they are.
- A room that has beds (active or archived) cannot move to a room type that sells rooms. It may move to another Bed room type. A room without beds can change type freely.
- A booked bed is stored on `unit_nights.bed_id` (see [Reservation shape](../12-reservations/01-reservation-shape.md)).
- Availability for a Bed room type counts active beds (see [Availability](../10-availability/01-availability-service.md)).

## Adding beds by count

`GenerateBeds` adds beds to one room or many. `BedLabelGenerator` (a Service) builds the labels and never writes.

- The staff member enters a **count** (1 to 50). There is no stored capacity.
- An optional **prefix** (max 10) replaces the room letter: prefix "Bunk" gives Bunk1, Bunk2. Empty means the room letter. The bulk header action has no prefix.
- Each room gets **one letter**, by its place among the property's active Bed rooms in room order: the first is A (A1, A2 ...), the second B, and so on. After Z the letters continue as AA, AB. A room keeps its letter whichever rooms are picked, but archiving an earlier dorm room shifts the letters of later rooms in future runs. Existing labels never change.
- Numbers **continue** after the highest active label that is the prefix plus digits (A4 gives A5, A6). Renamed labels such as "Top bunk" are ignored, so adding never clashes.
- Picked rooms must be active and sell beds. Without a pick, the run covers every active Bed room that has no active bed.
- **Manual** adds one bed with a typed label (`CreateBed`).
- **Onboarding** calls `GenerateBeds` on save of the [Rooms table](../17-onboarding/02-wizard-steps.md#rooms-table), with the tile's Beds per room count and no prefix, for every room of a bed-sold type that has no active bed.

## Actions

`CreateBed`, `UpdateBed`, `ArchiveBed`, `RestoreBed`, `GenerateBeds`.

## Permissions

`rooms.view` opens the rooms page (which lists beds). `rooms.manage` adds, renames, archives, restores and generates beds.

## UI

On Settings > Rooms, a room of a Bed type shows its active bed count and a Beds button. The button opens a sheet with the bed list (rename, archive), an Add beds panel and the archived beds (restore). The panel is a radio choice: **Generate** (how many, and a prefix that defaults to the room letter) or **Manual** (add 1 bed and type its label). The header has Add beds, which applies one count to every dorm room without beds.
