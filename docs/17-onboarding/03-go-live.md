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
2. Creates the first `business_dates` row (Open) on the opening date: **today** in the property time zone. Only `ApproveGoLive` can pass an earlier date, which the admin sets for a property that asked to add past stays. The owner never picks a date.
3. Sets `status = Live` and `went_live_at`. Document numbering needs no start step (`NextDocumentNumber` creates a sequence row on first use). The night audit already runs only for Live properties (`DueAudits`). No notification gate exists yet: notifications are not switched on at go-live.
4. Runs `RefreshPendingTemporaryPasswords`: every staff and company user of the property with `must_change_password` who has never signed in gets a new temp password that expires 14 days after go-live. No password values are logged. Company users who already have a password elsewhere are not touched.

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
- Notifications: `GoLiveRequested` goes to every active admin and the active users of the property's partner, by mail and in-app. Platform users have no property, so it is a plain notification that skips the preference matrix (`GoLiveRecipients` picks the users). `GoLiveApproved` and `GoLiveRejected` are `NotificationType` cases for the owner (in-app mandatory, mail can be switched off) and show in the owner's notification matrix.

### Owner side

- **Waiting for approval** banner on Review: sent date, "We usually review within 1 working day. We'll email you when you're live.", and **Withdraw to make changes** (AlertDialog, then `WithdrawGoLiveRequest`).
- While pending, every section opens as a read-only preview ("Waiting for approval. Withdraw to make changes.").
- **Sent back**: Review shows the admin's note ("Not approved yet. Reviewed by estatell support on {date}."). Flagged sections show ⚑ "Flagged by support" in the phase bar, the stepper and Review, with **Fix →**, until the next submit. Flags do not lock anything.
- **Approved**: the email "{property} is live" links to the **You're live!** screen, shown once on the next visit.

### Admin side

- The admin sidebar **Properties** item shows a count badge of pending requests. The properties list gets a **Waiting for approval** status tab (property, partner, sent, warnings count, **Review**). The admin property page shows a **Waiting for approval** banner with **Review**. The onboarding board gets the same tab when it is built.
- Review page `GET /admin/properties/{code}/go-live` (admin layout): who sent it and when, **View as**, and the Review rows read-only with more detail: Basics (registered name, registration number, TIN, licences), Taxes (rate and registration number) and Basic rate (weekday and weekend price per room type). Each row has a tick to flag it for the owner. Warnings show as a list without **Fix →**. The page redirects to the property when nothing is pending.
- **Approve** opens an AlertDialog: opening date (today by default, or earlier, with "Only change this if the owner asked to add stays from before today."), and what happens (business date, sign-in details count, owner emailed).
- **Send back** opens an AlertDialog with a required note and the flagged sections.

## Build order

`GoLive` was built before the wizard, because the night audit needs an open business date. It creates the business date through `OpenBusinessDate` and sets `Live`. `GoLiveReadiness` reads the required wizard steps in [Wizard steps](02-wizard-steps.md), and every section is now built.

## Build status

| Part                                                                                                               | Status                                                                          |
| ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------- |
| `GoLive`, `GoLiveReadiness`, `EnsurePropertyIsLive`, the Review page (rows, warnings, sign-in info line)           | Built.                                                                          |
| Opening date: today in the property time zone. The owner field is gone                                             | Built. `ApproveGoLive` takes an earlier date, never a later one.                |
| `PendingApproval` when `require_go_live_approval` is on. Nothing opens and `went_live_at` stays empty              | Built. `RequestGoLive` opens nothing; the go-live steps run at `ApproveGoLive`. |
| `RefreshPendingTemporaryPasswords` at go-live                                                                      | Built for staff. Company users join when the company portal users are built.    |
| The You're live card, confetti, Recommended checklist                                                              | Built.                                                                          |
| Notifications switch-on at go-live                                                                                 | Not built. No notification gate exists yet.                                     |
| Go-live approval (`RequestGoLive`, `ApproveGoLive`, `RejectGoLive`, `WithdrawGoLiveRequest`, flags, admin screens) | Built.                                                                          |

Document numbering needs no start step. `NextDocumentNumber` creates a sequence row on first use.

`PropertyFactory` makes a Live property by default, because operational routes need `Live`. Use `->onboarding()` for a property that is still in setup.

## After go-live

Going live redirects to the dashboard with a `wentLive` flash. There is no separate go-live page: onboarding URLs redirect a Live property to the dashboard, and a property approved by an admin reaches the same cards on the owner's next visit.

