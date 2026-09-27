# Wizard steps

## Purpose

Get a property from sign-up to its first booking in about 15 minutes, without confusion.

## Principles

1. Ask in the owner's order: place, rooms, prices, getting paid, check-in, team.
2. Confirm smart defaults instead of building from scratch.
3. Write to the real tables through the real Actions. No draft tables.
4. Progress is calculated from real data. No stored section states.
5. Only five steps are required.
6. Each step renders the **same form component** used in settings, inside a wizard layout (Inertia 3 `useLayoutProps`).

## Steps

| Step                        | Required | Content                                                                                                          |
| --------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------- |
| Basics (2 min)              | Yes      | Property type cards, name, address. Country sets currency, time zone, locale, tax presets.                       |
| Rooms (4 min)               | Yes      | Suggested room types, `GenerateRooms` number patterns, beds for dorms.                                           |
| Prices (3 min)              | Yes      | Base price per room type, optional weekend price. `SetBasePrices` creates a Standard plan and 365 days of rates. |
| Payments and taxes (2 min)  | Yes      | Toggle preset payment methods and taxes, enter tax registration numbers.                                         |
| Check-in essentials (2 min) | Yes      | Check-in and check-out times, ID requirement, terms preset preview.                                              |
| Team                        | Optional | Invite staff and choose roles.                                                                                   |
| Test drive                  | Optional | Guided practice booking: create, check in, post a charge, check out. Coach marks use shadcn `popover`.           |

Steps can be done in any order after Basics. Prices depends on Rooms.

## Data model

```
onboarding_skips    property_id, step (OnboardingStep), skipped_by, skipped_at
```

The only onboarding-specific table. It stores skips of optional steps.

## Enums and services

- `OnboardingStep` enum with `isRequired()`, `dependsOn()`, `estimatedMinutes()`, `check()`.
- One `StepCheck` class per step returns NotStarted, InProgress or Done from real data. Example: `PricesCheck` = every room type has rates for the next 30 days.
- `OnboardingProgress` combines checks for the sidebar, readiness list and admin board.

## Middleware

`EnsurePropertyIsLive`: operational routes (front desk, night audit, finance, channels) redirect to the wizard until the property is Live. Settings and Test drive stay open.

## Lessons from wastays

- 12 sections in a strict chain, with taxes and room revenue before rooms.
- `onboarding_staff_drafts`, `onboarding_corporate_drafts` and a jsonb `onboarding_submissions.snapshot`.
- Stored section states and `InvalidateDependentSections` bounced owners back.
- A 761-line controller, 36 services and about 2,450 lines of separate views.
