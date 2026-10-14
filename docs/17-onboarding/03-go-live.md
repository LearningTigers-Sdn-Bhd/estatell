# Go live

## Purpose

Switch a property from setup to operation in one clear moment.

## Actions

| Action                  | When                                                                   | Does                                                                                      |
| ----------------------- | ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `GoLive`                | The owner clicks **Go live** (approval off), or inside `ApproveGoLive` | The go-live steps below                                                                   |
| `RequestGoLive`         | The owner clicks **Send for approval** (approval on)                   | Checks readiness, sets `PendingApproval`, creates a `go_live_reviews` row. Opens nothing. |
| `ApproveGoLive`         | An admin approves                                                      | Records the decision and runs `GoLive` with the opening date the admin confirms           |
| `RejectGoLive`          | An admin sends it back                                                 | Back to `Onboarding`, with the required note and the flagged sections                     |
| `WithdrawGoLiveRequest` | The owner withdraws while pending                                      | Back to `Onboarding`, no note                                                             |

**Go-live steps** (`GoLive`, one transaction):

1. Checks the readiness list. Every required step must be Done.
2. Runs `ClearPracticeData` (see [Test drive](06-test-drive.md#clear-practice-data)). It deletes practice reservations, folios, payments, registration cards, document sequences and the practice business date.
3. Creates the first real `business_dates` row (Open) on the opening date: **today** in the property time zone. Only `ApproveGoLive` can pass an earlier date, which the admin sets for a property that asked to add past stays. The owner never picks a date.
4. Starts document numbering.
5. Enables notifications and the night audit.
6. Sets `status = Live` and `went_live_at`.
7. Runs `RefreshPendingTemporaryPasswords`: every staff and company user of the property with `must_change_password` who has never signed in gets a new temp password that expires 14 days after go-live. No password values are logged. Company users who already have a password elsewhere are not touched.

Nothing operational happens at `RequestGoLive`: a pending property has no business date.

## Approval

`platform_settings.require_go_live_approval` decides the owner's button: **Go live** (off) or **Send for approval** (on). Switching it off leaves pending requests pending until an admin acts. Switching it on affects the next click only.

```
go_live_reviews        id, property_id, submitted_by, submitted_at,
                       decision (GoLiveDecision, nullable), note (nullable),
                       decided_by (nullable), decided_at (nullable),
                       opening_date (nullable), timestamps
go_live_review_flags   go_live_review_id, step (OnboardingStep)
                       unique (go_live_review_id, step)
```

- `GoLiveDecision`: Approved, Rejected, Withdrawn. A pending request has no decision. At most one pending row per property (partial unique index).
- Each submit is a new row, so a property sent back twice keeps both reviews.
- `opening_date` is set on approval. A note is required for Rejected.
- Only admins approve or send back, with `support.2fa`. Partners see their pending properties read-only.
- Notifications: `GoLiveRequested` to admins (and the partner for its properties), `GoLiveApproved` and `GoLiveRejected` to the owner. To verify that the notification module can address admins, who are not property staff.

### Owner side

- **Waiting for approval** banner on Review: sent date, "We usually review within 1 working day. We'll email you when you're live.", **Start test drive →** and **Withdraw to make changes** (AlertDialog, then `WithdrawGoLiveRequest`).
- While pending, every section opens as a read-only preview ("Waiting for approval. Withdraw to make changes."). Test drive stays open.
- **Sent back**: Review shows the admin's note ("Not approved yet. Reviewed by estatell support on {date}."). Flagged sections show ⚑ "Flagged by support" in the phase bar, the stepper and Review, with **Fix →**, until the next submit. Flags do not lock anything.
- **Approved**: the email "{property} is live" links to the **You're live!** screen, shown once on the next visit.

### Admin side

- The admin sidebar **Properties** item shows a count badge of pending requests. The properties list gets a **Waiting for approval** status tab (property, partner, sent, warnings count, **Review**). The admin property page shows a **Waiting for approval** banner with **Review**. The onboarding board gets the same tab when it is built.
- Review page `GET /admin/properties/{code}/go-live` (admin layout): who sent it and when, **View as**, and the Review rows read-only with more detail (registration numbers, licences, tax rates and registration numbers, prices). Each row has a tick to flag it for the owner. Warnings show as on the owner side.
- **Approve** opens an AlertDialog: opening date (today by default, or earlier, with "Only change this if the owner asked to add stays from before today."), and what happens (business date, practice data counts, sign-in details count, owner emailed).
- **Send back** opens an AlertDialog with a required note and the flagged sections.

## Build order

`GoLive` is built before the wizard, because the night audit needs an open business date. The first version creates the business date through `OpenBusinessDate`, starts document numbering and sets `Live`. The readiness check reads the wizard steps, so it starts empty and fills as each step lands in [Wizard steps](02-wizard-steps.md).

## Build status

| Part                                                                                                            | Status                                                                                                       |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `GoLive`, `GoLiveReadiness` (empty list), `EnsurePropertyIsLive`, the Go live page and a link on Basics         | Built.                                                                                                       |
| Opening date: today or earlier in the property time zone, so a stay month can be back-filled                    | Built. To change: the owner field goes; `GoLive` uses today, and only `ApproveGoLive` takes an earlier date. |
| `PendingApproval` when `require_go_live_approval` is on. The business date opens and `went_live_at` stays empty | Built. To change: `RequestGoLive` opens nothing; the go-live steps run at `ApproveGoLive`.                   |
| `ClearPracticeData`, notifications and night audit switch-on, confetti, Recommended checklist                   | Not built. They come with the wizard steps, Test drive and the later night audit items.                      |

Document numbering needs no start step. `NextDocumentNumber` creates a sequence row on first use.

`PropertyFactory` makes a Live property by default, because operational routes need `Live`. Use `->onboarding()` for a property that is still in setup.

## After go-live

- The go-live screen shows **Share sign-in details**:

```
 ┌─ You're live! ───────────────────────────────────────────────────┐
 │ Share sign-in details                                            │
 │   Team (3)            [Copy messages]  [Export slips ▾ PDF·CSV]  │
 │   Company users (2)   [Copy messages]  [Export slips ▾ PDF·CSV]  │
 │ Passwords expire on 29 Oct. You can reset them in Settings.      │
 └──────────────────────────────────────────────────────────────────┘
```

- It reuses the temp password copy and export (`TemporaryPasswordMessage`, `TemporaryPasswordFile`). The Recommended checklist keeps "Share sign-in details with {n} staff and {n} company users" until each pending user's details were copied or exported. After that the Staff page and the company Portal users tab work as today.

- A short celebration (`canvas-confetti`).
- Onboarding URLs redirect to the dashboard. Setup data is edited in settings.
- The dashboard shows the **Recommended checklist**: no-show and cancellation charges (Room revenue policy tabs), a card fee (payment surcharges), room groups, photos, packages and meals, discounts, negotiated company rates, custom roles, notification preferences, and the channel manager connection when its feature is active (OTA logins given in onboarding are already on file).

## Lessons from wastays

The lifecycle was setup, pending_review, ready_to_launch, live, with a training mode and a training reset job. Practice mode keeps the idea with one shared `ClearPracticeData` and a guard test.
