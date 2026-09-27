# Audit run

## Purpose

Close the business day in about 1-2 minutes, unattended if needed.

## Data model

```
night_audits             id, business_date_id, trigger (AuditTrigger), status (AuditStatus),
                         started_at, completed_at, run_by (nullable), forced, force_reason
night_audit_steps        id, night_audit_id, step (AuditStep), status, processed_count,
                         failed_count, started_at, finished_at, error_message
night_audit_exceptions   id, night_audit_id, step, reservation_unit_id (nullable),
                         type (AuditExceptionType), message, resolved_at, resolved_by
```

## Principles

1. Resolve by policy instead of blocking.
2. Idempotent: running twice gives the same ledger.
3. Resumable: completed steps are skipped on rerun.
4. Failures are isolated per unit as exception rows.
5. Closed dates are immutable.

## Steps

`RunNightAudit` runs an ordered list of `AuditStep` classes. Each records a `night_audit_steps` row.

1. `ClaimBusinessDate`: atomic Open > Auditing. Exits if not claimed.
2. `ResolveMissedArrivals`: applies `missed_arrival_policy` (skips `late_arrival_expected`).
3. `ResolveOverdueDepartures`: applies `overdue_departure_policy`.
4. `PostRoomCharges`: per unit night, as a `Bus::batch` of per-unit jobs (see [Posting charges](../13-folio/02-posting-charges.md)).
5. `PostScheduledAddOns`: add-ons with this `service_date`.
6. `UpdateRoomStatuses`: occupied and departed rooms become Dirty.
7. `SnapshotStatistics`: see [Statistics](05-statistics.md).
8. `CloseAndOpenNext`: Closed, next date Open, dispatch reports and notifications.

## Force close

Requires `night-audit.force` and a reason. Sets `forced = true`. Open exceptions carry to the next day's readiness panel.

## Permissions

`night-audit.run`, `night-audit.force`, `night-audit.receive-report`.

## Lessons from wastays

About 3,900 lines across about 40 services, with jsonb `blocked_details`, `exceptions`, `summary`, `changelog` and `blockers_snapshot`.
