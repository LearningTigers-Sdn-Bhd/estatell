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

| #   | Phase      | Section                                                                                                                                                                                                                                                                                                   | Required | Locked until              |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ------------------------- |
| 1   | Property   | Basics: property type cards, name, country (prefilled Malaysia), address. Another country shows currency and time zone. Editable until another section starts, then **Change country** (room types and rooms kept). See [Changing country](../02-property/03-seed-property-defaults.md#changing-country). | Yes      | –                         |
| 2   | Rooms      | Room types: an editable table, sold by room only. See [Room types table](#room-types-table).                                                                                                                                                                                                              | Yes      | Basics                    |
| 3   |            | Rooms: `GenerateRooms` number patterns, no beds                                                                                                                                                                                                                                                           | Yes      | Room types                |
| 4   | Finance    | Taxes: Malaysia confirms the seeded presets and enters registration numbers. Other countries add their own taxes or tick **We don't charge tax**.                                                                                                                                                         | Yes      | Basics                    |
| 5   |            | Room revenue: taxes on the room charge                                                                                                                                                                                                                                                                    | Yes      | Taxes                     |
| 6   |            | Payment methods: toggle the seeded presets                                                                                                                                                                                                                                                                | Yes      | Basics                    |
| 7   | Prices     | Basic rate: weekday and weekend price per room-sold room type. `SetBasePrices` fills 365 days of rates. It does not create a plan.                                                                                                                                                                        | Yes      | Room types + Room revenue |
| 8   |            | Charge items                                                                                                                                                                                                                                                                                              | No       | Taxes                     |
| 9   |            | Discounts                                                                                                                                                                                                                                                                                                 | No       | Basic rate                |
| 10  | Check-in   | Check-in essentials: check-in and check-out times, ID requirement, terms preset preview                                                                                                                                                                                                                   | Yes      | Basics                    |
| 11  | Team       | Roles and staff: invite staff and choose roles                                                                                                                                                                                                                                                            | No       | Basics                    |
| 12  | Test drive | Practice mode. See [Test drive](06-test-drive.md).                                                                                                                                                                                                                                                        | No       | All required Done         |
| 13  | Review     | Readiness list and go live. See [Go live](03-go-live.md).                                                                                                                                                                                                                                                 | –        | All required Done         |

```
                    1 Basics*
   ┌──────────┬────────┼───────────┬────────────┐
   ▼          ▼        ▼           ▼            ▼
 2 Room     4 Taxes*  6 Payment  10 Check-in  11 Team
   types*   │     │     methods*                (opt)
   │  │     ▼     ▼
   ▼  │   5 Room  8 Charge
 3 Rooms* revenue*  items (opt)
      │     │
      ▼     ▼
     7 Basic rate* ──▶ 9 Discounts (opt)

 all * Done ──▶ 12 Test drive (opt) ──▶ 13 Review / Go live
```

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
│ Finance:  ✓ Taxes · ● Room revenue · ○ Payment methods               │ stepper
├──────────────────────────────────────────────────────────────────────┤
│ Room revenue                                              ~2 min     │
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

The only onboarding-specific table. It stores skips of optional steps.

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
