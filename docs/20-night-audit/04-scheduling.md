# Audit scheduling

## Purpose

Run the audit automatically at each property's local audit time.

## Design

- `routes/console.php` runs `night-audit:dispatch-scheduled` (`DispatchScheduledAudits`) every five minutes, with `withoutOverlapping()` and `onOneServer()`.
- `DueAudits` (`app/Services/NightAudit/`) finds Live properties where `auto_audit_enabled` is on, the business date is Open, and local time (property `time_zone`) is at or past `audit_time` **on the day after that date**. So an audit time of 23:00 still runs on the next day. `DueAudits::dueAt()` gives that moment for one date, or null when the automatic audit is off. The [sheet](02-readiness-panel.md#the-sheet) and the run route's refusal use it to say when the date closes.
- The command dispatches `RunNightAuditJob` per property with the Scheduled trigger, no actor and no force. The job is built (see [Audit run](03-audit-run.md#job)). A blocker in the [readiness panel](02-readiness-panel.md#the-gate) stops the run. A refused run writes nothing, so the next five-minute run tries again until staff fix the blocker.
- A Failed audit leaves the date Auditing, so the schedule does not pick it up. Staff resume it from the sheet.
- The job implements `ShouldBeUnique` (unique by property) and uses `WithoutOverlapping`.

## Why not per-property timezone schedules

Laravel docs warn that timezone schedules can run twice or not at all around daylight saving changes. Checking local time every five minutes avoids that.

## Rules

- Runs only for Live properties.
- Manual "Run audit now" uses the same Action.
- Unique job locks do not apply inside batches. `ClaimBusinessDate` protects the run.
