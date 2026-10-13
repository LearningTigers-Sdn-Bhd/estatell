# Boat settings

## Purpose

Island and jetty properties (Perhentian, Redang, Tioman, Semporna) bring guests in and out by boat. Boat settings hold the **departures** (when each boat leaves and arrives) and the **fares** (the one-way price per guest). Staff then pick a departure for each room on a booking (see [Boat trips](02-boat-trips.md)).

## Feature flag

- Pennant feature `Boats` (`App\Features\Boats`, `#[Name('boats')]`), off by default. A platform admin turns it on per property on the admin Features tab (see [Feature flags](../01-platform/03-feature-flags.md)). `PlatformFeature::Boats` lists it.
- With the feature off, the Boats settings item, the boat fields on bookings, the manifest and the meal time rule are hidden or off, and the boat routes return 404. A small `boats` middleware does this, not Pennant's `EnsureFeaturesAreActive`, whose inactive handler is global and is kept for the Channels upsell (see [Feature flags](../01-platform/03-feature-flags.md)).
- Turning the feature off keeps all boat data. Turning it on again shows it as it was.

## Data model

```
boat_departures  id, property_id, direction (BoatDirection),
                 leaves_at time, arrives_at time,
                 pickup_point text null, seats int null,
                 charge_item_id null → charge_items (the fare, restrict),
                 active (default true), timestamps
                 UNIQUE (property_id, direction, leaves_at)
                 CHECK arrives_at > leaves_at
                 CHECK seats IS NULL OR seats > 0
```

