# Go live

## Purpose

Switch a property from setup to operation in one clear moment.

## Action

`GoLive`:

1. Checks the readiness list. Every required step must be Done.
2. Offers "Clear practice data" (reservations and folios created before go-live). Safe because no business date or document numbers exist yet.
3. Creates the first `business_dates` row (Open) on the chosen opening date.
4. Starts document numbering.
5. Enables notifications and the night audit.
6. Sets `status = Live` and `went_live_at`, or `PendingApproval` when `platform_settings.require_go_live_approval` is on.

## After go-live

- A short celebration (`canvas-confetti`).
- The dashboard shows the **Recommended checklist**: photos, packages and meals, discounts, companies and city ledger, custom roles, notification preferences, and the channel manager when its feature is active.

## Lessons from wastays

The lifecycle was setup, pending_review, ready_to_launch, live, with a training mode and a training reset job.
