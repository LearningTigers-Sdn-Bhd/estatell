# Wizard steps

## Purpose

Get a property from sign-up to its first booking in about 15 minutes, without confusion. The flow follows wastays: a phase bar, a stepper inside each phase, a fixed footer, and locked sections shown greyed out. The mechanics do not follow wastays (see [Lessons from wastays](#lessons-from-wastays)).

## Principles

1. Ask in the owner's order: place, rooms, money, prices, check-in, team.
2. Confirm smart defaults instead of building from scratch.
3. Write to the real tables through the real Actions. No draft tables.
4. Progress is calculated from real data. No stored section states.
5. Only real dependencies lock a section. **Next** follows one fixed order, so the flow still feels linear.
6. Each section has its **own onboarding form** (guided copy, suggested defaults, one decision per screen). It saves through the **same Actions and Data classes** as settings.
7. The owner stays inside onboarding until the property is Live (see [The fence](#the-fence)).

The first release has only the Basics page. It uses the current staff layout. Basics currently accepts Malaysia only and seeds roles, property settings, transaction codes, taxes and payment methods. It does not create terms yet. Other countries arrive with [country profiles](../02-property/03-seed-property-defaults.md#country-profiles).

## Phases and sections

| #   | Phase      | Section                                                                                                                                                                                                                                                                                                   | Required | Locked until       |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ------------------ |
| 1   | Property   | Basics: property type cards, name, country (prefilled Malaysia), address. Another country shows currency and time zone. Editable until another section starts, then **Change country** (room types and rooms kept). See [Changing country](../02-property/03-seed-property-defaults.md#changing-country). | Yes      | –                  |
| 2   | Rooms      | Room types: an editable table, sold by room only. See [Room types table](#room-types-table).                                                                                                                                                                                                              | Yes      | Basics             |
| 3   |            | Rooms: number ranges per room type, optional room groups. See [Rooms table](#rooms-table).                                                                                                                                                                                                                | Yes      | Room types         |
| 4   | Finance    | Taxes, including which taxes apply to rooms (room revenue). See [Taxes table](#taxes-table).                                                                                                                                                                                                              | Yes      | Basics             |
| 5   |            | Payment methods: turn seeded methods on or off, add more. See [Payment methods table](#payment-methods-table).                                                                                                                                                                                            | Yes      | Basics             |
| 6   |            | Companies: city ledger companies and their portal users. See [Companies table](#companies-table).                                                                                                                                                                                                         | No       | Basics             |
| 7   | Prices     | Basic rate: weekday and weekend price per room-sold room type, priced by room or by guest, plus optional more rates. See [Basic rate table](#basic-rate-table).                                                                                                                                           | Yes      | Room types + Taxes |
| 8   |            | Charge items: starter items and extras with price, unit and taxes. See [Charge items table](#charge-items-table).                                                                                                                                                                                         | No       | Taxes              |
| 9   |            | Discounts: the starter discount and more, with type, value and what they apply to. See [Discounts table](#discounts-table).                                                                                                                                                                               | No       | Basics             |
| 10  | Check-in   | Check-in essentials: times, registration card, security deposit and house terms. See [Check-in essentials form](#check-in-essentials-form).                                                                                                                                                               | Yes      | Basics             |
| 11  | Team       | Roles and staff: invite staff and choose roles                                                                                                                                                                                                                                                            | No       | Basics             |
| 12  | Test drive | Practice mode. See [Test drive](06-test-drive.md).                                                                                                                                                                                                                                                        | No       | All required Done  |
| 13  | Review     | Readiness list and go live. See [Go live](03-go-live.md).                                                                                                                                                                                                                                                 | –        | All required Done  |

```
                              1 Basics*
   ┌─────────┬─────────┬──────────┼───────────┬────────────┬───────────┐
   ▼         ▼         ▼          ▼           ▼            ▼           ▼
 2 Room    4 Taxes*  5 Payment  6 Companies  9 Discounts  10 Check-in  11 Team
   types*  │     │     methods*   (opt)        (opt)        essentials*  (opt)
   │   │   │     ▼
   ▼   │   │   8 Charge items (opt)
 3 Rooms*  │
       ▼   ▼
     7 Basic rate*

 all * Done ──▶ 12 Test drive (opt) ──▶ 13 Review / Go live
```

Room revenue is not a separate section. Its taxes are the **Applies to rooms** column in Taxes. The no-show, cancellation, early departure and late check-out policies stay Free (seeded) and are on the Recommended checklist after go-live.

- **Next** follows the numbers. The phase bar lets the owner jump to any unlocked section.
- Optional sections show **Skip** in the footer.
- Not in onboarding: photos, packages and meals, companies and city ledger, channel manager. They are in the Recommended checklist after go-live.

## Room types table

```
 What kinds of rooms do you sell?
 ┌────────────────────────────────┬─────────────┬──────────────┬────┐
 │ Name                           │ Max adults  │ Max children │    │
 ├────────────────────────────────┼─────────────┼──────────────┼────┤
 │ [ Standard Double            ] │ [ 2 ]       │ [ 1 ]        │ 🗑 │
 │ [                            ] │ [ 2 ]       │ [ 0 ]        │ 🗑 │
 ├────────────────────────────────┴─────────────┴──────────────┴────┤
 │ [+ Add room type]                                                │
 └──────────────────────────────────────────────────────────────────┘
```

- Follows the settings age groups form: shadcn `table`, a remove button per row, **Add room type** in the `TableFooter`.
- With no room types, the table starts with one empty row. Saved room types load as rows.
- The last row cannot be removed: its remove button is disabled (`focusableWhenDisabled`, tooltip "At least one room type").
- New rows default to 2 adults and 0 children.
- Columns: name, max adults, max children. No sell unit: onboarding always creates `SellUnit::Room`. `base_occupancy` defaults to max adults, so Basic rate has no extra-adult charge. Description, smoking, pets and terms stay in settings. The hint under the table: "After you go live, you can add descriptions and room rules in Settings, under Room types."
- One submit saves the table in one transaction: new rows create, changed rows update, removed rows delete, through the room type Actions used by settings. Errors show per row (`rows.{n}.name`). A small onboarding request takes `rows` and reuses the `RoomTypeRequest` rules per row.
- Removing a saved room type that has rooms follows the settings rule (to verify).
- At phone width each row stacks into a card.
- Dorm beds (`SellUnit::Bed`) are not offered. A hostel sees "Selling dorm beds? After you go live, you can add them in Settings, under Room types." A bed-sold type made in settings shows read-only with "Sold by bed. Edit in settings." and counts toward Done. Basic rate leaves it out ("After you go live, you can price beds in Settings, under Rate plans.").
- Check: Done with at least one room type.

## Rooms table

```
 Room groups   [ No groups | ● Use groups ]

 Groups (only with Use groups)
 ┌──────────────────────────────────────────────┬────┐
 │ Name                                         │    │
 ├──────────────────────────────────────────────┼────┤
 │ [ Ground floor                             ] │ 🗑 │
 │ [ Garden block                             ] │ 🗑 │
 ├──────────────────────────────────────────────┴────┤
 │ [+ Add group]                                     │
 └───────────────────────────────────────────────────┘

 Rooms
 ┌──────────────────┬────────────────┬────────┬───────┬───────┬───────┬────┐
 │ Room type        │ Group          │ Prefix │ From  │ To    │ Rooms │    │
 ├──────────────────┼────────────────┼────────┼───────┼───────┼───────┼────┤
 │ [Standard Dbl ▾] │ [Ground floor▾]│ [    ] │ [101] │ [106] │   6   │ 🗑 │
 │ [Deluxe King  ▾] │ [Garden block▾]│ [ G  ] │ [  1] │ [  4] │   4   │ 🗑 │
 ├──────────────────┴────────────────┴────────┴───────┴───────┴───────┴────┤
 │ [+ Add range]                                           Total: 10       │
 └─────────────────────────────────────────────────────────────────────────┘
 Preview   Ground floor 101 … 106 · Garden block G1 … G4
```

**Ranges**

- One range row per room-sold room type to start. Only the room type is prefilled. Prefix, From and To are empty, with a grey placeholder (101 to 110) on the first row. Numbers are never guessed.
- Columns: room type, prefix, from, to, rooms count. The Group column appears with Use groups.
- No floor mode and no suffix in onboarding: "floors 2 to 3, rooms 01 to 04" is two ranges. Floor mode and suffix stay in settings.
- The rooms count and preview are derived in the browser for display. The server validates and creates.
- Limits follow `GenerateRoomsRequest`. A number already in use, or repeated across rows, shows an error on the row and its preview chip, and nothing saves.
- A fully empty row is ignored on save, not an error.
- **Add range** in the `TableFooter`. A row can be removed once rooms exist. Before that the last row stays.
- Returning: saved rooms show as chips (under group headings when grouped) with × to delete, with a confirm popover. Blocked when a practice booking uses the room ("Reset practice first"). One empty range row is added for more rooms.
- Bed-sold room types are not in the room type menu.

**Room groups**

- A `toggle-group`: **No groups** (default) or **Use groups**. It starts on Use groups when the property already has groups.
- Use groups shows a Groups table (name, remove, **Add group** in the footer) above the ranges, and a required Group menu on every range row. The first group is preselected. Half-grouped rooms would leave an ungrouped heap on the housekeeping board and Stay View.
- The Group menu lists the names typed in the Groups table, before anything is saved. Rows refer to new groups by row key. The server maps keys to ids.
- Group names follow `RoomGroupRequest` (unique, case-insensitive). Table order sets the group position, which `AutoAssignRooms` uses first.
- The last group row cannot be removed while Use groups is on. Removing a group that a range uses clears that range's menu and shows an error until a group is picked.
- Back to No groups: unsaved groups hide and are not sent. With saved groups the toggle is disabled, with the tooltip "After you go live, you can remove groups in Settings, under Rooms."

**Save and check**

- One submit, one transaction: create the groups, then `GenerateRooms` (range mode) per row with its `room_group_id`.
- At phone width each row stacks into a card, with the Group menu on its own line.
- Check: Done when every room-sold room type has at least one room.

## Taxes table

```
 ┌────┬──────────────────┬────────────────┬──────────────┬──────────┬────────────────┬──┐
 │ On │ Name             │ Rate           │ In price     │ Applies  │ Reg. no.       │  │
 │    │                  │                │              │ to rooms │                │  │
 ├────┼──────────────────┼────────────────┼──────────────┼──────────┼────────────────┼──┤
 │[●] │[ SST           ] │[ 8    ][ % ▾]  │[Added on ▾]  │   [x]    │[ W10-1808-32 ] │⌄ │
 │[●] │[ Tourism tax   ] │[ 10.00][RM ▾]  │[Added on ▾]  │   [x]    │[             ] │⌃ │
 │    │  per night · [x] Foreign guests only · [x] Collect at check-in              │  │
 │[●] │[ Service charge] │[ 10   ][ % ▾]  │[Added on ▾]  │   [ ]    │[             ] │⌄🗑│
 │    │  ℹ Not applied to rooms. Link it to extras in Charge items →                │  │
 ├────┴──────────────────┴────────────────┴──────────────┴──────────┴────────────────┴──┤
 │ [+ Add tax]                                                                          │
 └──────────────────────────────────────────────────────────────────────────────────────┘
 What a guest pays for 1 night at RM 100.00
 ┌──────────────┬─────────────┬───────────────┐
 │              │ Local guest │ Foreign guest │
 │ Room         │     100.00  │       100.00  │
 │ SST 8%       │       8.00  │         8.00  │
 │ Tourism tax  │          –  │        10.00  │
 │ Total        │     108.00  │       118.00  │
 └──────────────┴─────────────┴───────────────┘
 [ ] We don't charge tax
 ℹ No-show and cancellation charges are free for now. After you go live, you can
   set them in Settings, under Room revenue.
```

- Malaysia starts with the seeded SST and Tourism tax rows ("Your Malaysian taxes are ready. Turn on what you charge."). Other countries start with one empty row ("Add the taxes you charge guests.").
- Columns: **On** (`enabled`), **Name**, **Rate** (amount and a % or currency menu = `rate_type`), **In price** (**Added on top** or **Included** = `is_inclusive`), **Applies to rooms**, **Reg. no.** (`registration_number`).
- ⌄ opens row details: **Foreign guests only** ("not {country}") and **Collect at check-in**. A flat rate shows "per night" only when it applies to rooms. Otherwise the quantity comes from the charge item.
- The short code is not shown. It is derived from the name (VAT → `TAX_VAT`, a number added when taken) and editable in settings. To verify that `TaxRequest` accepts a derived code.
- Preset rows have no remove button. They are turned off instead, because they own system posting codes. Custom rows can be removed. The last row stays while no taxes exist.
- **Applies to rooms** saves through `UpdateRoomRevenueTaxes`, which links ROOM and every policy code that follows the Taxes tab. The header tooltip says "Also used for no-show, cancellation, early departure and late check-out charges."
- A tax that does not apply to rooms is allowed (for example a service charge on food only). Its row says "Not applied to rooms. Link it to extras in **Charge items →**", a link to section 8, which is unlocked once Taxes is Done. Charge items list it in each item's tax menu.
- When no enabled tax applies to rooms, the example shows a warning: "Room nights have no tax. Tick **Applies to rooms** on a tax if they should."
- The example is a sample night of 100 in the property currency, or the cheapest Basic rate once prices exist. The server calculates it with `RoomRevenuePreview` for a local and a foreign guest, as on the settings Taxes tab. The browser never calculates tax.
- **We don't charge tax** greys out the table and turns every tax off. It is stored as an `onboarding_skips` row for Taxes. Unticking it removes the row.
- At phone width each row stacks into a card, with `[x] Applies to rooms` and ⌄ More options.
- Check: Done when at least one tax is enabled (anywhere), or **We don't charge tax** is ticked.
- Review shows "Applies to rooms: {taxes}" (or "Room nights: no tax") as info, and warns with **Fix →** when an enabled tax applies to no charge at all. Neither blocks go-live.

## Payment methods table

```
 ┌────┬──────────────────────────┬──────────────────┬───────────────┬────┐
 │ On │ Name                     │ Type             │ Ask for ref.  │    │
 │    │                          │                  │ no.           │    │
 ├────┼──────────────────────────┼──────────────────┼───────────────┼────┤
 │[●] │[ Cash                  ] │ Cash             │     [ ]       │    │
 │[●] │[ Card                  ] │ Card             │     [x]       │    │
 │[●] │[ Bank transfer         ] │ Bank transfer    │     [x]       │    │
 │[●] │[ E-wallet              ] │ E-wallet         │     [x]       │    │
 │[○] │[ City ledger           ] │ City ledger      │      –        │    │
 │    │  ℹ Bill stays to companies. Add them in the next section.    │    │
 │[●] │[ DuitNow QR            ] │[ E-wallet     ▾] │     [x]       │ 🗑 │
 ├────┴──────────────────────────┴──────────────────┴───────────────┴────┤
 │ [+ Add payment method]                                                │
 └───────────────────────────────────────────────────────────────────────┘
 After you go live, you can add card fees and change the order in Settings,
 under Payment methods.
```

- Rows: the seeded methods of the addable types (Cash, Card, Bank transfer, E-wallet) and City ledger. OTA collected is hidden (channels come after go-live).
- **On** is `active`. Methods are never deleted, so a saved method is turned off instead. 🗑 only on unsaved rows.
- **Name** is editable (renames its transaction code, existing rule). **Type** is read-only for saved methods and a menu of the addable types for new rows. **Ask for ref. no.** is `requires_reference`, starting from the type default. City ledger shows "–".
- A new method's transaction code is derived from the name (DuitNow QR → `DUITNOW_QR`, at most 20 characters, a number added when taken) and editable in settings.
- Surcharges are hidden. Every method saves as `None`, because a fee needs a charge item (section 8). "Add a card fee" is on the Recommended checklist.
- Order is table order. New rows go last.
- One submit, one transaction, through the payment method Actions used by settings.
- Check: Done when at least one addable method is active. The seeded methods start active, so this shows Done before the owner opens it. **Next** still visits it, and Review lists the active methods.

## Companies table

```
 ┌──────────────────────┬──────────────┬───────────────────────┬────────┬──────────────┬───────┬────┐
 │ Name                 │ Type         │ Contact email         │ Direct │ Credit limit │ Terms │    │
 │                      │              │                       │ bill   │              │ days  │    │
 ├──────────────────────┼──────────────┼───────────────────────┼────────┼──────────────┼───────┼────┤
 │[ Petronas Carigali ] │[Corporate  ▾]│[ travel@petronas.my ] │  [x]   │ RM[20000.00] │ [30]  │ ⌃  │
 │   BRN [ 201901234567 ]  TIN [ C2584563200 ]  SST no. [ W10-… ]  Phone [ +60 88 … ]     │    │
 │   Portal users                                                                         │    │
 │   [ Aina Rahman     ] [ aina@petronas.my     ]  Temp password · 12d  [Copy message]    │    │
 │   [ +Add portal user ]                                                                 │    │
 │[ Borneo Tours      ] │[Travel agt ▾]│[ ops@borneotours.my ] │  [x]   │ RM[        ] │ [14]  │ ⌄🗑│
 ├──────────────────────┴──────────────┴───────────────────────┴────────┴──────────────┴───────┴────┤
 │ [+ Add company]   [Import from file]                                                             │
 └──────────────────────────────────────────────────────────────────────────────────────────────────┘
 Empty credit limit = no limit. After you go live, you can give companies their
 own rates in Settings, under Rate plans.
```

- Optional. No rows to start: the empty state offers **Add company** and **Import from file** (the existing `BulkCreateCompanies` import and template).
- Columns from `CompanyRules`: name, type (new rows Corporate), contact email, **Direct bill** (new rows ticked), credit limit (property currency, empty = no limit), terms days.
- ⌄ row details: BRN, TIN, SST no., contact phone, and the portal users. The billing address stays on the company Details tab.
- Companies are never deleted. 🗑 only on unsaved rows. A saved company shows "Suspend in settings".
- Saving a company with **Direct bill** turns on the City ledger payment method, with the toast "City ledger turned on in Payment methods".
- Name is unique per property, ignoring case. Errors show per row.
- One submit, one transaction, through `CreateCompany` and `UpdateCompany`.
- Check: Done with at least one company, or Skipped.

**Portal users**

- No email invitations in onboarding. Each portal user (name, email) is created with `CreateCompanyUserWithTemporaryPassword` and an Active link. Adding the first one sets the company's `portal_enabled`.
- An email that already belongs to a company user follows the existing rule (Pending link, in-app).
- The row shows **Temp password · Nd** with **Copy message**, as on the staff list. The page never receives temp passwords as props.
- The portal opens only when the property is Live (`portal` middleware). Owners hand out the sign-in details after go-live.
- Temp passwords expire after 14 days. Review lists portal users with **Export sign-in slips** (PDF), and expired ones with **Reset temp password**.

## Basic rate table

```
 Weekend nights   [● Fri + Sat | Sat + Sun ]
 Weekend price    [ Same every night | ● Different on weekends ]

 ┌──────────────────────┬──────────────┬──────────────────┬──────────────────┐
 │ Room type            │ Price by     │ Weekdays         │ Weekend          │
 ├──────────────────────┼──────────────┼──────────────────┼──────────────────┤
 │ Garden Chalet        │ [ Room   ▾ ] │ RM [ 180.00 ]    │ RM [ 220.00 ]    │
 │                      │              │ guest pays 194.40│ guest pays 237.60│
 │ Longhouse            │ [ Guest  ▾ ] │ RM [  85.00 ]    │ RM [  95.00 ]    │
 │                      │              │ per adult        │ per adult        │
 │                      │              │ 2 adults: 170.00 │ 2 adults: 190.00 │
 └──────────────────────┴──────────────┴──────────────────┴──────────────────┘
 This creates 2 rates: "Basic Rate / Room" (1 room type) and
 "Basic Rate / Guest" (1 room type).

 More rates (optional)
 ┌───────────────────────┬────────────────────────────────┬────────────────────────┬────┐
 │ Name                  │ Price                          │ Room types             │    │
 ├───────────────────────┼────────────────────────────────┼────────────────────────┼────┤
 │[ Room with breakfast] │[Follows Basic Rate / Room   ▾] │[ Garden Chalet      ▾] │ 🗑 │
 │                       │ [More ▾] [30.00] [RM ▾]        │                        │    │
 │[ Long stay          ] │[Own prices                  ▾] │[ All                ▾] │⌃🗑 │
 │   (nested grid: Room type · Price by · Weekdays · Weekend)                     │    │
 ├───────────────────────┴────────────────────────────────┴────────────────────────┴────┤
 │ [+ Add rate]                                                                         │
 └──────────────────────────────────────────────────────────────────────────────────────┘
```

**Page settings**

- **Weekend nights** is a `toggle-group` (`FriSat`, `SatSun`). It saves to `property_settings.weekend_nights`, because here it is the property default. Column headers follow it ("Fri, Sat").
- **Weekend price**: **Same every night** hides the Weekend column and sends one amount for all 7 weekdays. Not stored. On return it is preselected when all saved weekdays match.

**Basic rate rows**

- One row per room-sold room type, in Room types order. No add or remove: room types come from section 2.
- **Price by** per row: **Room** (default) or **Guest**. Guest prices are per adult per night (adult price × adults). Prices by adult count, Auto fill and the single supplement stay in Rates.
- Amounts are required, more than 0, in the currency's decimals.
- Under each price: "guest pays" for a Room row and "{base_occupancy} adults: …" for a Guest row. The server calculates both with the taxes that apply to rooms, for a local guest. The browser never calculates prices or tax.
- With a Guest row, a note says: "Children on per-guest prices: infants (0–3) stay free, children (4–11) pay half. After you go live, you can change this in Settings, under Rate plans." This is the existing pre-fill for a new PerGuest plan.
- Extra adults are not asked, because `base_occupancy` is max adults.

**Plans behind the table**

A rate plan has one pricing basis, so the rows map to one plan per basis used:

| Rows use       | Plans                                   | Names                                    |
| -------------- | --------------------------------------- | ---------------------------------------- |
| Room only      | The seeded plan                         | Basic Rate                               |
| Guest only     | The seeded plan, basis switched         | Basic Rate                               |
| Room and Guest | The seeded plan and a new PerGuest plan | Basic Rate / Room and Basic Rate / Guest |

- The note under the table says which rates will be created, before saving.
- Switching the seeded plan's basis uses a new `ChangeRatePlanBasis`, allowed only before go-live. After go-live the basis is fixed, as today.
- Switching a row with saved prices asks first (AlertDialog: "Switching Longhouse to per guest clears its prices.").
- A plan left with no room types is archived.
- Room types added later link to the seeded plan (Basic Rate / Room when mixed). To verify how `SeedRatePlans` finds that plan without a flag.

**More rates**

- Optional. Empty state: "Sell other prices, like a breakfast or non-refundable rate." **Add rate** in the `TableFooter`. Rows are disabled until Basic rate has prices.
- **Price** menu: one "Follows {plan}" per Basic Rate plan ("Follows Basic rate" when there is one), or **Own prices**.
- Follows: **More** or **Less**, an amount, **RM** or **%** (`CreateDerivedRatePlan`: direction, value, derivation). An amount is per unit of the parent basis ("per guest" on a Guest parent). An example column shows the result for the first room type's weekday price (server). Room types: only the parent's.
- Own prices: `CreateRatePlan` and `SaveRates`, with a nested grid of the selected room types and the same **Price by** column. Mixed rows split the same way ("Long stay / Room", "Long stay / Guest").
- **Room types** is a multi-select, All by default, select and deselect both ways, at least one ("Pick at least one room type"). Saved rows use `RatePlanRoomTypesRequest`. To verify whether `SaveRates` keeps a deselected room type's amounts for reselecting.
- Saved rows: name, change and room types are editable (`SaveRatePlanDerivation`). Switching Own prices to Follows clears own amounts and asks first. 🗑 archives (`ArchiveRatePlan`).
- When a follower's room type moves to the other basis, it drops off the follower and the row shows an error. Nothing moves silently.
- A rate's name does not add breakfast to the bill. The hint: "After you go live, you can include meals in Settings, under Rate plans."
- Not in onboarding: inclusions, restrictions, long-stay discounts, company rates, packages, day use, per bed.

**Save and check**

- One submit, one transaction: plan bases and names, room type links, `SaveRates` per own-price plan from today to today + 364 (property time zone), then the more rates. Re-saving replaces the amounts in that range. That is safe before go-live, because the rate calendar is behind the fence.
- At phone width each row stacks into a card.
- Check: every room-sold room type has prices for the next 30 days on its Basic Rate plan. More rates never block.

## Charge items table

```
 ┌────┬────────────────────┬──────────────────┬────────────────┬───────────────────┬────────────────┬────┐
 │ On │ Name               │ Category         │ Price          │ Charged           │ Taxes          │    │
 ├────┼────────────────────┼──────────────────┼────────────────┼───────────────────┼────────────────┼────┤
 │[●] │[ Food and beverage]│ Food & beverage  │ RM [         ] │[ Per item      ▾] │[ SST        ▾] │ ⌄  │
 │[●] │[ Laundry          ]│ Laundry          │ RM [         ] │[ Per item      ▾] │[ SST        ▾] │ ⌄  │
 │[●] │[ Breakfast        ]│[Food & bev.   ▾] │ RM [  25.00 ]  │[ Per guest-night▾]│[ SST        ▾] │⌃🗑 │
 │    ├──────────────────────────────────────────────────────────────────────────────────────────────┤    │
 │    │ Price when charging   [x] Staff can change the price                                         │    │
 │    │ Children              Pay the same as adults. After you go live, you can set child           │    │
 │    │                       prices in Settings, under Charge items.                                │    │
 │    │ Code                  BREAKFAST  (made from the name)                                        │    │
 │    └──────────────────────────────────────────────────────────────────────────────────────────────┘    │
 ├────┴────────────────────┴──────────────────┴────────────────┴───────────────────┴────────────────┴────┤
 │ [+ Add item]                                                                                         │
 └──────────────────────────────────────────────────────────────────────────────────────────────────────┘
 Empty price = staff enter the amount each time.
```

- Optional. Starts with the starter items from `SeedChargeItems` (Malaysia: Food and beverage, Laundry, Minibar, General fee, all without a price). No empty row.
- **On** is `active`. Items are never deleted: a saved item is turned off. 🗑 only on unsaved rows.
- **Name** is editable and unique (renames the code, existing rule).
- **Category** is read-only for starter items. New rows pick from `TransactionCategory::chargeItemCases()` without Transport (boat fares have their own settings tab), default Other.
- **Price** is `default_amount`: positive, or empty for "Enter when charging", in the currency's decimals.
- **Charged** is `charging_unit` (Per item, Per night, Per stay, Per guest-night, Per guest).
- **Taxes** is a multi-select of the taxes enabled in section 4, saved through the item's tax links as in settings. For new rows and for starter items without taxes, the form preselects the enabled **percentage taxes for all guests** (for example SST, service charge). Flat and foreign-only taxes (tourism tax) are not preselected. Nothing is saved until **Save**.
- ⌄ **More options**, a full-width strip under the row:
    - **Staff can change the price** (`allow_override`, on by default). Locked on when the price is empty (database CHECK).
    - **Children** note, only for Per guest and Per guest-night: the existing default Percent 100, with "After you go live, you can set child prices in Settings, under Charge items."
    - **Code**: read-only. A new item shows the code made from the name (Extra bed → `EXTRA_BED`, a number added when taken), updating as the name is typed. Starter items show "fixed".
- Not in onboarding: auto add, description, child prices, Transport items.
- Arriving from the Taxes hint **Charge items →** highlights the Taxes column.
- One submit, one transaction, through the charge item Actions used by settings.
- At phone width each row stacks into a card with ⌄ More options.
- Check: optional, never blocks. Done when at least one active item has a price or a tax, or Skipped.

## Discounts table

```
 ┌────┬──────────────────────┬──────────────┬────────────────┬─────────────────────────┬────┐
 │ On │ Name                 │ Type         │ Value          │ Applies to              │    │
 ├────┼──────────────────────┼──────────────┼────────────────┼─────────────────────────┼────┤
 │[●] │[ Discount          ] │[ Open     ▾] │ staff enter it │[ All charges         ▾] │ ⌄  │
 │[●] │[ Walk-in           ] │[ Percent  ▾] │ [ 10   ] %     │[ Room only           ▾] │ ⌄🗑│
 │[●] │[ Returning guest   ] │[ Fixed    ▾] │ RM [ 20.00 ]   │[ All charges         ▾] │⌃🗑 │
 │    ├──────────────────────────────────────────────────────────────────────────────┤    │
 │    │ Amount when used   [x] Staff can change the amount                           │    │
 │    │ Code               RETURNING_GUEST  (made from the name)                     │    │
 │    └──────────────────────────────────────────────────────────────────────────────┘    │
 ├────┴──────────────────────┴──────────────┴────────────────┴─────────────────────────┴────┤
 │ [+ Add discount]                                                                         │
 └──────────────────────────────────────────────────────────────────────────────────────────┘
 After you go live, you can set long-stay and rate discounts in Rates.
```

- Optional. Starts with the starter discount from `SeedDiscounts` (Malaysia: **Discount**, Open, All charges). No empty row.
- **On** is `active`. Discounts are never deleted: a saved one is turned off. 🗑 only on unsaved rows.
- **Name** is editable and unique, ignoring case (renames the code).
- **Type** and **Value**: Percent 1 to 100 %, Fixed more than 0 in the property currency, Open with no value ("staff enter it").
- **Applies to**: Room only (the five room revenue codes), All charges (every charge except tax), or Selected. Selected lists "Room charges" (the five room codes as one tick) and each active charge item, at least one ticked.
- ⌄ **More options**: **Staff can change the amount** for Fixed only (`allow_override`). Percent shows it locked off and Open locked on, as the database CHECKs require. **Code** is read-only, made from the name. The starter shows "fixed".
- No example line: a discount applies to a folio, so a sample would mislead.
- One submit, one transaction, through the discount Actions used by settings.
- At phone width each row stacks into a card with ⌄ More options.
- Check: optional, never blocks. Done when at least one discount is active, or Skipped. The starter is active, so this usually shows Done.

## Check-in essentials form

```
 Times
   Check-in from   [ 15:00 ▾ ]        Check-out by   [ 11:00 ▾ ]

 Registration card
   Who fills in a card    [● Main guest only | Every adult ]
   [x] Guests must sign the card before check-in
   Keep ID photos for  ⓘ  [ 365 ] days      (empty = keep them)
   Collect a security deposit   [● on ]
   Amount                       RM [ 100.00 ]

 House terms              Template [ Homestay ▾ ]   Language [ English ▾ ]
 ┌──────────────────────────────────────┬───────────────────────────┐
 │ Editor (after Edit terms)            │ Preview                   │
 │ 2. A security deposit of             │ 2. A security deposit of  │
 │    {security_deposit} is collected…  │    RM 100.00 is collected…│
 └──────────────────────────────────────┴───────────────────────────┘
 Templates are a starting point, not legal advice.
```

Not a table: three groups on one page.

**Times**

- Check-in and check-out time menus in 30-minute steps (`H:i`). They save through the property settings Action, general section, with only these two fields. Arrival grace and auto-assign rooms stay in settings.

**Registration card**

- **Who fills in a card**: a `toggle-group` for `registration_card_scope` (Main guest only = PrimaryOnly, Every adult = EveryAdult).
- **Guests must sign the card before check-in**: `card_required_for_check_in`.
- **Keep ID photos for** {n} days: `id_document_retention_days`, 1 to 3650, empty keeps them. An ⓘ button opens a Popover (works on tap and keyboard): "Photos of a guest's IC or passport, taken at check-in. After this many days they are deleted automatically. The ID number stays on the guest record, so you can still search for it. A shorter time keeps less personal data." This matches `PurgeExpiredIdentityScans`, which deletes scan files only.
- **Collect a security deposit**: a switch, not stored. It is on when `security_deposit_amount` is more than 0. On shows **Amount** (required, more than 0, currency decimals, prefilled with the saved amount). Off hides it and saves 0, so no deposit is asked at check-in. Off then on again shows the last amount typed on the page.
- When the switch is off and the terms text uses `{security_deposit}`, a hint says: "Your house terms mention a deposit. Edit them if you don't collect one."

**House terms**

- The property's default terms document (seeded by `SeedRegistrationTerms` from the property type's preset), in the selected language. Language lists the property locales (English and Bahasa Malaysia for Malaysia, English elsewhere).
- Default view: a read-only preview with placeholders filled by the server, and **Edit terms**.
- **Edit terms** opens the settings editor and preview (markdown editor and the in-app renderer, no new package) side by side. At phone width they become **Edit** and **Preview** tabs. **Discard changes** restores the published text. Empty text is refused: "House terms can't be empty."
- **Save & continue** publishes a new version (`PublishRegistrationTerms`) only for languages whose text changed. With only one language edited, a line says: "Bahasa Malaysia still uses the template text."
- **Template**: choosing another preset opens an AlertDialog ("This replaces your house terms with the Resort template. Your edits stay in version history.") and saves it with `ApplyTermsPreset` as a new version when confirmed.
- Version history, extra documents and card fields stay in settings: "After you go live, you can see past versions and add terms per room type in Settings, under Registration card."
- Needs `registration-card.manage-terms` (the Owner has it).

**Save and check**

- One submit, one transaction: one onboarding request validates both groups and calls the property settings and registration card settings Actions, then publishes changed terms.
- Check: required. Done when check-in and check-out times are set and a published terms document exists. Both are seeded, so it shows Done before the owner opens it. **Next** still visits it, and Review lists "Check-in 15:00 · Check-out 11:00 · Deposit RM 100.00".

## Section states

| State           | Meaning                                                                           |
| --------------- | --------------------------------------------------------------------------------- |
| Locked          | A section in "Locked until" is not Done                                           |
| NotStarted      | Unlocked, no data yet                                                             |
| InProgress      | Some data, the check is not met                                                   |
| Done            | The check is met, or the optional section is skipped                              |
| Needs attention | The section was Done and its check now fails, for example after an edit elsewhere |

Editing an earlier section never resets a later one. A later section only shows Needs attention when its own check fails.

## Locks

- The step controller sends `locked` and `blockedBy` props.
- The page wraps its form in `<StepForm locked>`: a `<fieldset disabled>` and a banner "Finish {section} first" with a link. To verify: Base UI Select, Switch and Combobox follow a native `fieldset disabled`. If not, `StepForm` uses Base UI `Fieldset` with `disabled`.
- The server enforces the lock too: a save while a prerequisite is not Done is rejected.

## Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ estatell · Seaview Lodge                               [user menu ▾] │
├──────────────────────────────────────────────────────────────────────┤
│ ✓ Property ▸ ✓ Rooms ▸ ● Finance ▸ 🔒 Prices ▸ Check-in ▸ Team ▸ …    │ phase bar
├──────────────────────────────────────────────────────────────────────┤
│ Finance:  ✓ Taxes · ● Payment methods · ○ Companies                  │ stepper
├──────────────────────────────────────────────────────────────────────┤
│ Payment methods                                           ~1 min     │
│ ...section form...                                                   │ scrolls
├──────────────────────────────────────────────────────────────────────┤
│ [← Back]                                     [Save & continue →]     │ fixed footer
└──────────────────────────────────────────────────────────────────────┘
```

- Its own onboarding layout, set with Inertia 3 `useLayoutProps`. No PMS sidebar and no ⌘K command palette.
- The phase bar shows state by icon (check, lock, warning, number), never by colour alone, with the state word for assistive tech.
- The footer is a flex sibling of the scroll area, so it never covers the last field.
- Hints that point beyond onboarding say "After you go live, you can … in Settings, under {page}." (or "in Rates") in plain text, with no arrow paths. Settings is behind the fence until go-live. A hint that opens another onboarding section is a link instead, for example **Charge items →**.

## Forms

| Layer                        | Shared with settings?                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------- |
| Actions, Data classes, enums | Shared. One write path.                                                             |
| Form Requests                | Reused where the fields match. A small onboarding request where the form asks less. |
| Controllers                  | Own: `App\Http\Controllers\Onboarding\{Section}Controller`                          |
| Pages                        | Own: `pages/onboarding/{section}.tsx`                                               |
| Field components             | Shared `components/ui`. Settings sub-forms only where they fit cleanly.             |

When a field is added to a settings form, decide in the same change whether the onboarding form asks for it too.

## The fence

| Status          | `/p/{code}/onboarding/*`                                | Other `/p/{code}/*` routes                                                                                          |
| --------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Onboarding      | Owner and view as: open. Other staff: the waiting page. | Redirect to the current section. Practice routes are open in practice mode ([Test drive](06-test-drive.md#routes)). |
| PendingApproval | Review and Go live only (waiting notice)                | Redirect to Go live                                                                                                 |
| Live            | Redirect to the dashboard                               | Open                                                                                                                |

- The waiting page says "Your property is still being set up. Ask {Owner name}." Staff can be invited early so their accounts are ready on day one.
- After go-live, settings is the only place to edit setup data.

## Data model

```
onboarding_skips    property_id, step (OnboardingStep), skipped_by, skipped_at
```

The only onboarding-specific table. It stores skips of optional steps, and the **We don't charge tax** answer for Taxes, the one required step that accepts a "none" answer.

## Enums and services

- `OnboardingPhase` enum with `label()` and `steps()`.
- `OnboardingStep` enum with `phase()`, `isRequired()`, `dependsOn()`, `estimatedMinutes()`, `check()`.
- One `StepCheck` class per step returns NotStarted, InProgress or Done from real data. Example: `BasicRateCheck` = every room type has rates for the next 30 days.
- `OnboardingProgress` combines checks and locks for the phase bar, stepper, readiness list and admin board.

## Middleware

- `live` (`EnsurePropertyIsLive`) wraps every PMS route file, including the dashboard, settings, staff and rates. It redirects to the current section until the property is Live, with the practice exception.
- `onboarding.only` (`EnsureOnboarding`) wraps `onboarding.php`. It redirects Live properties to the dashboard and other staff to the waiting page.

## Build order

1. Onboarding layout, `OnboardingPhase`, `OnboardingStep`, `OnboardingProgress`, `StepForm`, the fence and the waiting page. Basics moves into the new layout.
2. Rooms phase (2, 3).
3. Finance phase (4, 5, 6).
4. Prices phase (7, 8, 9).
5. Check-in (10) and Team (11).
6. Review: the readiness list reads the checks.
7. [Test drive](06-test-drive.md).

## Lessons from wastays

- 12 sections in a strict chain, with taxes and room revenue before rooms, and team blocking taxes.
- `onboarding_staff_drafts`, `onboarding_corporate_drafts` and a jsonb `onboarding_submissions.snapshot`.
- Stored section states and `InvalidateDependentSections` bounced owners back.
- A 761-line controller, 36 services and about 2,450 lines of separate views.
- Kept: the phase bar, stepper, fixed footer and greyed-out locked sections.
