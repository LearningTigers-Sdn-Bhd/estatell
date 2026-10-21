# Daily rates and restrictions

## Purpose

The price and selling restrictions for each room type, rate plan and date.

## Data model

```
rates                     id, room_type_id, rate_plan_id, date, amount (nullable),
                          min_stay, max_stay, closed_to_arrival, closed_to_departure, stop_sell,
                          timestamps
                          unique (room_type_id, rate_plan_id, date)
rate_occupancy_prices     id, rate_id, adults, amount
                          unique (rate_id, adults)
```

## Meaning of `amount`

| Pricing basis | `amount` means                                                    |
| ------------- | ----------------------------------------------------------------- |
| PerRoom       | Room price for the room type's `base_occupancy`                   |
| PerGuest      | Price for one adult                                               |
| PerBed        | Price per bed                                                     |
| Package       | Null. Rows carry restrictions only.                               |
| DayUse        | Null. Prices are in `rate_hour_prices`; the row carries Stop sell |

## Rules

- Rows exist only for a plan and room type pair linked in `rate_plan_room_types` (see [Rate plans](01-rate-plans.md)).
- A derived plan has no own `amount`. Its price follows the parent per date (see [Derived rate plans](04-derived-rate-plans.md)).
- `rate_occupancy_prices` is optional: an explicit price per adult count.
    - PerRoom: the room price for that many adults.
    - PerGuest: the total price for that many adults (for example 1 adult RM400, 2 adults RM600).
- The editor can fill occupancy prices with **Auto**. Auto follows the plan's pricing basis. It only fills the fields, and its inputs are not stored.
    - PerRoom: pick a base adult count, then a step up and a step down (amount or %). The base defaults to the room type's `base_occupancy`, which starts from the room price.
    - PerGuest: starts from the 1 adult price, which is per person. "Per person, each extra adult" is Same price, Less or More, by an amount or %. The per person price moves one step for each adult after the first, and each row gets the room total. Example, 1 adult RM475, Less RM25: 2 adults = 2 × 450 = 900, 3 adults = 3 × 425 = 1,275.
    - PerBed has one price row, so it has no Auto.
