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
| 5   |            | Payment methods: toggle the seeded presets                                                                                                                                                                                                                                                                | Yes      | Basics             |
| 6   | Prices     | Basic rate: weekday and weekend price per room-sold room type. `SetBasePrices` fills 365 days of rates. It does not create a plan.                                                                                                                                                                        | Yes      | Room types + Taxes |
| 7   |            | Charge items                                                                                                                                                                                                                                                                                              | No       | Taxes              |
| 8   |            | Discounts                                                                                                                                                                                                                                                                                                 | No       | Basic rate         |
| 9   | Check-in   | Check-in essentials: check-in and check-out times, ID requirement, terms preset preview                                                                                                                                                                                                                   | Yes      | Basics             |
| 10  | Team       | Roles and staff: invite staff and choose roles                                                                                                                                                                                                                                                            | No       | Basics             |
| 11  | Test drive | Practice mode. See [Test drive](06-test-drive.md).                                                                                                                                                                                                                                                        | No       | All required Done  |
| 12  | Review     | Readiness list and go live. See [Go live](03-go-live.md).                                                                                                                                                                                                                                                 | –        | All required Done  |

```
                       1 Basics*
   ┌───────────┬──────────┼────────────┬────────────┐
   ▼           ▼          ▼            ▼            ▼
 2 Room      4 Taxes*   5 Payment    9 Check-in   10 Team
   types*    │     │      methods*                  (opt)
   │   │     │     ▼
   ▼   │     │   7 Charge items (opt)
 3 Rooms*    │
       ▼     ▼
     6 Basic rate* ──▶ 8 Discounts (opt)

 all * Done ──▶ 11 Test drive (opt) ──▶ 12 Review / Go live
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
- Columns: name, max adults, max children. No sell unit: onboarding always creates `SellUnit::Room`. `base_occupancy` defaults to max adults (to verify against pricing). Description, smoking, pets and terms stay in settings.
- One submit saves the table in one transaction: new rows create, changed rows update, removed rows delete, through the room type Actions used by settings. Errors show per row (`rows.{n}.name`). A small onboarding request takes `rows` and reuses the `RoomTypeRequest` rules per row.
- Removing a saved room type that has rooms follows the settings rule (to verify).
- At phone width each row stacks into a card.
- Dorm beds (`SellUnit::Bed`) are not offered. A hostel sees "Selling dorm beds? Add them in Settings → Room types after setup." A bed-sold type made in settings shows read-only with "Sold by bed. Edit in settings." and counts toward Done. Basic rate leaves it out ("Price beds in Rates").
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
- Back to No groups: unsaved groups hide and are not sent. With saved groups the toggle is disabled, with the tooltip "Remove groups in Settings → Rooms".

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
 │    │  ℹ Not applied to rooms. Link it to extras in Charge items.                 │  │
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
 ℹ No-show and cancellation charges are free for now. Set them after go-live.
```

- Malaysia starts with the seeded SST and Tourism tax rows ("Your Malaysian taxes are ready. Turn on what you charge."). Other countries start with one empty row ("Add the taxes you charge guests.").
- Columns: **On** (`enabled`), **Name**, **Rate** (amount and a % or currency menu = `rate_type`), **In price** (**Added on top** or **Included** = `is_inclusive`), **Applies to rooms**, **Reg. no.** (`registration_number`).
- ⌄ opens row details: **Foreign guests only** ("not {country}") and **Collect at check-in**. A flat rate shows "per night" only when it applies to rooms. Otherwise the quantity comes from the charge item.
- The short code is not shown. It is derived from the name (VAT → `TAX_VAT`, a number added when taken) and editable in settings. To verify that `TaxRequest` accepts a derived code.
- Preset rows have no remove button. They are turned off instead, because they own system posting codes. Custom rows can be removed. The last row stays while no taxes exist.
- **Applies to rooms** saves through `UpdateRoomRevenueTaxes`, which links ROOM and every policy code that follows the Taxes tab. The header tooltip says "Also used for no-show, cancellation, early departure and late check-out charges."
- A tax that does not apply to rooms is allowed (for example a service charge on food only). Its row says "Not applied to rooms. Link it to extras in Charge items." Charge items list it in each item's tax menu.
- When no enabled tax applies to rooms, the example shows a warning: "Room nights have no tax. Tick **Applies to rooms** on a tax if they should."
- The example is a sample night of 100 in the property currency, or the cheapest Basic rate once prices exist. The server calculates it with `RoomRevenuePreview` for a local and a foreign guest, as on the settings Taxes tab. The browser never calculates tax.
- **We don't charge tax** greys out the table and turns every tax off. It is stored as an `onboarding_skips` row for Taxes. Unticking it removes the row.
- At phone width each row stacks into a card, with `[x] Applies to rooms` and ⌄ More options.
- Check: Done when at least one tax is enabled (anywhere), or **We don't charge tax** is ticked.
- Review shows "Applies to rooms: {taxes}" (or "Room nights: no tax") as info, and warns with **Fix →** when an enabled tax applies to no charge at all. Neither blocks go-live.

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
│ Finance:  ✓ Taxes · ● Payment methods                                │ stepper
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
3. Finance phase (4, 5).
4. Prices phase (6, 7, 8).
5. Check-in (9) and Team (10).
6. Review: the readiness list reads the checks.
7. [Test drive](06-test-drive.md).

## Lessons from wastays

- 12 sections in a strict chain, with taxes and room revenue before rooms, and team blocking taxes.
- `onboarding_staff_drafts`, `onboarding_corporate_drafts` and a jsonb `onboarding_submissions.snapshot`.
- Stored section states and `InvalidateDependentSections` bounced owners back.
- A 761-line controller, 36 services and about 2,450 lines of separate views.
- Kept: the phase bar, stepper, fixed footer and greyed-out locked sections.
