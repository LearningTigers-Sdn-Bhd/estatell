# Boat settings

## Purpose

Island and jetty properties (Perhentian, Redang, Tioman, Semporna) bring guests in and out by boat. Boat settings hold the **departures** (the timetable: when each boat leaves and arrives), the **fares** (the one-way price per guest, and the charter charge), the **boats** and the **crew**. Staff then pick a departure for each room on a booking (see [Boat trips](02-boat-trips.md)), and run each date on the [Boat Trips board](05-boats-board.md).

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
                 default_boat_id null → boats (null on delete),
                 active (default true), timestamps
                 UNIQUE (property_id, direction, leaves_at)
                 CHECK arrives_at > leaves_at
                 CHECK seats IS NULL OR seats > 0

boat_departure_crew  id, boat_departure_id → boat_departures (cascade),
                     boat_crew_member_id → boat_crew_members (restrict),
                     in_charge bool (default false), timestamps
                     UNIQUE (boat_departure_id, boat_crew_member_id)
                     UNIQUE (boat_departure_id) WHERE in_charge
```

- One row is the **timetable**: a departure that runs every day at that time. A single date can differ (a second boat, another time, a cancelled trip, an extra trip). That lives in [sailings](04-sailings.md), changed on the [Boat Trips board](05-boats-board.md), never on the timetable. Seasonal closures (the east coast monsoon, November to February) use the property's closed dates or room blocks, not boat settings.
- **Default boat and crew** prefill each date's first sailing. The crew is a list of [crew members](#crew-tab) with one **in charge** (the skipper). `boat_departure_crew` replaces the old free-text `default_crew` column (see [Migration](04-sailings.md#migration)).
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
- One page with four link tabs (`TabLinks`), each with its own URL: **Departures**, **Fares**, **Boats** and **Crew**.

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
| `PUT /p/{code}/settings/boats/charter`                   | `settings.boats.charter`            | Charter charge (Fares tab)     |
| `GET /p/{code}/settings/boats/boats`                     | `settings.boats.boats.index`        | Boat list tab                  |
| `POST /p/{code}/settings/boats/boats`                    | `settings.boats.boats.store`        | Add a boat                     |
| `PUT /p/{code}/settings/boats/boats/{boat}`              | `settings.boats.boats.update`       | Edit, and the Available switch |
| `GET /p/{code}/settings/boats/crew`                      | `settings.boats.crew.index`         | Crew tab                       |
| `POST /p/{code}/settings/boats/crew`                     | `settings.boats.crew.store`         | Add a crew member              |
| `PUT /p/{code}/settings/boats/crew/{crewMember}`         | `settings.boats.crew.update`        | Edit, and the Available switch |

`{chargeItem}` on the fare routes must be a Transport charge item of the property, or the route returns 404. `{boat}` and `{crewMember}` of another property are a 404.

## Departures tab

```
Settings › Boats
[ Departures ]  Fares  Boats  Crew

Boat in                                                                                                            [+ Add departure]
 Available  Leaves  Arrives  Pick-up point       Seats  Default boat & crew             Fare                 Meals ⓘ
 [●  ]      09:00   09:45    Kuala Besut jetty   20     MV Sand Bay 1 · Awang (in charge), Daus Speedboat · RM35.00  Breakfast · Lunch · Dinner  ⋯
 [●  ]      13:00   13:45    Kuala Besut jetty   20     MV Sand Bay 2 · Din (in charge), Razak   Speedboat · RM35.00  Lunch · Dinner              ⋯
 [  ○]      16:00   16:45    Kuala Besut jetty   —      —                               No fare              Dinner                      ⋯   (muted)

Boat out                                                                                                           [+ Add departure]
 [●  ]      08:00   08:45    Resort jetty        20     MV Sand Bay 1 · Awang (in charge), Daus Speedboat · RM35.00  Breakfast                   ⋯
 [●  ]      12:00   12:45    Resort jetty        —      MV Sea Eagle                    Ferry · RM25.00      Breakfast                   ⋯
