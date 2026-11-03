# House status and housekeeping items

## Purpose

Two front desk reports for housekeeping, built from Sand Bay's spreadsheets: **Housekeeping items** (the extra beds and other set-up items of each room on a date) and **House status** (every room of the date by boat time, with pax, room label and flags). Both are in the core report frame, so they have quick dates, Layout, PDF, CSV and Excel.

## Housekeeping items

| Report             | `ReportKey`         | Source                                        | Dates    | Orientation | Category   | Icon        |
| ------------------ | ------------------- | --------------------------------------------- | -------- | ----------- | ---------- | ----------- |
| Housekeeping items | `HousekeepingItems` | `HousekeepingItemsReport` over `unit_add_ons` | One date | Portrait    | Front desk | `spray-can` |

- **Which items:** charge items with **Show to housekeeping** (`charge_items.housekeeping_item`, default off), a switch in the New bookings section of the charge item page. Sand Bay ticks Extra bed, Baby cot and Cake.
- **Which rooms:** overnight units of a status that holds a room (Confirmed, Checked in, Checked out) whose stay covers the date, arrival to departure day.
- **Nights in the room:** an undated or one-off item (PerStay, PerItem, PerGuest) is in the room every night of the stay. A nightly item (PerNight, PerGuestNight) is in the room on the nights of its rows.
- **Action** on the date: **Set up** (a night with the item, the night before without), **In room** (both nights with it), **Remove** (the night before with it, the date without). A date with neither shows no row.
- **Columns:** Room (the room of the date, else of the last night, else the room type), Guest (the lead guest, else the booker), Pax, Item, Qty, Action, Stay. Default sort: Room. The total row sums Qty.
- **Row key:** `{unit id}:{charge item id}`.
- Quick dates: Today, Tomorrow. Permission: `reports.view`.

## House status

| Report       | `ReportKey`   | Source                                                              | Dates    | Orientation | Category   | Icon          |
| ------------ | ------------- | ------------------------------------------------------------------- | -------- | ----------- | ---------- | ------------- |
| House status | `HouseStatus` | `HouseStatusReport` over `reservation_units`, `unit_nights`, blocks | One date | Landscape   | Front desk | `layout-grid` |

Every room housekeeping needs on the date, one tile per room, in groups. The rows keep their order (`hasFixedOrder`), so the headers do not sort.

| Group (in this order)                                           | Rooms in it                                                                                            | Colour (PDF)      |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ----------------- |
| Boat out · 11:30, Boat in · 12:30, … (by time, both directions) | Units leaving (departure day) or arriving (arrival day) on that scheduled boat                         | Red out, black in |
| Charter · in / out, Own boat · in / out                         | Units whose trip in that direction is Charter or Own                                                   | Red out, black in |
| Boat not set                                                    | Units leaving or arriving with no trip in that direction                                               | Amber             |
| Room move                                                       | Stay-over units whose room on the date is not the room of the night before: the old room, then the new | Red, then black   |
| OCC (stay-over)                                                 | The other stay-over units                                                                              | Blue              |
| Management                                                      | Rooms blocked for Owner use on the date (`room_blocks`, reason `owner_use`)                            | Purple            |

- Without the Boats feature, leaving and arriving rooms group by their expected time instead ("Out · 11:00", "In · 15:00").
- A unit already checked out prints its room struck through, as on Sand Bay's sheet.
- **Tile:** pax ("X2", adults + children), the room label, the room number, then flags: the housekeeping items in the room (upper case, from [Housekeeping items](#housekeeping-items), not those to remove), **TL** when a guest of the unit has the tag "TL" or "Tour leader", and the room's housekeeping remark (`room_statuses.remark`; a Management block shows its notes).
- **Room label:** `rooms.print_label`, else `room_types.print_label`, else the initials of the room type name ("Standard Twin" gives ST). Both are optional, at most 12 characters, saved in capitals: the room type's on its details form, the room's on the room form ("Print label (optional)") for the rooms set up differently, such as D01 "1KING+TWIN" among QUADRUPLE rooms.
- **Subtotal per group:** "12 pax · 4 rooms". A room move counts its new room only.
- **Columns** (screen and spreadsheet): Group, Room, Type, Pax, Flags, Remark.
- **PDF:** `HouseStatusReport` implements `HasPrintView`, so `ReportPdf` prints `pdf/house-status.blade.php` instead of the shared table: a "HOUSE STATUS" banner and "Working date: 20/10/2026", one line per group (the group name in its colour, up to 10 tiles a line, the pax and rooms on the right), then the **Extra amenities / remark** table (Room no, Description, Qty, an empty Assign (runner) column) from the housekeeping items, and "Assigned by" with the user who printed it.
- Row key: `{kind}:{unit id}` or `management:{block id}`. Quick dates: Today, Tomorrow. Permission: `reports.view`.
