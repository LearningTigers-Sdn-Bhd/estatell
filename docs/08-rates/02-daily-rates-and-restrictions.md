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

| Pricing basis | `amount` means                                  |
| ------------- | ----------------------------------------------- |
| PerRoom       | Room price for the room type's `base_occupancy` |
| PerGuest      | Price for one adult                             |
| PerBed        | Price per bed                                   |
| Package       | Null. Rows carry restrictions only.             |

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
- Restrictions (`stop_sell`, `closed_to_arrival`, `closed_to_departure`, `min_stay`, `max_stay`) are checked by one service, `RestrictionCheck`, for every channel.

| Channel                   | When a restriction fails                                                                                                           |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Public site, agent portal | Blocked. The plan is shown with the reason, for example "Min stay 2 nights".                                                       |
| Front desk                | Warning. Staff with `rates.override-restrictions` can continue after entering a reason. The override is logged on the reservation. |
| Channel bookings          | Accepted. The OTA already checked its own copy of the restrictions.                                                                |

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

The front desk override arrives with the booking form.

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

| Pricing basis | Simple                                                                                      | By day                                                         |
| ------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| PerRoom       | Weekdays and Weekend per room type. "Different price by adults" adds a row per adult count. | 7 columns per room type                                        |
| PerGuest      | A row per adult count, Weekdays and Weekend, with Auto fill                                 | A row per adult count, 7 columns, one room type open at a time |
| PerBed        | Weekdays and Weekend per room type                                                          | 7 columns per room type                                        |
| Package       | Not used. Package plans use [package prices](../09-packages/02-package-prices.md).          | Not used                                                       |

Bulk edit adds the restriction fields to the same form (see [Bulk edit](#bulk-edit)).

## UI

Built now: the rate plan editor's **Pricing** tab (`settings.rate-plans.pricing.edit`). It has the rates ending warning with **Extend 12 months**, then one table. Each room type is a collapsible group row that shows how far it is priced, its **Auto** button and its "Different price by adults" switch, with its price rows underneath. On a PerGuest plan in By day view, only one group is open at a time. The form starts empty for each save. It does not preload saved prices. Bulk edit restrictions are on the calendar's Bulk edit page.

## The rate calendar

Route: `rates.calendar` at `/p/{code}/rates/calendar` (`rates.view`). It is in the Front desk context under Operations. `rates.calendar.update` (`PUT`, `rates.manage`) saves one plan and returns to the calendar. `rates.calendar.extend` (`rates.manage`) extends one plan and returns to the calendar. `rates.calendar.bulk` and `rates.calendar.bulk.update` (`rates.manage`) are the Bulk edit page and its save.

**Built (read only):**

- **Range.** The range is in the URL: `?from=2026-10-05&to=2026-10-18`. With no `from` it starts today, and with no `to` it shows 14 days. The longest range is 62 days, counting both ends. `to` before `from`, a bad date or a `to` with no `from` is refused. A range change is a request. The neighbouring ranges are prefetched.
- **Toolbar.** **Today**, the previous and next arrows, the date range picker, the **14 days**, **21 days** and **Month** presets, the room type and plan filters, and an icon button that opens the legend.
    - Today scrolls to today's column when today is already in the range. Otherwise it starts the range today and keeps its length. A month range becomes this month.
    - The arrows move by the length of the range, or by one month for a whole-month range.
    - A preset keeps the start date. Month snaps to the whole month of the start date.
    - The range picker asks for the new range only after the last date is picked.
- **Rows.** `RateCalendarService` builds the range. It reads only and adds no query per plan or date. The page gets a group for each active room type and a row for each active plan that sells it. Archived plans, archived room types and unlinked room types are left out.
- **Cells.** A row has a cell only for a date with a price or a restriction row. The cell shows the price, stop sell, closed to arrival (CTA), closed to departure (CTD) and the min and max stay.
- **Derived and package plans.** A derived plan row shows the parent's room price after the derivation, with a link marker. It is read only. The plan's own restriction rows show on the same dates. A package plan row shows restrictions only.
- **Filters.** The room type and plan filters run in the browser. They make no request.
- **Rates ending.** The warning shows above the toolbar for plans with their own prices. Staff with `rates.manage` get **Extend 12 months** for each plan.
- **Layout.** A Grid calendar page has no page header. The toolbar is the top of the page and the grid fills the rest of the window, edge to edge. The plan column is 288px wide.
- **Columns.** A date header shows the weekday over the day and month, for example "Wed" over "30 Sep". Weekend nights are tinted across the whole column. They come from the property's weekend nights setting, not from a rate plan. Today's column has its own highlight.
- **Theme and contrast.** The grid uses the `grid-*` tokens (see [Tokens](../design-system/02-tokens-and-typography.md)). CTA and CTD are filled warning pills, because warning-colored text fails contrast on the tints. Today's header has `aria-current="date"`. Each cell has a text alternative for screen readers, and its markers are decorative.
- **Collapsible groups.** A room type heading is a button with a chevron and `aria-expanded`. It collapses or expands that room type's plan rows. A collapsed heading shows how many plans it hides, for example "3 plans". The state is in the browser, per page visit, and makes no request. It follows the filters, so the count is the plans that pass them.
- **Hover.** The row and the column under the pointer are highlighted, on the cells and on the plan label and date header. It makes no request.
- **Engine.** The grid is the shared `pms/grid` engine (`DateGrid`): a sticky label column and date header, fixed row and column sizes, and virtualized rows and columns with `@tanstack/react-virtual`. Its scroll area has no overscroll bounce, and it sizes itself to the space left in the window, so only the grid scrolls. Stay View reuses it.

**Edit one date (built):** staff with `rates.manage` click a cell, or focus it and press Enter, to open a side sheet for that plan, room type and date. Empty dates open it too, so a new date can be priced.

- **Price.** The label follows the pricing basis: Room price, Price per adult or Price per bed. A room or guest plan can also set a price for each adult count from 1 up to what the room type sleeps. A blank field uses the default price.
- **Restrictions.** Stop sell, closed to arrival, closed to departure, min stay and max stay. A blank stay limit clears it.
- **Derived and package plans.** The sheet shows the calculated price or a note, and only the restrictions can change.
- **Save.** It sends one date to `SaveRates` through `rates.calendar.update`. The price and prices by adults are sent only when they changed, because saving a price replaces that date's prices by adults. Every restriction is sent. Save is off until something changed.
- **Limits.** A price cannot be removed here, because `SaveRates` leaves a blank price alone. To stop selling a date, turn on stop sell. A price by adults needs a price first.
- **Data.** Each cell carries its prices by adult count, and each room type carries `baseOccupancy` and `maxAdults`, from one extra query for the month.

### Bulk edit

`rates.calendar.bulk` at `/p/{code}/rates/calendar/bulk` (`rates.manage`). A **Bulk edit** link in the calendar toolbar opens it with the visible range and the plan filter. It is a full page, because it has repeating price rows.

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
