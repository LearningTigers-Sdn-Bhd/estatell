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
