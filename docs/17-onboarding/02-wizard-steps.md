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

The first release has two pages, Basics and Review (go live). Both use the onboarding layout. Basics offers any country. `SeedPropertyDefaults` seeds the [country profiles](../02-property/03-seed-property-defaults.md#country-profiles) for it.

## Phases and sections

| #   | Phase      | Section                                                                                                                                                         | Required | Locked until       |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ------------------ |
| 1   | Property   | Basics: property type, name, country, address, and currency and time zone outside Malaysia. See [Basics form](#basics-form).                                    | Yes      | –                  |
| 2   | Rooms      | Room types: an editable table, sold by room only. See [Room types table](#room-types-table).                                                                    | Yes      | Basics             |
| 3   |            | Rooms: number ranges per room type, optional room groups. See [Rooms table](#rooms-table).                                                                      | Yes      | Room types         |
| 4   | Finance    | Taxes, including which taxes apply to rooms (room revenue). See [Taxes table](#taxes-table).                                                                    | Yes      | Basics             |
| 5   |            | Payment methods: turn seeded methods on or off, add more. See [Payment methods table](#payment-methods-table).                                                  | Yes      | Basics             |
| 6   |            | Companies: city ledger companies and their portal users. See [Companies table](#companies-table).                                                               | No       | Basics             |
| 7   | Prices     | Basic rate: weekday and weekend price per room-sold room type, priced by room or by guest, plus optional more rates. See [Basic rate table](#basic-rate-table). | Yes      | Room types + Taxes |
| 8   |            | Charge items: starter items and extras with price, unit and taxes. See [Charge items table](#charge-items-table).                                               | No       | Taxes              |
| 9   |            | Discounts: the starter discount and more, with type, value and what they apply to. See [Discounts table](#discounts-table).                                     | No       | Basics             |
| 10  | Check-in   | Check-in essentials: times, registration card, security deposit and house terms. See [Check-in essentials form](#check-in-essentials-form).                     | Yes      | Basics             |
| 11  | Team       | Team: staff with temp passwords and a role. See [Team table](#team-table).                                                                                      | No       | Basics             |
| 12  | Channels   | Channel manager: OTA logins the estatell team connects after approval. See [Channel manager table](#channel-manager-table).                                     | No       | Basics             |
| 13  | Test drive | Practice mode. See [Test drive](06-test-drive.md).                                                                                                              | No       | All required Done  |
| 14  | Review     | Review: the readiness list, warnings and go live (or send for approval). See [Review page](#review-page).                                                       | –        | All required Done  |

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

   Basics* ──▶ 12 Channel manager (opt)

 all * Done ──▶ 13 Test drive (opt) ──▶ 14 Review / Go live
```

Room revenue is not a separate section. Its taxes are the **Applies to rooms** column in Taxes. The no-show, cancellation, early departure and late check-out policies stay Free (seeded) and are on the Recommended checklist after go-live.

- **Next** follows the numbers. The phase bar lets the owner jump to any unlocked section.
- Optional sections show **Skip** in the footer.
- Not in onboarding: photos, packages and meals, and the live channel connection (mappings, rates and availability sync). They are in the Recommended checklist after go-live. Only the OTA logins are collected in onboarding.

## Basics form

```
 What kind of place is it?
 [ Hotel ● ] [ Hostel ] [ Resort ] [ Serviced apartment ] [ Homestay ]

 Property name   [ Seaview Lodge                          ]
 Country         [ 🔍 Malaysia                          ▾ ]
 Address line 1  [ 12 Jalan Pantai                        ]
 Address line 2  [                                        ]
 City            [ Kota Kinabalu ]   State [ Sabah      ▾ ]
 Postcode        [ 88000 ]
 ┌ ℹ Malaysia: MYR · Asia/Kuala_Lumpur · SST, tourism tax and local payment presets ┐

 Business details (optional)  ⓘ
   Registered business name   [ Seaview Hospitality Sdn Bhd ]
   SSM registration no.       [ 202301012345               ]
   TIN (LHDN)                 [ C1234567890                ]
   Licences
   ┌──────────────────────────────────────┬──────────────────────┬──────────────┬────┐
   │ Issued by                            │ Licence no.          │ Expires      │    │
   ├──────────────────────────────────────┼──────────────────────┼──────────────┼────┤
   │[ Dewan Bandaraya Kota Kinabalu     ] │[ DBKK/LP/2025/01234] │[ 31/12/2026 ]│ 🗑 │
   ├──────────────────────────────────────┴──────────────────────┴──────────────┴────┤
   │                                                                 [+ Add licence] │
   └─────────────────────────────────────────────────────────────────────────────────┘
```

Another country:

```
 Country         [ 🔍 Indonesia                         ▾ ]
 Currency        [ 🔍 Rupiah (IDR)                      ▾ ]
 Time zone       [ Asia/Jakarta                         ▾ ]
 State           [ Bali                                   ]   (free text)
 ┌ ⚠ Malaysian presets don't apply. You'll add your own taxes in Finance. ┐
```

Setup started (another section is no longer Not started):

```
 Country     Malaysia · MYR · Asia/Kuala_Lumpur     [Change country]
```

**Fields**

| Field                                      | Rule                                                                                                                                                                   |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Type                                       | Required `PropertyType`, five cards                                                                                                                                    |
| Name, address line 1 and 2, city, postcode | `SavePropertyBasicsRequest::identityRules()`                                                                                                                           |
| Country                                    | Required, from `CountryOptions`, searchable, Malaysia first and prefilled                                                                                              |
| State                                      | Malaysia: the Malaysian state list (`MalaysianStates`, the name is stored in `state_code`). Others: free text                                                          |
| Currency                                   | Malaysia: not shown, MYR. Others: required, from `CurrencyOptions`, prefilled from the country (ICU). Empty when the country's currency is left out (for example KWD). |
| Time zone                                  | Malaysia: not shown, `Asia/Kuala_Lumpur`. Others: required, one of the country's zones (`DateTimeZone::PER_COUNTRY`), prefilled when there is one                      |

- The info or warning box under the form follows the country profile.

**Business details (optional)**

- **Registered business name** (`properties.legal_name`), the registration number and TIN (`registration_number`, `tin`), labelled by the country profile ("SSM registration no." and "TIN (LHDN)" for Malaysia, "Business registration no." and "Tax ID (TIN)" otherwise). They save with the Profile tab rules.
- **Licences**: a table of `property_licences` rows (issued by, licence no., optional expiry), for example the local council licence from Dewan Bandaraya Kota Kinabalu or a MOTAC registration. Issuer is free text with the placeholder "e.g. Dewan Bandaraya Kota Kinabalu". Licence no. is required when the row has an issuer. No starting rows: "No licences yet" with **Add licence** in the `TableFooter`. 🗑 deletes a row.
- ⓘ opens a Popover: "These can appear on invoices, receipts and the registration card. After you go live, you can choose which ones to show in Settings, under Printing."
- All optional. They never block Basics.
- Admin- and partner-created properties arrive with type and country filled ([Account creation](01-account-creation.md)). The owner can still change them here.

**Changing country**

- While every other section is Not started, country, currency and time zone are plain fields. Saving a change runs `ChangePropertyCountry`, which reseeds the untouched presets.
- After that they are read-only (`SettingsLock::locksCountry`, sent as `basics.locked`) and the page shows "Country · currency · time zone" as plain text. **Change country**, which unlocks them in place with a warning, is a later item (see the checklist). Until then the server ignores country, currency and time zone in a Basics save once they are locked, and `ChangePropertyCountry` refuses. **Save & continue** opens an AlertDialog with the counts of what resets. Room types, rooms and team stay. Full rules: [Changing country](../02-property/03-seed-property-defaults.md#changing-country).
- After go-live they are fixed.

**Save and check**

- `SavePropertyBasics` saves the property and runs `SeedPropertyDefaults` with the country profile in one transaction ([Seed property defaults](../02-property/03-seed-property-defaults.md)). Saving again only updates type, name, address and business details, and country, currency and time zone while they are not locked.
- At phone width the type cards wrap into two columns and the fields stack.
- Check: Done when the property has a type, name, address line 1, city, country and a `property_settings` row.

## Room types table

```
 What kinds of rooms do you sell?
 ┌────────────────────────────────┬─────────────┬──────────────┬────┐
 │ Name                           │ Max adults  │ Max children │    │
 ├────────────────────────────────┼─────────────┼──────────────┼────┤
 │ [ Standard Double            ] │ [ 2 ]       │ [ 1 ]        │ 🗑 │
 │ [                            ] │ [ 2 ]       │ [ 0 ]        │ 🗑 │
 ├────────────────────────────────┴─────────────┴──────────────┴────┤
 │                                                [+ Add room type] │
 └──────────────────────────────────────────────────────────────────┘
```

- Follows the settings age groups form: shadcn `table`, a remove button per row, **Add room type** in the `TableFooter`.
- With no room types, the table starts with one empty row. Saved room types load as rows.
- The last row cannot be removed: its remove button is disabled (`focusableWhenDisabled`, tooltip "At least one room type").
- New rows default to 2 adults and 0 children.
- Columns: name, max adults, max children. No sell unit: onboarding always creates `SellUnit::Room`. `base_occupancy` defaults to max adults, so Basic rate has no extra-adult charge. Description, smoking, pets and terms stay in settings. The hint under the table: "After you go live, you can add descriptions and room rules in Settings, under Room types."
- One submit saves the table in one transaction (`SaveOnboardingRoomTypes`): new rows create, changed rows update, removed rows are archived together with their rooms (a deleted row frees its name, and its rooms free their numbers), through the room type Actions used by settings. The table order sets the display order. Errors show per row (`rows.{n}.name`). `SaveRoomTypesRequest` takes `rows` (1 to 50) and uses the bounds of `RoomTypeRequest` per row. A name must differ from the other rows, from bed-sold types and from the saved name of another kept row, so two rows cannot swap names in one save.
- Updating a saved row also sets `base_occupancy` to the new max adults.
- At phone width each row stacks into a card.
- Dorm beds (`SellUnit::Bed`) are not offered. A hostel sees "Selling dorm beds? After you go live, you can add them in Settings, under Room types." A bed-sold type made in settings shows read-only with "Sold by bed. Edit in settings." and counts toward Done. Basic rate leaves it out ("After you go live, you can price beds in Settings, under Rate plans.").
- Check: Done with at least one active room type (sold by room or by bed), otherwise Not started. There is no In progress state.

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
 │                                     [+ Add group] │
 └───────────────────────────────────────────────────┘

 Rooms
 ┌──────────────────┬────────────────┬────────┬───────┬───────┬───────┬────┐
 │ Room type        │ Group          │ Prefix │ From  │ To    │ Rooms │    │
 ├──────────────────┼────────────────┼────────┼───────┼───────┼───────┼────┤
 │ [Standard Dbl ▾] │ [Ground floor▾]│ [    ] │ [101] │ [106] │   6   │ 🗑 │
 │ [Deluxe King  ▾] │ [Garden block▾]│ [ G  ] │ [  1] │ [  4] │   4   │ 🗑 │
 ├──────────────────┴────────────────┴────────┴───────┴───────┴───────┴────┤
 │ Total: 10                                                 [+ Add range] │
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
- The save is `SaveOnboardingRooms`, called through `SaveRoomsRequest` (`use_groups`, `groups` with a row `key`, `id` and `name`, `ranges` with room type, `group_key`, prefix, from and to). Errors show per row (`ranges.{n}.from`, `groups.{n}.name`). A number that is taken (by a saved room or by another row) shows on the row. If a number is taken between the check and the save, the Action rolls back and the page shows an error toast.
- Saved groups stay in the Groups table as read-only rows and cannot be removed here, so a property that has groups always uses groups. A saved room is removed with × and a confirm popover (`onboarding.rooms.destroy`, through `ArchiveRoom`). The "Reset practice first" block comes with [Test drive](06-test-drive.md).
- Check: Done when every room-sold room type has at least one active room. In progress when some have rooms and some do not. Not started when none do, or when there is no room-sold room type.

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
 │                                                                          [+ Add tax] │
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
- The save is `SaveOnboardingTaxes`, called through `SaveTaxesRequest` (`no_tax`, and `rows` with the tax fields and `applies_to_rooms`). It creates new rows through `CreateTax` and updates saved rows through `UpdateTax`. A saved tax keeps its short code when renamed. A new tax gets the code derived from its name (a number is added when the code or `TAX_{CODE}` is taken). Errors show per row (`rows.{n}.name`, `rows.{n}.rate`).
- The example updates while the owner edits. The page posts the table to `onboarding.taxes.preview`, and `OnboardingTaxPreview` calculates the unsaved rows in memory through `RoomRevenuePreview`. Before Basics is saved the page opens as a locked preview with no example.
- A saved custom row is removed with the trash button and a confirm popover (`onboarding.taxes.destroy`, through `DeleteOnboardingTax`). A tax that something already refers to is refused with "Turn it off instead". An unsaved row is removed in the browser.
- **We don't charge tax** is sent with the table. The footer **Skip** button is not shown for Taxes. Unticking it removes the skip and leaves the taxes turned off.
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
 │                                                [+ Add payment method] │
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
- The save is `SaveOnboardingPaymentMethods`, called through `SavePaymentMethodsRequest` (`rows` with `id`, `name`, `type`, `active`, `requires_reference`). It updates saved rows through `UpdatePaymentMethod` and creates new rows through `CreatePaymentMethod`. A saved method keeps its type, code and any surcharge. A new method gets a code derived from its name, at most 20 characters, with a number added when the code is taken.
- **City ledger** keeps its name field but its On switch and reference box are read-only here, because Companies turns it on when a company is direct bill. The server ignores any value sent for them. OTA collected has no row and cannot be sent.
- A save that leaves no addable method On is refused with "Keep at least one payment method on." Names must differ from the other rows and from every saved method without a row, ignoring case. A new row needs a type.
- A new method takes the end of the active order, so after a save it sits above City ledger, which is off. Settings can reorder it.
- The seeded Done state does not count as setup started for the country lock (`SettingsLock::setupStarted`), so Basics keeps its plain country fields until another section is saved.

## Companies table

```
 ┌──────────────────────┬──────────────┬───────────────────────┬────────┬──────────────┬───────┬────┐
 │ Name                 │ Type         │ Contact email         │ Direct │ Credit limit │ Terms │    │
 │                      │              │                       │ bill   │              │ days  │    │
 ├──────────────────────┼──────────────┼───────────────────────┼────────┼──────────────┼───────┼────┤
 │[ Petronas Carigali ] │[Corporate  ▾]│[ travel@petronas.my ] │  [x]   │ RM[20000.00] │ [30]  │ ⌃  │
 │   BRN [ 201901234567 ]  TIN [ C2584563200 ]  SST no. [ W10-… ]  Phone [ +60 88 … ]     │    │
 │   Portal users                                                                         │    │
 │   [ Aina Rahman     ] [ aina@petronas.my     ]  Ready after go-live                    │    │
 │   [ +Add portal user ]                                                                 │    │
 │[ Borneo Tours      ] │[Travel agt ▾]│[ ops@borneotours.my ] │  [x]   │ RM[        ] │ [14]  │ ⌄🗑│
 ├──────────────────────┴──────────────┴───────────────────────┴────────┴──────────────┴───────┴────┤
 │                                                             [Import from file]   [+ Add company] │
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
- The save is `SaveOnboardingCompanies`, called through `SaveCompaniesRequest` (`rows` with the fields above and the tax numbers, bounded by `CompanyRules`). It creates new rows through `CreateCompany` and updates saved rows through `UpdateCompany`. Saved companies without a row are left alone. A company that manages its own profile keeps its tax numbers. Saving at least one row removes a Skip; saving no rows is valid.
- A suspended company shows in a read-only Suspended list under the table: it cannot be edited here, and its name still counts for the unique-name check.
- **Import from file** reads the file on the server (`onboarding.companies.import`, through `CompanyImportReader`) and adds the rows to the table. Nothing is saved until the owner saves the table. **Template** downloads the Simple CSV or XLSX (`onboarding.companies.template`).
- **Built without portal users.** Portal users need `company_users` and `CreateCompanyUserWithTemporaryPassword`, which come with [Company portal](../23-company-portal/01-company-portal.md). The block and the Review line below are added in that commit.

**Portal users**

- No email invitations in onboarding. Each portal user (name, email) is created with `CreateCompanyUserWithTemporaryPassword` and an Active link. Adding the first one sets the company's `portal_enabled`.
- An email that already belongs to a company user follows the existing rule (Pending link, in-app).
- Before go-live the row shows "Ready after go-live": no Copy, Reset or export. The temp password is replaced at go-live (see [Go live](03-go-live.md#action)), and the details are shared from the go-live screen.
- The portal opens only when the property is Live (`portal` middleware). Owners hand out the sign-in details after go-live.
- Review shows one info line: "{n} staff and {n} company users get their sign-in details when you go live."

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
 │                                                                         [+ Add rate] │
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
 │                                                                                         [+ Add item] │
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
 │                                                                         [+ Add discount] │
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

## Team table

```
 ┌──────────────────────┬────────────────────────┬────────────────┬──────────────────────────────┬────┐
 │ Name                 │ Email                  │ Role  ⓘ        │ Sign-in                      │    │
 ├──────────────────────┼────────────────────────┼────────────────┼──────────────────────────────┼────┤
 │ Nurul Hafizah (you)  │ nurul@seaview.my       │ Owner          │ Active                       │    │
 │[ Aina Rahman       ] │[ aina@seaview.my     ] │[Front desk  ▾] │ Ready after go-live          │    │
 │[                   ] │[                     ] │[Front desk  ▾] │ Created when you save        │ 🗑 │
 ├──────────────────────┴────────────────────────┴────────────────┴──────────────────────────────┴────┤
 │                                                                [Import from file]   [+ Add person] │
 └────────────────────────────────────────────────────────────────────────────────────────────────────┘
 After you go live, you can invite staff by email and make custom roles in
 Settings, under Staff and Roles.
```

- Optional. Starts with the owner (read-only, "(you)") and one empty row.
- Temp passwords only, no email invitations in onboarding. Each new row (name, email, role) is created on save by the bulk add Action (`BulkAddStaffRequest` rules: email required and unique against users and open invitations, errors per row).
- **Role** offers Manager, Front desk, Housekeeping and Finance (not Owner). New rows default to Front desk. An ⓘ Popover lists one line per role, built on the server from the role's permissions. To verify that each default role has a short description.
- **Sign-in** is read-only: "Created when you save" for new rows, "Ready after go-live" for saved rows. Before go-live there is no Copy, Reset or export, and the page never receives temp passwords as props.
- Saved rows have no actions in onboarding. Removing someone is in settings after go-live. 🗑 only on unsaved rows.
- **Import from file** opens the existing bulk add (grid or CSV/XLSX), then returns here.
- Staff who sign in before go-live see the waiting page ([The fence](#the-fence)).
- At go-live every pending temp password is replaced, so the 14 days start then. The go-live screen shares the details (see [Go live](03-go-live.md#after-go-live)). Review shows one info line: "{n} staff and {n} company users get their sign-in details when you go live."
- At phone width each row stacks into a card.
- Check: optional, never blocks. Done with at least one person besides the owner, or Skipped.

## Channel manager table

```
 ┌ ℹ Only the estatell team reads these. Passwords are stored encrypted and are
 │   never shown again here. The team connects your channels through our channel
 │   manager after approval. Nothing is connected from this page.
 └
 ┌──────────┬─────────────┬──────────┬──────────┬────────────────┬─────────────┬───────────────┬────┐
 │ Channel  │ Property ID │ Username │ Password │ Market manager │ Phone       │ Email         │    │
 ├──────────┼─────────────┼──────────┼──────────┼────────────────┼─────────────┼───────────────┼────┤
 │[Agoda  ▾]│[ 123456   ] │[me@x.com]│[••••••  ]│[ Aisha       ] │[ +60 12 … ] │[ aisha@ag… ]  │ 🗑 │
 │[Booking▾]│[ 789012   ] │[me@x.com]│ On file  │[              ]│[           ]│[             ]│ 🗑 │
 ├──────────┴─────────────┴──────────┴──────────┴────────────────┴─────────────┴───────────────┴────┤
 │ Total: 2                                                                      [+ Add OTA login]  │
 └──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

- Optional. No rows to start: the empty state says "No OTA logins yet. Leave this empty and continue if you don't sell through any OTA." An empty table with **Save and continue** counts as Skipped.
- There is no channel manager field. All properties use the platform Channex account (see [Connections](../21-channels/01-connections-and-feature-gate.md)). No feature gate applies, because onboarding only stores the logins.
- Columns: **Channel** (`OtaChannel` select, with Other), Property ID (the property's own code on that channel), Username, Password, and the optional market manager name, phone and email. Channel is required. One row per channel per property, ignoring case. Errors show per row.
- **Password** is write-only. A new row has a password input. A saved row shows "On file". Typing replaces the saved value and leaving it blank keeps it. The page never receives a password as a prop.
- Username and password are stored encrypted (`encrypted` cast). Removing a row with 🗑 deletes it, saved or not.
- One submit, one transaction, through `SaveOtaCredentials`.
- The admin property page has an **OTA logins** tab, read only. **Reveal** shows a password to admins with `support.2fa` and writes an activity log entry. **Mark processed** sets the row to Processed once the team has connected the channel. Nothing in code reads the stored logins.
- At phone width each row stacks into a card.
- Check: optional, never blocks. Done with at least one row, or Skipped.

## Review page

```
 Review
 Almost there. Finish 1 required section to go live.
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ Property   ✓ Basics           Seaview Lodge · Malaysia · MYR       [Edit]    │
 │ Finance    ✓ Taxes            Applies to rooms: SST, Tourism tax   [Edit]    │
 │                               ⚠ Service charge is not applied to any charge  │
 │                                                     [Fix in Charge items →]  │
 │            – Companies        Skipped                              [Add]     │
 │ Prices     ✗ Basic rate       Deluxe King has no price            [Finish →] │
 │ Test drive ○ Test drive       Not started (optional)               [Start]   │
 └──────────────────────────────────────────────────────────────────────────────┘
 ℹ 2 staff and 1 company user get their sign-in details when you go live.
 ⚠ Practice data will be cleared: 2 bookings, 2 folios, 1 payment.
 Your first business day opens on the day you go live.
                                                   [Go live]  or  [Send for approval]
```

- One row per section, grouped by phase, from `OnboardingProgress`: state, a one-line summary built on the server, and an action (**Edit**, **Finish →**, **Add**, **Start**, or **Fix in {section} →** for a warning).
- States: ✓ Done, ✗ required and not done, ○ optional and not started, – skipped, ⚠ Needs attention, ⚑ flagged by support.
- `GoLiveReadiness::checks()` returns one `GoLiveCheckData` per required section, and `isReady()` needs every one Done. Warnings are a separate list and never block: a tax applied to no charge, no tax on rooms, the deposit switch off while the terms mention a deposit, a more-rates follower missing a room type.
- Info lines: the sign-in line and the practice data counts.
- No opening date field. The day you go live is the first business day.
- The button is disabled until ready (tooltip "Finish {sections} first"), hidden during view as, and reads **Send for approval** when approval is on. It opens an AlertDialog: "Go live today?" with the first business day, practice data counts, "Country, currency and time zone can't be changed after this." and the sign-in count.
- After going live: the **You're live!** screen with **Share sign-in details** and **Open dashboard**. After sending: the waiting notice. Pending, sent back and approved states: see [Go live](03-go-live.md#approval).
- Review can be opened before everything is Done and works as a to-do list.
- At phone width rows stack: state and name, then summary and action.

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
- Every section can be opened, locked or not. A locked section opens as a **preview**: the real form with the owner's data, all fields disabled, and the banner "Preview. Finish {section} first to fill this in." with a **Go to {section} →** link.
- The page wraps its form in `<StepForm locked>`: a `<fieldset disabled>` and that banner. To verify: Base UI Select, Switch and Combobox follow a native `fieldset disabled`. If not, `StepForm` uses Base UI `Fieldset` with `disabled`.
- The server enforces the lock too: a save while a prerequisite is not Done is rejected.
- A preview's footer has **Back** and **Next** only (no Save, no Skip). Next opens the next section without saving.
- Locked phase and step links keep the 🔒 icon. Hover or focus shows "Locked until {section} is done. Open to preview." Screen readers hear "{section}, locked, preview".
- Test drive and Review can be previewed too. Review then works as a to-do list of what is missing.

## Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ estatell · Seaview Lodge                               [user menu ▾] │
├──────────────────────────────────────────────────────────────────────┤
│ ✓ Property ❯ ✓ Rooms ❯ ● Finance ❯ 🔒 Prices ❯ Check-in ❯ Team ❯ …   │ phase bar
├──────────────────────────────────────────────────────────────────────┤
│ ✓ 1. Taxes   2. Payment methods   3. Companies                       │ section tabs
├──────────────────────────────────────────────────────────────────────┤
│ Payment methods                                           ~1 min     │
│ ...section form...                                                   │ scrolls
├──────────────────────────────────────────────────────────────────────┤
│ [← Back]                                     [Save & continue →]     │ fixed footer
└──────────────────────────────────────────────────────────────────────┘
```

- Its own onboarding layout (`layouts/onboarding-layout.tsx`). Pages set its footer and step with Inertia 3 `setLayoutProps`. No PMS sidebar, no ⌘K command palette, no business date, no notifications bell, and no links to settings, rates or the dashboard. The phase bar and stepper do the sidebar's job, and the full width fits the section tables.
- **Top bar**: logo, property name and code (not a link), progress ("Setup 6 of 9 required · ~18 min" from `OnboardingProgress` and `estimatedMinutes()`), a **Help** Popover (short guide and support contact, and a note when support access is on), and the user menu (account pages and **Log out**, no property switcher). The impersonation banner sits above the top bar during view as.
- The phase bar and the section tabs use the full viewport width. The phase bar is a row of equal-width arrow-shaped segments (done: light green, current: solid green, others: muted). The section tabs are numbered text tabs on a light band, with an underline on the current one. The form content column below is centred with a max width close to a settings page. A page picks the width with the layout prop `width`: `5xl` (tables with up to about 4 columns, such as Room types), `7xl` (tables with 5 or more columns, such as Rooms and Taxes), or `8xl` (two-column forms such as Basics; `max-w-8xl` is 90rem, defined in `app.css`). Without it the column is 45rem. The footer uses the same width.
- **Navigation**: the phase bar, the stepper and the phone section list link to every section. **Save & continue** saves and opens the next section in number order, even a locked one (as a preview). Leaving an edited section opens the dirty-form AlertDialog (**Cancel**, **Discard**, **Save**, see [Form containers](../design-system/05-form-containers.md)).
- **Footer**: **Back**, **Skip** (optional sections only) and **Save & continue**. A preview has **Back** and **Next**. Go live shows **Back** only until every required section is Done.
- **Phone width**: the top bar keeps the property name, Help and the user menu. The phase bar becomes one line ("Finance · 5 of 13") with a progress bar and **☰**, which opens a bottom Sheet listing every phase and section with its state. The footer shows **←** and the main button, and **Skip** where the step allows it.
- **Progress data**: the server shares `onboarding` (`OnboardingProgressData`) on `onboarding.*` routes, except the waiting page. It lists only steps that have a page, so the phase bar and stepper have no dead links. A phase with no listed step is left out. Other PMS pages do not get the prop.
- The phase bar shows state by icon (check, lock, warning, number), never by colour alone, with the state word for assistive tech.
- The footer is a flex sibling of the scroll area, so it never covers the last field.
- In an editable table the add button sits at the end (right) of the `TableFooter` (`justify-between`). A summary such as "Total: 10" goes at the start (left). With a second action, such as **Import from file**, the add button stays last. See [Form containers](../design-system/05-form-containers.md#editable-tables).
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

| Status          | `/p/{code}/onboarding/*`                                            | Other `/p/{code}/*` routes                                                                                          |
| --------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Onboarding      | Owner and view as: open. Other staff: the waiting page.             | Redirect to the current section. Practice routes are open in practice mode ([Test drive](06-test-drive.md#routes)). |
| PendingApproval | Review (waiting notice), Test drive, sections as read-only previews | Practice routes are open in practice mode. Everything else redirects to Review.                                     |
| Live            | Redirect to the dashboard                                           | Open                                                                                                                |

- The waiting page says "Your property is still being set up. Ask {Owner name}." Staff can be added early (Team) so their accounts are ready on day one.
- After go-live, settings is the only place to edit setup data.

## Data model

```
onboarding_skips        property_id, step (OnboardingStep), skipped_by, skipped_at
property_ota_credentials  id, property_id, channel (OtaChannel), property_code, username (encrypted),
                          password (encrypted), market_manager_name, market_manager_phone,
                          market_manager_email, status (OtaCredentialStatus: Pending, Processed),
                          timestamps. Unique on property_id + lower(channel).
```

`onboarding_skips` stores skips of optional steps, and the **We don't charge tax** answer for Taxes, the one required step that accepts a "none" answer. `property_ota_credentials` outlives onboarding: the admin team reads it later, and owners can edit it in settings.

## Enums and services

- `OnboardingPhase` enum with `label()` and `steps()`.
- `OnboardingStep` enum with `phase()`, `isRequired()`, `dependsOn()`, `estimatedMinutes()`, `check()`.
- One `StepCheck` class per step returns NotStarted, InProgress or Done from real data. Example: `BasicRateCheck` = every room type has rates for the next 30 days.
- `OnboardingProgress` combines checks and locks for the phase bar, stepper, readiness list and admin board.

## Middleware

- `live` (`EnsurePropertyIsLive`) wraps every PMS route file, including the dashboard, settings, staff and rates. It redirects to the current section until the property is Live, with the practice exception.
- `onboarding.only` (`EnsureOnboarding`) wraps `onboarding.php`. It redirects Live properties to the dashboard and other staff to the waiting page.

### Status

- Built: Basics with country, currency, time zone, state list and the Business details group (`SavePropertyBasics`, `ChangePropertyCountry` while setup has not started, `SettingsLock::locksCountry`), the Room types section (`SaveOnboardingRoomTypes`, `RoomTypesCheck`), the enums, `OnboardingProgress`, the Basics check, the `onboarding_skips` table with the Skip and Unskip Actions and their routes (`POST` and `DELETE onboarding/skips/{step}`), the step save rule (`OnboardingStepRequest`), both middlewares, the waiting page, the onboarding layout and the shared progress.
- Stubs: every other step's check (except Basics and Room types) returns NotStarted until its own commit replaces it.
- Not yet: the practice exception, and the Needs attention state (it needs a stored "was Done" marker, so no state is produced yet).
- Go-live readiness is unchanged. It still returns no checks until the sections are built.

## Build order

The commits are listed in [Checklist: 17 Onboarding](../checklist.md#17-onboarding). In short:

1. Property foundations: brick/money currencies, country profiles, registered name and licences.
2. The onboarding layout and fence, then the Basics country and business details.
3. Sections: Room types and Rooms, Taxes, Payment methods and Companies, Basic rate, Charge items and Discounts, Check-in essentials, Team, Channel manager.
4. Review and go live, then [Test drive](06-test-drive.md), then Change country after setup started.

## Lessons from wastays

- 12 sections in a strict chain, with taxes and room revenue before rooms, and team blocking taxes.
- `onboarding_staff_drafts`, `onboarding_corporate_drafts` and a jsonb `onboarding_submissions.snapshot`.
- Stored section states and `InvalidateDependentSections` bounced owners back.
- A 761-line controller, 36 services and about 2,450 lines of separate views.
- Kept: the phase bar, stepper, fixed footer and greyed-out locked sections.