- One row is one boat that runs **every day** at that time. Seasonal closures (the east coast monsoon, November to February) use the property's closed dates or room blocks, not boat settings.
- `leaves_at` and `arrives_at` are times of day in the property time zone. A departure cannot cross midnight.
- For **Boat in**, `leaves_at` is at the jetty and `arrives_at` is at the property. For **Boat out**, `leaves_at` is at the property and `arrives_at` is at the jetty.
- A fare is a charge item. There is no fares table (see [Fares](#fares-tab)).

## Enums

| Enum                  | Cases (label)                    | Notes                                                                                   |
| --------------------- | -------------------------------- | --------------------------------------------------------------------------------------- |
| `BoatDirection`       | In ("Boat in"), Out ("Boat out") | `propertyTime(BoatDeparture)`: `arrives_at` for In, `leaves_at` for Out                 |
| `TransactionCategory` | adds Transport ("Transport")     | In `chargeItemCases()`, so also a folio routing category. Marks a charge item as a fare |

## Where it lives

- Settings sidebar, **Property** group, after Printing: **Boats** (icon `ship`). `SettingsNavigation` adds it only when the feature is on and the user has `settings.manage`.
- One page with two link tabs (`TabLinks`), each with its own URL: **Departures** and **Fares**.

## Routes

All need `settings.manage` and the `Boats` feature.

| Route                                                    | Name                                | Purpose                        |
| -------------------------------------------------------- | ----------------------------------- | ------------------------------ |
| `GET /p/{code}/settings/boats`                           | `settings.boats`                    | Redirects to Departures        |
| `GET /p/{code}/settings/boats/departures`                | `settings.boats.departures.index`   | Departures tab                 |
| `POST /p/{code}/settings/boats/departures`               | `settings.boats.departures.store`   | Add a departure                |
| `PUT /p/{code}/settings/boats/departures/{departure}`    | `settings.boats.departures.update`  | Edit, and the Available switch |
| `DELETE /p/{code}/settings/boats/departures/{departure}` | `settings.boats.departures.destroy` | Delete a departure never used  |
| `GET /p/{code}/settings/boats/fares`                     | `settings.boats.fares.index`        | Fares tab                      |
| `POST /p/{code}/settings/boats/fares`                    | `settings.boats.fares.store`        | Add a fare                     |
| `PUT /p/{code}/settings/boats/fares/{chargeItem}`        | `settings.boats.fares.update`       | Edit, and the Available switch |

`{chargeItem}` on the fare routes must be a Transport charge item of the property, or the route returns 404.

## Departures tab

```
Settings › Boats
[ Departures ]  Fares

Boat in                                                   [+ Add departure]
 Available  Leaves  Arrives  Pick-up point       Seats  Fare
 [●  ]      09:00   09:45    Kuala Besut jetty   20     Speedboat · RM35.00  ⋯
 [●  ]      13:00   13:45    Kuala Besut jetty   20     Speedboat · RM35.00  ⋯
 [  ○]      16:00   16:45    Kuala Besut jetty   —      No fare              ⋯   (muted)

Boat out                                                  [+ Add departure]
 [●  ]      08:00   08:45    Resort jetty        20     Speedboat · RM35.00  ⋯
 [●  ]      12:00   12:45    Resort jetty        —      Ferry · RM25.00      ⋯
```

- Two tables, Boat in then Boat out, each sorted by **Leaves**. There is no manual order, as with meal periods.
- Unavailable rows stay in place and are muted. There is no separate inactive list.
- Seats shows "—" for no limit. Fare shows the fare name and adult price, or "No fare".
- The **⋯** menu has **Edit** and **Delete**. Delete shows only for a departure that no boat trip has ever used.
- An empty table shows "No boat in departures yet." (or "boat out") with its Add button.

### Available switch

The first column is a `Switch` (`size="sm"`), as on the Charge items list. It sends the row with the new `active` value to the update route at once and is disabled while it saves.

| Action                        | What happens                                                                                                                        |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Turn on                       | Saves. Toast: "09:00 boat in is available."                                                                                         |
| Turn off, no upcoming trips   | Saves. Toast: "09:00 boat in is unavailable."                                                                                       |
| Turn off, with upcoming trips | An AlertDialog first: "5 upcoming bookings use the 09:00 boat in. They keep it. New bookings cannot choose it." [Cancel] [Turn off] |

Upcoming trips are Scheduled trips on the departure whose unit `SetBoatTrip` would still accept (Boat in: Confirmed; Boat out: Confirmed or CheckedIn) and whose trip date is on or after the business date. `BoatTripUsage::upcomingUnits()` is the one query. Counts are distinct reservations ("5 upcoming bookings"). A guest who has arrived keeps their arrival time. An unavailable departure leaves the booking pickers. Trips that use it keep it and it stays on the manifest.

### Add or edit departure

A Sheet (`FormOverlay`, `md`, 6 fields), opened from a section's Add button or from **Edit**.

```
Add departure                                    ×
Direction      (•) Boat in   ( ) Boat out
Leaves         [09:00]      Arrives  [09:45]
Pick-up point  [Kuala Besut jetty          ]
Seats          [20]   Leave empty for no limit
Fare           [Speedboat · RM35.00       ▾]
               No fare · Speedboat · Ferry
                              [Cancel] [Add departure]
```

- **Direction** is preset from the section whose Add button was used.
- A new departure starts available. The Available switch is only on the table, not in the Sheet.
- **Fare** lists available fares and **No fare**. With no fares: "No fares yet. Add one on the Fares tab." The Sheet cannot open the fare Sheet (one overlay level).
- When an edit changes the property-side time (**Arrives** for Boat in, **Leaves** for Boat out) on a departure with upcoming trips, a note shows above the buttons: "Updates the times on 12 upcoming bookings." ("the time on 1 upcoming booking" for one). The other time moves no booking, so it shows no note.
- The Departures page gets `upcomingBookings` per departure from one grouped query (`BoatTripUsage::upcomingBookingCounts`). The turn-off AlertDialog posts the row's values with `active` false in a `<Form>`. Turning on, or off with no upcoming bookings, stays the optimistic switch.
- A change of fare or seats applies to new trips only.

### Actions

| Action                | Does                                                                                                                                                                                                                                                                                                                                                                                                                            |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateBoatDeparture` | Adds the row. Logs `boat_departure.created`.                                                                                                                                                                                                                                                                                                                                                                                    |
| `UpdateBoatDeparture` | Saves the row. When the property-side time changes, sets `arrival_time` (In) or `departure_time` (Out) on every upcoming trip's unit to the new time, in the same transaction. Locks the departure, then the units in id order. Refuses a direction change (`departure_in_use`) while any trip uses the departure. Logs `boat_departure.updated` with the before and after values and the number of bookings and units changed. |
| `DeleteBoatDeparture` | Deletes a row with no trips. Refused (`departure_in_use`) when any trip, of any status, uses it.                                                                                                                                                                                                                                                                                                                                |

### Rules

| Rule      | Detail                                                                                                                                                                                                                                            |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unique    | One departure per direction per Leaves time. Message: "A boat in already leaves at 09:00."                                                                                                                                                        |
| Times     | Arrives after Leaves (database CHECK). Message: "Arrives must be after Leaves."                                                                                                                                                                   |
| Seats     | Empty, or a whole number above 0 (database CHECK)                                                                                                                                                                                                 |
| Fare      | An available Transport charge item of the property with **Add to every new booking** off, the departure's current fare (even when unavailable), or none. A fare with auto-add on: "This fare is added to every new booking. Turn that off first." |
| Available | A departure can be made available only while its fare is available, or it has no fare. Message: "Turn the fare Speedboat on first."                                                                                                               |
| Direction | Cannot change while any trip uses the departure (`departure_in_use`).                                                                                                                                                                             |
| Delete    | Only when never used. Otherwise turn the switch off.                                                                                                                                                                                              |

## Fares tab

```
Settings › Boats
 Departures  [ Fares ]

Fares                                                               [+ Add fare]
 Available  Name       Adult   Children                      Taxes   Used by
 [●  ]      Speedboat  35.00   Child 4–11 50% · Infant Free  SST 8%  4 departures  ⋯
 [●  ]      Ferry      25.00   Same as adult                 None    1 departure   ⋯
 [  ○]      Old ferry  20.00   Same as adult                 None    —             ⋯  (muted)
```

- The list is every Transport charge item of the property, in the charge item order. Unavailable fares stay in place and are muted.
- **Children** reads "Same as adult" when every age group is Percent 100.
- **Used by** counts available departures that use the fare.
- The **⋯** menu has **Edit** (the fare Sheet) and **More settings**, which opens the [charge item page](../06-catalogues/02-charge-items.md#pages) for the description and other fields.
- Empty state: "No fares yet. A fare is the one-way price per guest for a boat." with the Add button.

### Available switch

Same control as on Departures. It sends the fare with the new `active` value to the fare update route, which calls `UpdateChargeItem`, so the fare's transaction code follows as for any charge item.

| Action                                   | What happens                                                                                                                                                                     |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Turn on                                  | Saves. The item moves to the end of the charge item list (the existing reactivation rule).                                                                                       |
| Turn off, no available departure uses it | Saves                                                                                                                                                                            |
| An available departure uses it           | The switch is disabled. Tooltip: "Used by 2 available departures. Change their fare first." `UpdateBoatFare` also refuses it (`fare_in_use`), and the page shows an error toast. |

### Add or edit fare

A Sheet (`FormOverlay`, `lg`). It has four short sections and fixed age group rows, which the [form container rules](../design-system/05-form-containers.md#always-a-full-page-whatever-the-count) allow in a Sheet.

```
Edit fare · Speedboat                                       ×
Details
 Name        [Speedboat           ]
 Code        SPEEDBOAT              (typed on create, then read only)

Price · one way, per guest
 Adult       RM [35.00]
 [ ] Allow another amount when charging

Children
 Child 4–11   [Percent ▾] [50] %     RM17.50
 Infant 0–3   [Free    ▾]

Taxes
 [SST 8% ×]                          ▾
 Prices are before tax.

 New prices apply to new boat trips. Booked trips keep their price.
                                        [Cancel] [Save fare]
```

- **Code** is typed on create only, suggested from the name, as in the New charge item Dialog. After that the Sheet shows it read only and sends it unchanged. Renaming the fare renames the code's name, not the code (the charge item rule). The code can change on the charge item page (**More settings**).
- **Adult** is required and above 0. A fare always has a fixed price, never Enter when charging.
- **Children** reuses the rate plan and charge item Children control (Free, Percent, Fixed per age group).
- **Taxes** is the charge item taxes multiselect.
- Fixed and not shown: charged Per guest, category Transport, Add to every new booking off.
- Booked trips keep their price because an add-on copies `unit_amount` when it is added ([Add-ons](../12-reservations/04-add-ons.md#price)).
- The Sheet asks before it closes with unsaved changes.

### Actions

| Action           | Does                                                                                                                                                       |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateBoatFare` | Calls `CreateChargeItem` (category Transport) and then `UpdateChargeItem` (PerGuest, the price, child prices, taxes, `auto_add` false) in one transaction. |
| `UpdateBoatFare` | Calls `UpdateChargeItem` with the Sheet values and keeps PerGuest, Transport and `auto_add` false.                                                         |

Boats never write `charge_items` directly. Both Actions live in `app/Actions/Boats/`.

### On the Charge items page

- Fares also show on the Charge items list, with category Transport.
- On the edit page of a charge item that any departure uses (available or not), **Add to every new booking** is disabled with "Added by boat trips", so a fare is never charged twice. `ChargeItemRequest` also refuses `auto_add` on for such an item.
- The Charge items list cannot turn off a fare that an available departure uses either. `ChargeItemRequest` refuses `active` off with "This fare is used by an available departure. Change their fare first.", shown as an error toast.
- Changing a fare's charging unit there is allowed but not useful. The Fares tab shows the charging unit in muted text when it is not Per guest.

## Build order

- `add boat departures`: one Departures page with no tabs. Nothing uses a departure yet, so Update saves the row only and Delete always works.
- `add boat fares`: the Fares tab and the `TabLinks`.
- `add boat trips and set them on units`: the upcoming trip query, moving unit times on Update, the direction lock and the `departure_in_use` refusal on Delete.
- `show boat trips on the reservation page`: the turn-off AlertDialog with the upcoming booking count and the edit note.

## Permissions

`settings.manage` for both tabs. No new permission.

## Lessons from wastays

wastays kept boat times in `hotel_boat_schedules` with `has_breakfast`, `has_lunch`, `has_dinner` and `has_hi_tea` booleans, and meal times in `hotel_boat_settings`. There was no fare, no seat limit and no pick-up point. estatell keeps the departure list, drops the meal booleans (meals follow the times, see [Boat trips](02-boat-trips.md#meals)) and adds fares as charge items.