```

- **Default boat & crew** shows the boat, then the crew member in charge with "(in charge)", then the assistants: "MV Sand Bay 1 · Awang (in charge), Daus". "—" when none is set.

- Two tables, Boat in then Boat out, each sorted by **Leaves**. There is no manual order, as with meal periods.
- Unavailable rows stay in place and are muted. There is no separate inactive list.
- Seats shows "—" for no limit. Fare shows the fare name and adult price, or "No fare".
- The **⋯** menu has **Edit** and **Delete**. Delete shows only for a departure that no boat trip has ever used.
- An empty direction keeps its table header and shows "No boat in departures yet." (or "boat out") in one row. The Add departure button is always in the section header.

### Meals column

Shows which meals a guest on the boat can get on the arrival or departure day. It is read only. It replaces the wastays meal checkboxes: the meal times decide, so there is nothing to tick and nothing to drift (see [Lessons from wastays](#lessons-from-wastays)).

| Direction | Time used    | A meal shows when                           |
| --------- | ------------ | ------------------------------------------- |
| Boat in   | `arrives_at` | `arrives_at` is before the meal's `ends_at` |
| Boat out  | `leaves_at`  | `leaves_at` is after the meal's `starts_at` |

- This is the same rule as the kitchen counts ([Boat trips](02-boat-trips.md#meals)). Both call one method, so the column and the counts never disagree.
- The cell lists the active meal periods that match, in start time order, as outline `Badge`s with no icon and no tone, so they read as information, not as checkboxes. With no match or no active meal periods, it shows "—".
- Example with the default times (Breakfast 07:00–10:00, Lunch 12:00–14:30, Dinner 19:00–22:00): a boat in that arrives 12:30 shows Lunch · Dinner. A boat out that leaves 12:00 shows Breakfast only, because lunch starts at 12:00.
- A guest gets only the meals their rate plan includes. The column shows what the boat time allows, not what a guest has paid for.
- Charter and own-boat trips are not departures, so they are not listed. They follow the same rule from the unit's time.

**Header popover.** The header reads **Meals** with an info icon button (`aria-label` "Meal times"). It opens a `Popover`, not a `HoverCard`, so it also works on a tap:

```
Meal times
Breakfast   07:00–10:00
Lunch       12:00–14:30
Dinner      19:00–22:00

Boat in: meals that end after the boat arrives.
Boat out: meals that start before the boat leaves.
Edit meal periods ↗
```

- It lists the active meal periods in start time order. With none: "No meal periods yet." and the same link.
- **Edit meal periods** opens `settings.meal-periods` in a new tab (a plain `<a target="_blank">` with an external link icon), so the departures stay open. A wrong result is fixed there, not on the boat.

**Data.** The Departures page gets `mealPeriods` (the active periods: name, `startsAt`, `endsAt`) and a `meals` list of period names on each departure. `MealTimeRule` (`app/Services/Meals/`) holds the two checks, `countsOnArrival(arrivalTime, endsAt)` and `countsOnDeparture(departureTime, startsAt)`, and `MealEntitlementResolver` calls it instead of its own compare. `DepartureMeals` (`app/Services/Boats/`) uses `MealTimeRule` with `BoatDirection::propertyTime()` to build each departure's list.

### Available switch

The first column is a `Switch` (`size="sm"`), as on the Charge items list. It sends the row with the new `active` value to the update route at once and is disabled while it saves.

| Action                        | What happens                                                                                                                        |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Turn on                       | Saves. Toast: "09:00 boat in is available."                                                                                         |
| Turn off, no upcoming trips   | Saves. Toast: "09:00 boat in is unavailable."                                                                                       |
| Turn off, with upcoming trips | An AlertDialog first: "5 upcoming bookings use the 09:00 boat in. They keep it. New bookings cannot choose it." [Cancel] [Turn off] |

Upcoming trips are Scheduled trips on the departure whose unit `SetBoatTrip` would still accept (Boat in: Confirmed; Boat out: Confirmed or CheckedIn) and whose trip date is on or after the business date. `BoatTripUsage::upcomingUnits()` is the one query. Counts are distinct reservations ("5 upcoming bookings"). A guest who has arrived keeps their arrival time. An unavailable departure leaves the booking pickers. Trips that use it keep it and it stays on the manifest.

### Add or edit departure

A Sheet (`FormOverlay`, `md`, 9 fields), opened from a section's Add button or from **Edit**.

```
Add departure                                    ×
Direction      (•) Boat in   ( ) Boat out
Leaves         [09:00]      Arrives  [09:45]
               Meals: [Breakfast] [Lunch] [Dinner]
Pick-up point  [Kuala Besut jetty          ]
Seats          [20]   Leave empty for no limit
Fare           [Speedboat · RM35.00       ▾]
               No fare · Speedboat · Ferry
