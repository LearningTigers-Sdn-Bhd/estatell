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
- Reminders after 24 and 72 hours without progress (see [Reminders](#reminders)).

## Reminders

`OnboardingProgress` is derived and stores nothing, so "progress" needs a timestamp.

| Part              | Design                                                                                                                                                                                                                                                 |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Activity          | `properties.onboarding_last_activity_at`. One middleware sets it on every non-GET onboarding request (to verify against the route files). It also sets `onboarding_reminder_stage` back to 0.                                                          |
| Schedule          | `onboarding:send-reminders`, hourly, `onOneServer`. It reads properties in `Onboarding`, using `onboarding_last_activity_at`, or `onboarding_started_at` when empty.                                                                                   |
| Stages            | Stage 1 after 24 hours, stage 2 after 72 hours. Each stage is sent once, then `onboarding_reminder_stage` moves up. A property idle for more than 72 hours gets only stage 2. After stage 2 nothing more is sent until the owner makes progress again. |
| Skipped when      | A go-live request is pending (the platform is the one waiting), the property is suspended, or the owner turned the reminder off in their notification preferences.                                                                                     |
| Notification      | `OnboardingReminder` (`NotificationType`, mail and in-app, opt-out for both). It goes to the property's Owner and names the next unfinished step with a link to it.                                                                                    |
| Owner not started | An owner who never set a password gets the setup link through `SendOwnerSetupLink` instead of the plain link.                                                                                                                                          |

## Admin board

Lists every onboarding property with current step, completion percentage and last activity (`onboarding_last_activity_at`). Admins can offer help to stuck properties. Partners see the same board on their Properties page, limited to their own properties (see [Partners](05-partners.md#partner-portal)). Each row has a **[View as]** button (see [Impersonation](../01-platform/04-impersonation.md)), which pre-selects the "Onboarding help" reason. The board also gets the **Waiting for approval** tab (see [Approval](03-go-live.md#approval)).

## Analytics

Step completed and skipped events are logged with `spatie/laravel-activitylog`.

## Lessons from wastays

`onboarding_audit_events` stored metadata in jsonb.