### You're live card

```
 ┌─ You're live! ─────────────────────────────────────────────────────────────────────┐
 │ Seaview Lodge is open. Your first business day is Fri 16 Oct.                      │
 │                                                                                    │
 │ Share sign-in details                                                              │
 │   Team (3)    Not shared yet        [Copy messages]  [Export slips ▾ PDF · CSV]    │
 │   Passwords expire on Fri 30 Oct. You can reset them in Settings, under Staff.     │
 └────────────────────────────────────────────────────────────────────────────────────┘
```

- Shown at the top of the dashboard to users with `staff.manage` while a pending staff user has no shared time. Pending means the user still has `must_change_password` and is not deactivated (`PendingSignInUsers`).
- **Copy messages** and **Export slips** call the existing staff temp-password routes (`settings.staff.temp-passwords.message`, `.export`), which use `TemporaryPasswordMessage` and `TemporaryPasswordFile`.
- `users.temporary_password_shared_at` (nullable timestamp) is set for each user in a copy or an export, and cleared when a temp password is set again (reset, or the refresh at go-live). This applies everywhere, not only on this card.
- After sharing, the row reads "✓ Team (3) Shared 16 Oct" with **Copy again**. The card is gone on the next visit once every pending user has a shared time.
- With no pending staff, the card shows only the first line, once (on the `wentLive` flash).
- A short celebration (`canvas-confetti`) runs once, on the `wentLive` flash, never on later loads.
- Company users join this card with [Company portal](../23-company-portal/01-company-portal.md).
- At phone width the rows stack and the buttons go full width.

### Recommended checklist

```
 ┌─ Recommended next ─────────────────────────────────────── 1 of 6 done ─┐
 │ ○ Property logo                            [Add →]                     │
 │   No logo on invoices and receipts                                     │
 │ ○ No-show and cancellation charges         [Set up →]   [Not needed]   │
 │   All policies are Free                                                │
 │ ○ Missed arrivals and overdue departures   [Review →]   [Not needed]   │
 │   Using the defaults                                                   │
 │ ○ Skipped sections                         [Open →]     [Not needed]   │
 │   Charge items, Discounts                                              │
 │ ○ Payment surcharges                       [Set up →]   [Not needed]   │
 │   No surcharge on any payment method                                   │
 │ ✓ Room groups                                                          │
 │   Sea view, Garden                                                     │
 └────────────────────────────────────────────────────────────────────────┘
```

| Item                                   | Done when                                                                                            | Opens                        | Not needed       |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------- | ---------------------------- | ---------------- |
| Property logo                          | The property has a logo (`Property::LOGO` media)                                                     | Settings → Logo              | No               |
| No-show and cancellation charges       | A room revenue policy has a charge mode other than Free                                              | Settings → Room revenue      | Yes              |
| Missed arrivals and overdue departures | Only by Not needed: the defaults are valid, the owner confirms them                                  | Settings → Operations        | Yes              |
| Skipped sections                       | One line per skipped optional section (`onboarding_skips`); a line is done when the section has data | That section's settings page | Yes, per section |
| Payment surcharges                     | A payment method has a surcharge other than None                                                     | Settings → Payment methods   | Yes              |
| Room groups                            | The property has a room group                                                                        | Settings → Room groups       | Yes              |

- A read-only Service builds the items from the property's data. Each item has a key, a label, a one-line summary, a done flag and a URL.
- **Not needed** stores a row in `recommended_item_dismissals (property_id, item, dismissed_by, dismissed_at)`, unique on `(property_id, item)`. A skipped section uses the item key `skipped:{step}`.
- Shown on the dashboard to users with `settings.manage` while any item is open. The card is gone when every item is done or dismissed. There is no hide for the whole card.
- The checklist sits inside the dashboard bento, below To do. Its heading uses the `Sparkles` icon.
- It starts collapsed, with progress and the first incomplete item. **Show all** expands the remaining items inside the cell.
- The browser remembers expansion per property. Item order and all actions stay unchanged.
- Not on the list: photos (no feature yet), meal periods and packages, discounts (an onboarding section), custom roles, notification preferences, and company rates (later, with company users). The channel connection joins the list when it is built ([Channel connections](../21-channels/01-connections-and-feature-gate.md)).

## Lessons from wastays

The lifecycle was setup, pending_review, ready_to_launch, live, with a training mode and a training reset job. estatell has no practice mode. Owners try the product on the shared [demo properties](../01-platform/06-demo-properties.md), and the approval setting is the check before go-live.