Default boat   [MV Sand Bay 1             ▾]
In charge      [Awang                     ▾]
Assistants     [Daus ×]                    ▾
               Prefill each date on the Boat Trips board.
                              [Cancel] [Add departure]
```

- **Default boat** lists available boats and **None**. **In charge** and **Assistants** list available crew members. The current values show even when they are now unavailable, so an edit does not drop them.
- Each crew option shows the crew member's phone as a muted second line under the name, when one is saved. The Boat Trips board's crew pickers do the same.
- **Assistants** is a multiselect (`Combobox` with chips) and leaves out the person in charge. Assistants without anyone in charge are refused: "Pick who is in charge first."
- With no boats or no crew yet, the select says "No boats yet. Add one on the Boat list tab." (or "Crew tab").

- **Direction** is preset from the section whose Add button was used.
- A new departure starts available. The Available switch is only on the table, not in the Sheet.
- **Fare** lists available fares and **No fare**. With no fares: "No fares yet. Add one on the Fares tab." The Sheet cannot open the fare Sheet (one overlay level).
- When an edit changes the property-side time (**Arrives** for Boat in, **Leaves** for Boat out) on a departure with upcoming trips, a note shows above the buttons: "Updates the times on 12 upcoming bookings." ("the time on 1 upcoming booking" for one). The other time moves no booking, so it shows no note.
- The Departures page gets `upcomingBookings` per departure from one grouped query (`BoatTripUsage::upcomingBookingCounts`). The turn-off AlertDialog posts the row's values with `active` false in a `<Form>`. Turning on, or off with no upcoming bookings, stays the optimistic switch.
- A change of fare or seats applies to new trips only.
- **Meals preview.** A muted line under the times shows the meals for the current Direction and property-side time as outline badges, like the column, and updates as staff type. It uses the [Meals column](#meals-column) rule in the browser with the page's `mealPeriods` (`departureMeals()` in `resources/js/lib/departure-meals.ts`). It is a preview only: after saving, the column shows the server's list. With no active meal periods: "No meal periods yet." With no match: "Meals: none". With no valid time yet, the line is hidden.

### Actions

| Action                | Does                                                                                                                                                                                                                                                                                                                                                                                                                            |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateBoatDeparture` | Adds the row. Logs `boat_departure.created`.                                                                                                                                                                                                                                                                                                                                                                                    |
| `UpdateBoatDeparture` | Saves the row. When the property-side time changes, sets `arrival_time` (In) or `departure_time` (Out) on every upcoming trip's unit to the new time, in the same transaction. Locks the departure, then the units in id order. Refuses a direction change (`departure_in_use`) while any trip uses the departure. Logs `boat_departure.updated` with the before and after values and the number of bookings and units changed. |
| `DeleteBoatDeparture` | Deletes a row with no trips and no sailings. Refused (`departure_in_use`) when any trip, of any status, uses it, and (`departure_has_sailings`) when any date has a stored [sailing](04-sailings.md).                                                                                                                                                                                                                           |

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
 Departures  [ Fares ]  Boats  Crew

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
- Empty state: the table keeps its header and shows "No fares yet. A fare is the one-way price per guest for a boat." in one row. The Add fare button is always in the section header.

### Charter

A section below the fares table. It moved here from the Boat list tab, because it is a price, not a boat.

```
Charter
 Charge for a charter   [Charter Boat · RM450.00 ▾]  [Save]
 Picking Charter as a room's boat adds this charge once.
```

