# Audit scheduling

## Purpose

Run the audit automatically at each property's local audit time.

## Design

- A scheduler entry runs `->everyFiveMinutes()`.
- It finds properties where `auto_audit_enabled` is on, local time (property `time_zone`) is past `audit_time`, and the business date is still Open.
- It dispatches `RunNightAuditJob` per property.
- The job implements `ShouldBeUnique` (unique by property) and uses `WithoutOverlapping`.

## Why not per-property timezone schedules

Laravel docs warn that timezone schedules can run twice or not at all around daylight saving changes. Checking local time every five minutes avoids that.

## Rules

- Runs only for Live properties.
- Manual "Run audit now" uses the same Action.
- Unique job locks do not apply inside batches. `ClaimBusinessDate` protects the run.
