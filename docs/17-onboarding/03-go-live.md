# Go live

## Purpose

Switch a property from setup to operation in one clear moment.

## Action

`GoLive`:

1. Checks the readiness list. Every required step must be Done.
2. Always runs `ClearPracticeData` (see [Test drive](06-test-drive.md#clear-practice-data)). It deletes practice reservations, folios, payments, registration cards, document sequences and the practice business date. Safe because no real business date or document numbers exist yet.
3. Creates the first real `business_dates` row (Open) on the chosen opening date.
4. Starts document numbering.
5. Enables notifications and the night audit.
6. Sets `status = Live` and `went_live_at`, or `PendingApproval` when `platform_settings.require_go_live_approval` is on.

## Build order

`GoLive` is built before the wizard, because the night audit needs an open business date. The first version creates the business date through `OpenBusinessDate`, starts document numbering and sets `Live`. The readiness check reads the wizard steps, so it starts empty and fills as each step lands in [Wizard steps](02-wizard-steps.md).

## Build status

| Part                                                                                                            | Status                                                                                  |
| --------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `GoLive`, `GoLiveReadiness` (empty list), `EnsurePropertyIsLive`, the Go live page and a link on Basics         | Built.                                                                                  |
| Opening date: today or earlier in the property time zone, so a stay month can be back-filled                    | Built.                                                                                  |
| `PendingApproval` when `require_go_live_approval` is on. The business date opens and `went_live_at` stays empty | Built. The admin step that approves it and sets `Live` and `went_live_at` is not built. |
| `ClearPracticeData`, notifications and night audit switch-on, confetti, Recommended checklist                   | Not built. They come with the wizard steps, Test drive and the later night audit items. |

Document numbering needs no start step. `NextDocumentNumber` creates a sequence row on first use.

`PropertyFactory` makes a Live property by default, because operational routes need `Live`. Use `->onboarding()` for a property that is still in setup.

## After go-live

- A short celebration (`canvas-confetti`).
- Onboarding URLs redirect to the dashboard. Setup data is edited in settings.
- The dashboard shows the **Recommended checklist**: no-show and cancellation charges (Room revenue policy tabs), room groups, photos, packages and meals, discounts, companies and city ledger, custom roles, notification preferences, and the channel manager when its feature is active.

## Lessons from wastays

The lifecycle was setup, pending_review, ready_to_launch, live, with a training mode and a training reset job. Practice mode keeps the idea with one shared `ClearPracticeData` and a guard test.