- On a PerGuest plan, each adult-count price shows its share per person under the field ("475.00 each"). It is display only. The field stays a room total, so a per person price typed there shows up as half.
- Restrictions (`stop_sell`, `closed_to_arrival`, `closed_to_departure`, `min_stay`, `max_stay`, and a package's arrival weekdays) are checked by one service, `RestrictionCheck`, for every channel.

| Channel                     | When a restriction fails                                                                                                           |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Public site, company portal | Blocked. The plan is shown with the reason, for example "Min stay 2 nights".                                                       |
| Front desk                  | Warning. Staff with `rates.override-restrictions` can continue after entering a reason. The override is logged on the reservation. |
| Channel bookings            | Accepted. The OTA already checked its own copy of the restrictions.                                                                |

- Weekend and holiday prices are daily rates edited in bulk with the pricing form. No weekday arrays.
- Any change records a channel sync range (see [ARI sync](../21-channels/04-ari-sync.md)). Not built yet: `SaveRates` and `ExtendRates` are the places to hook it when channels arrive.
- A room type stays in the `rates` table when a plan stops selling it. The rows return if the link is restored. Readers use only linked room types.

## Writing rates

| Piece              | Job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SaveRates`        | Saves a date range for one plan. Per room type, the request sends one entry per ISO weekday (1 = Monday), so the server applies each weekday to every date in the range. A weekday with no amount leaves its price alone. A weekday with an amount replaces that date's occupancy prices. A `restrictions` array (only the keys sent) is applied to every date of the range, and creates rows that have no price yet. Returns false for an archived plan, a room type the plan does not sell, an amount on a package plan, or a merged min stay above the max stay. |
| `ExtendRates`      | Copies the last 7 priced days of each room type forward 12 months, from the day after its last price or today, whichever is later. Copies `amount`, occupancy prices, `min_stay` and `max_stay`. Keeps date-specific flags already on the new dates.                                                                                                                                                                                                                                                                                                                |
| `UpsertRates`      | The shared bulk upsert for both.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `RestrictionCheck` | Lists what a stay breaks: stop sell on any night, closed to arrival on the arrival date, closed to departure on the departure date, min and max stay from the arrival date's row. The caller decides to block, warn or accept.                                                                                                                                                                                                                                                                                                                                      |
| `RatesEndingCheck` | Finds the last priced date per room type. Prices are "ending" when that is missing or earlier than today + 60 days.                                                                                                                                                                                                                                                                                                                                                                                                                                                 |

Routes: `settings.rate-plans.pricing.edit`, `.update` and `.extend` (all `settings.manage`). Package plans get 404.

## Permissions

| Permission                    | Guards                                                        | Default roles     |
| ----------------------------- | ------------------------------------------------------------- | ----------------- |
| `rates.view`                  | Open the rate calendar                                        | All default roles |
| `rates.manage`                | Edit rates and restrictions in the calendar, and Extend there | Owner, Manager    |
| `rates.override-restrictions` | Book past a restriction at the front desk                     | Owner, Manager    |
| `settings.manage`             | The plan editor, including its Pricing tab                    | Owner, Manager    |

The check is built in `AddUnit` (see [Group bookings](../12-reservations/02-group-bookings.md#addunit)). The front desk screen for it arrives with the booking form.

## Rates ending

- A plan with its own prices shows a warning when a linked room type has no price within the next 60 days. The warning shows on the dashboard and the night audit readiness panel.
- **Extend 12 months** copies the last 7 days (Mon–Sun) of each room type forward: `amount`, occupancy prices, `min_stay` and `max_stay`.
- `stop_sell`, `closed_to_arrival` and `closed_to_departure` are not copied. They are date-specific, for example a maintenance stop sell.
- Derived plans have no warning and no button. They follow the parent.

## Pricing form

One form fills `rates` for a date range. The rate plan editor's Pricing tab ("Set prices directly") and the calendar's Bulk edit both use it.

- Header: date range, and a **Simple | By day** switch.
- **Simple**: a Weekdays column and a Weekend column. "Weekend nights" is preselected from `property_settings.weekend_nights` (see [Property settings](../02-property/02-property-settings.md)). Changing it in the form affects only that apply, not the setting and not other plans.
- **By day**: seven columns, Mon to Sun.
- Simple to By day carries the values over. By day to Simple works only when the prices match a weekday and weekend pattern. Otherwise the form asks to stay on By day.
- The mode and the form's weekend choice are not stored. Only `rates` and `rate_occupancy_prices` are written.
- On a phone, By day scrolls sideways inside a table for now.

| Pricing basis | Simple                                                                                         | By day                                                         |
| ------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| PerRoom       | Weekdays and Weekend per room type. "Different price by adults" adds a row per adult count.    | 7 columns per room type                                        |
| PerGuest      | A row per adult count, Weekdays and Weekend, with Auto fill                                    | A row per adult count, 7 columns, one room type open at a time |
| PerBed        | Weekdays and Weekend per room type                                                             | 7 columns per room type                                        |
| Package       | Not used. Package plans use [package prices](../09-packages/02-package-prices.md).             | Not used                                                       |
| DayUse        | A row per hour count, Weekdays and Weekend, with Auto fill ([Day use prices](#day-use-prices)) | A row per hour count, 7 columns                                |

Bulk edit adds the restriction fields to the same form (see [Bulk edit](#bulk-edit)).

## Day use prices

```
rate_hour_prices    id, rate_id, hours, amount
                    unique (rate_id, hours), CHECK hours 1–24, amount ≥ 0
```

- It mirrors `rate_occupancy_prices`: one price per hour count for a room type, plan and date, on the `rates` row. The `rates.amount` of a Day use plan stays null.
- A date sells an hour count only when its price exists. The booking form leaves out an hour count with no price, and the quote refuses it with `no_hour_price`.
- Restrictions: only Stop sell applies. Min stay, max stay, closed to arrival and closed to departure are hidden for a Day use plan, and `SaveRatesRequest` refuses them.
- **Saving.** A saved date replaces its hour prices inside the plan's current min to max hours and keeps hour prices outside that range. A blank hour inside the range is cleared when the date is saved with other hours. `ExtendRates` copies hour prices too. A Day use rate counts as priced when it has an hour price (`Rate::scopePriced()`), so "priced until" and the rates ending warning follow hour prices.
- **Pricing form.** Each room type group has one row per hour count from the plan's minimum to its maximum, with Weekdays and Weekend (Simple) or Mon to Sun (By day). `SaveRates` writes the `rates` rows and their `rate_hour_prices`.
- **Auto** asks for the first block (hours and price), the change for each extra hour (an amount or %, up or down) and an optional cap ("Never more than"). Example: first 1 hour 40.00, each extra hour +20.00, cap 180.00 gives 1h 40, 2h 60, 3h 80 … 12h 180. Like the adults Auto, it only fills the fields and its inputs are not stored.
- **Rate calendar.** A Day use row shows the lowest hour price of each date ("from 40.00"), and a tooltip lists the hour prices. Open and sold counts stay overnight only. The date editor lists the hour prices read-only with a pointer to the Pricing tab and Bulk edit, and keeps Stop sell editable. Bulk edit shows the hour rows and Stop sell only.
- **Auto fill.** It fills every weekday column. A percentage step compounds from the hour before. Hours below the first block stay as they are.

## UI

Built now: the rate plan editor's **Pricing** tab (`settings.rate-plans.pricing.edit`). It has the rates ending warning with **Extend 12 months**, then one table. Each room type is a collapsible group row that shows how far it is priced, its **Auto** button and its "Different price by adults" switch, with its price rows underneath. On a PerGuest plan in By day view, only one group is open at a time. The form starts empty for each save. It does not preload saved prices. Bulk edit restrictions are on the calendar's Bulk edit page.

## The rate calendar

Route: `rates.calendar` at `/p/{code}/rates/calendar` (`rates.view`). It is in the Front desk context under Operations. `rates.calendar.changes` (`PUT /rates/calendar/changes`, `rates.manage`) saves all staged edits together and returns to the calendar. `rates.calendar.extend` (`rates.manage`) extends one plan and returns to the calendar. `rates.calendar.bulk` and `rates.calendar.bulk.update` (`rates.manage`) are the Bulk edit page and its save.

**Built (read only):**

- **Range.** The range is in the URL: `?from=2026-10-05&to=2026-10-18`. With no `from` it starts today, and with no `to` it shows 14 days. The longest range is 62 days, counting both ends. `to` before `from`, a bad date or a `to` with no `from` is refused. A range change is a request. The neighbouring ranges are prefetched.
- **Toolbar.** **Today**, the previous and next arrows, the date range picker, the **14 days**, **21 days** and **Month** presets, the room type and plan filters, and an icon button that opens the legend.
    - Today scrolls to today's column when today is already in the range. Otherwise it starts the range today and keeps its length. A month range becomes this month.
    - The arrows move by the length of the range, or by one month for a whole-month range.
    - A preset keeps the start date. Month snaps to the whole month of the start date.
    - The range picker asks for the new range only after the last date is picked.
- **Rows.** `RateCalendarService` builds the range. It reads only and adds no query per plan or date. The page gets a group for each active room type and a row for each active plan that sells it. Archived plans, archived room types and unlinked room types are left out.
- **Cells.** A row has a cell only for a date with a price or a restriction row. The cell shows the price, stop sell, closed to arrival (CTA), closed to departure (CTD) and the min and max stay.
- **Derived plans.** A derived plan row shows the parent's room price after the derivation, with a link marker. It is read only. The plan's own restriction rows show on the same dates.
- **Package plans.** A package row shows the price of the **whole stay** on every date of a price period, for example `600/2N`, which is the price for guests arriving that day. The row's second line says "Package, 2 nights, per adult" (or "per room"). The restrictions of a date's `rates` row show beside the price. A date on a weekday the package cannot start on shows "No arrival" and a muted price. It is drawn only, nothing is stored as closed to arrival. A period is clipped to the range. Dates outside every period have no price.
- **Inclusions.** A plan that has [inclusions](../09-packages/01-inclusions.md) shows a box icon button next to its name. It opens a popover that lists each inclusion with its quantity and charging unit, in the order of the Inclusions tab. Staff with `settings.manage` also get a "Manage inclusions" link that opens the plan's Inclusions tab in a new browser tab (`canManageInclusions` prop). Inclusions never change the price shown.
- **Open and sold.** The heading row of each room type shows "N open" and "N sold" for every date, as wastays does. `RateCalendarService` reads them from [`AvailabilityService`](../10-availability/01-availability-service.md) for the range (`availability` on each room type: `units`, `sold`, `open`). `units` leaves out blocked units. A date with nothing open shows in the warning tone. Sold counts the booked units of [`AvailabilityService`](../10-availability/01-availability-service.md). This costs a fixed number of queries for each room type, none for a plan or a date.
- **Occupancy footer.** A row at the bottom of the grid shows the occupancy rate of each date and `sold/units`, added over the room types shown (the room type filter applies). Rate = sold / (units minus blocked units), rounded to a whole percent, 0 when there are no units. The browser only adds what the server sent.
- **Filters.** The room type and plan filters run in the browser. They make no request.
- **Rates ending.** The warning shows above the toolbar for plans with their own prices. Staff with `rates.manage` get **Extend 12 months** for each plan.
- **Layout.** A Grid calendar page has no page header. The tab links (Calendar, Bulk edit) are the top of the page, then the toolbar, and the grid fills the rest of the window, edge to edge. The page uses the `fixed` layout variant (`RateCalendar.layout = { variant: 'fixed' }`): the page is fixed to the window height (at least 30rem), and the grid uses `fill`, so it takes what the tabs and toolbar leave. It scrolls inside itself, and the occupancy footer stays at the bottom, also when there are few rows. The grid does not measure the window. The plan column is 288px wide.
- **Columns.** A date header shows the weekday over the day and month, for example "Wed" over "30 Sep". Weekend nights are tinted across the whole column. They come from the property's weekend nights setting, not from a rate plan. Today's column has its own highlight.
- **Theme and contrast.** The grid uses the `grid-*` tokens (see [Tokens](../design-system/02-tokens-and-typography.md)). CTA and CTD are filled warning pills, because warning-colored text fails contrast on the tints. Today's header has `aria-current="date"`. Each cell has a text alternative for screen readers, and its markers are decorative.
- **Collapsible groups.** A room type heading is a button with a chevron and `aria-expanded`. It collapses or expands that room type's plan rows. A collapsed heading shows how many plans it hides, for example "3 plans". The state is in the browser, per page visit, and makes no request. It follows the filters, so the count is the plans that pass them.
- **Hover.** The row and the column under the pointer are highlighted, on the cells and on the plan label and date header. It makes no request.
- **Engine.** The grid is the shared `pms/grid` engine (`DateGrid`): a sticky label column and date header, fixed row and column sizes, and virtualized rows and columns with `@tanstack/react-virtual`. Its scroll area has no overscroll bounce, and it sizes itself to the space left in the window, so only the grid scrolls. Stay View reuses it.

**Edit dates (built):** staff with `rates.manage` click a cell, or focus it and press Enter, to open a side sheet for that plan, room type and date. Empty dates open it too, so a new date can be priced. The sheet does not save. **Apply** stages the edit in the browser, and the calendar saves all staged edits with one **Save changes**.

- **Price.** The label follows the pricing basis: Room price, Price per adult or Price per bed. A room or guest plan can also set a price for each adult count from 1 up to what the room type sleeps. A blank field uses the default price.
- **Restrictions.** Stop sell, closed to arrival, closed to departure, min stay and max stay. A blank stay limit clears it.
- **Derived and package plans.** The sheet shows the calculated price, or the stay price of the date for a package, and only the restrictions can change. A package sheet also says the price is set on the package's Pricing tab in Settings, because `rates.manage` does not include `settings.manage`.
- **Staging (dirty check).** Apply keeps the edit in the page state, keyed by plan, room type and date. A staged cell shows its new values with an info ring and dot. Opening it again shows the staged values. An edit that is back at the saved values is removed. Staged edits survive a change of range on the page. Plan and room type filters do not affect them. `pending-changes.ts` holds the model (`stagePending`, `applyPending`, `buildPendingPayload`, `pendingErrors`).
- **Save bar.** While anything is staged, a floating bar at the bottom of the grid (same style as the bulk action bar) shows "N unsaved changes", **Discard** (confirmed with a dialog) and **Save changes**. Leaving the page asks first (Inertia visit to another page, and the browser's own leave prompt on reload or close). Save sends `{ changes: [...] }` to `rates.calendar.changes` and clears the bar on success. When the server refuses, the staged edits stay and the bar lists each error with its room type, plan and date.
- **Save.** `SaveRateCalendarChanges` runs `SaveRates` once for each staged edit (one date, one plan, one room type) in one transaction: all are saved or none. `SaveRateCalendarChangesRequest` validates up to 200 changes, each a plan and room type of the property, a date, an optional price with prices by adults, and the restrictions. A plan, room type and date can appear once. A Day use plan refuses prices and stay limits. The price and prices by adults are sent only when they changed, because saving a price replaces that date's prices by adults. Every restriction is sent. Each `SaveRates` call fires its own rate event, and the channel flush merges them into one call.
- **Limits.** A price cannot be removed here, because `SaveRates` leaves a blank price alone. To stop selling a date, turn on stop sell. A price by adults needs a price first.
- **Data.** `RateCalendarService` adds one query each for the package prices, the arrival weekdays and the inclusions of the range, so a plan or a date adds none. Each cell carries its prices by adult count, and each room type carries `baseOccupancy` and `maxAdults`, from one extra query for the month.

### Bulk edit

`rates.calendar.bulk` at `/p/{code}/rates/calendar/bulk` (`rates.manage`). The **Bulk edit** tab next to the **Calendar** tab opens it with the visible range and the plan filter. Staff without `rates.manage` see no tabs. It is a full page, because it has repeating price rows.

- **Plan.** A picker of active plans. `?plan=` picks one and defaults to the first. An archived plan or one of another property is ignored.
- **Dates.** A date range picker, up to 731 days. `?from=` and `?to=` prefill it.
- **Prices.** The same form as the plan editor's Pricing tab: Simple or By day, Auto fill and prices by adults. It is shown only for a plan with its own prices. A derived plan and a package plan show a note, and only restrictions can change.
- **Restrictions.** Stop sell, closed to arrival and closed to departure are each Leave as is, Turn on or Turn off. Min and max stay are Leave as is, Set to a number, or No limit. Only the restrictions you change are sent, so the others keep what is saved on each date.
- **Save.** `rates.calendar.bulk.update` calls `SaveRates` for the whole range, then opens the calendar at the start of the range. If the range is longer than the calendar's 62 days, the calendar shows the first 62. Save stays off until there are dates and something to change.
- **Reuse.** `PricingForm` takes a save route, a start range, a `restrictions` switch and a `prices` switch. The plan editor's Pricing tab uses the defaults and behaves as before.

## Lessons from wastays

- Occupancy prices existed twice: as `room_rates.occupancy_prices` jsonb and as the `room_type_rate_plan_occupancy_prices` table.
- The travel agent portal could book stop-sell, closed-to-arrival/departure and min/max-stay dates because each channel had its own check.
- wastays stored the Auto inputs in a jsonb column (`occupancy_ladder`) so the editor could reopen as Auto.
- A pair-level default price (`room_type_rate_plans.pricing_mode`) never ran out, but it competed with daily rates. estatell keeps only daily rates and warns before they run out.
