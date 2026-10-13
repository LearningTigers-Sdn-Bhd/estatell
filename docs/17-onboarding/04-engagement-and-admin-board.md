# Engagement and admin board

## Purpose

Make owners finish onboarding, and let the platform see who is stuck.

## Engagement

- Time estimates per step, and "About 15 minutes" up front.
- Autosave and "resume where you left off" on every login.
- Live preview panel: a mini room grid, a sample quote and a sample registration card update as the owner types.
- Milestone messages: "12 rooms ready", "Prices set for a year", "You can take your first booking".
- "What this unlocks" labels, for example "Finish Prices to unlock bookings".
- One sentence of help and an example next to each field.
- `OnboardingReminder` notification after 24 and 72 hours without progress (opt-out allowed).

## Admin board

Lists every onboarding property with current step, completion percentage and last activity. Admins can offer help to stuck properties. Partners see the same board on their Properties page, limited to their own properties (see [Partners](05-partners.md#partner-portal)). Each row has a **[View as]** button (see [Impersonation](../01-platform/04-impersonation.md)), which pre-selects the "Onboarding help" reason.

## Analytics

Step completed and skipped events are logged with `spatie/laravel-activitylog`.

## Lessons from wastays

`onboarding_audit_events` stored metadata in jsonb.