- **Charge for a charter** (`property_settings.charter_charge_item_id`) lists the property's active charge items with a fixed price, and "Nothing (charge by hand)".
- `SetCharterChargeItem` saves it (`settings.boats.charter`) and logs `boats.charter_item_set`. How the trip adds it: [Charter charge](02-boat-trips.md#fare).

### Available switch

Same control as on Departures. It sends the fare with the new `active` value to the fare update route, which calls `UpdateChargeItem`, so the fare's transaction code follows as for any charge item. The fare Sheet leaves `active` out, and the update route then keeps the fare's Available state.

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

## Boat list tab

`BoatController` (`settings.manage`): the boats the property runs, picked for each [sailing](04-sailings.md) on the Boat Trips board.

```
boats    id, property_id, name (≤ 60, unique per property ignoring case), registration (nullable, ≤ 30),
         seats int null, active (default true), timestamps
         CHECK seats IS NULL OR seats > 0
```

```
Settings › Boats
 Departures  Fares  [ Boat list ]  Crew

Boats                                                         [+ Add boat]
 Available  Name            Registration   Seats
 [●  ]      MV Sand Bay 1   SA10716        20      ✎
 [●  ]      MV Sand Bay 2   SA10822        12      ✎
 [  ○]      MV Old Ferry    SA09001        —       ✎   (muted)
```

- A data table sorted by name. Unavailable rows stay in place and are muted.
- The first column is the **Available** switch, as on Departures. It sends the row with the new `active` value at once. Toast: "MV Sand Bay 2 is available." or "… is unavailable." An unavailable boat leaves the pickers; sailings that already use it keep it and still show its name.
- **Seats** is the boat's own limit. It counts on the Boat Trips board when a departure runs more than one boat ([Seats](04-sailings.md#seats)). "—" for no limit.
- **Add boat** and ✎ open a Dialog (`FormOverlay`, `sm`): Name, Registration (optional), Seats (optional). A new boat starts available. The switch is only on the table.
- `SaveBoat` adds or changes a boat and logs `boat.created` or `boat.updated`. A boat is never deleted; turn it off instead.
- Empty state: "No boats yet. Add the boats you run, so the Boat Trips board can name the boat of each sailing."

## Crew tab

`BoatCrewController` (`settings.manage`): the people who run the boats. Crew members are names, not staff users: they do not sign in.

```
boat_crew_members   id, property_id, name (≤ 60, unique per property ignoring case),
                    phone (nullable, ≤ 30), active (default true), timestamps
```

```
Settings › Boats
 Departures  Fares  Boats  [ Crew ]

Crew                                                          [+ Add crew]
 Available  Name     Phone
 [●  ]      Awang    012-345 6789   ✎
 [●  ]      Daus     —              ✎
 [●  ]      Din      013-222 1111   ✎
 [  ○]      Razak    —              ✎   (muted)
```

- The same table, switch and Dialog as the Boat list tab: Name and Phone (optional). Toast: "Din is available." or "… is unavailable."
- Phone uses the shared phone field with a country picker. A number without a country code is read as a number of the property's country and saved in E.164 form (`+60132221111`).
- On both tabs, an empty list keeps the table header and shows the empty state in the table.
- Who is **in charge** is set per departure and per sailing, not on the crew member, because the skipper of one trip can be the assistant on another.
- `SaveBoatCrewMember` adds or changes a crew member and logs `boat_crew_member.created` or `boat_crew_member.updated`. A crew member is never deleted; turn them off instead, so old sailings keep the name.
- Empty state: "No crew yet. Add the people who run your boats, then pick who is in charge of each trip."

## Build order

- `add boat departures`: one Departures page with no tabs. Nothing uses a departure yet, so Update saves the row only and Delete always works.
- `add boat fares`: the Fares tab and the `TabLinks`.
- `add boat trips and set them on units`: the upcoming trip query, moving unit times on Update, the direction lock and the `departure_in_use` refusal on Delete.
- `show boat trips on the reservation page`: the turn-off AlertDialog with the upcoming booking count and the edit note.
- `show meals on boat departures`: `MealTimeRule`, `DepartureMeals`, the Meals column with its header popover, and the meals preview in the Sheet.
- `add the crew list`: the Crew tab, `boat_departure_crew`, the In charge and Assistants fields, the Available switch and Seats on the Boats tab, and the Charter section moved to the Fares tab.

## Permissions

`settings.manage` for every tab. No new permission.

## Lessons from wastays

wastays kept boat times in `hotel_boat_schedules` with `has_breakfast`, `has_lunch`, `has_dinner` and `has_hi_tea` booleans, and meal times in `hotel_boat_settings`. There was no fare, no seat limit and no pick-up point. estatell keeps the departure list, drops the meal booleans (meals follow the times, see [Boat trips](02-boat-trips.md#meals)) and adds fares as charge items.

The booleans were pre-ticked from the meal start times, then kept apart from them. At Sand Bay, the 12:30 boat in needed Lunch ticked by hand, because 12:30 is after lunch starts. Changing a meal time never updated the slots. estatell compares the arrival with the meal's end time, which gives Sand Bay's six slots the same meals with no ticks (with a Hi-Tea period such as 16:00–17:30). The read-only [Meals column](#meals-column) shows the result per departure instead of checkboxes. There is no per-departure exception: a wrong result means the meal period times are wrong.
