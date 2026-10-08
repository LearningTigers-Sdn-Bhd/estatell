# Audit run

## Purpose

Close the business day in about 1-2 minutes, unattended if needed.

## Data model

```
night_audits             id, property_id, business_date_id (unique), trigger (AuditTrigger),
                         status (AuditStatus), started_at, completed_at (set only when Completed),
                         run_by (nullable), forced, force_reason (required when forced), timestamps
night_audit_steps        id, property_id, night_audit_id, step (AuditStep), status (AuditStepStatus),
                         processed_count, failed_count, started_at, finished_at, error_message,
                         timestamps. Unique (night_audit_id, step)
night_audit_exceptions   id, property_id, night_audit_id, step (AuditStep), reservation_unit_id (nullable),
                         type (AuditExceptionType), message, resolved_at, resolved_by, timestamps
```

A business date has at most one audit. A Failed audit is resumed on the same row, never replaced.

## Enums

| Enum                 | Cases                                                                                             |
| -------------------- | ------------------------------------------------------------------------------------------------- |
| `AuditTrigger`       | Scheduled, Manual                                                                                 |
| `AuditStatus`        | Running, Completed, Failed                                                                        |
| `AuditStepStatus`    | Running, Completed, Failed                                                                        |
| `AuditStep`          | The steps below, in run order. `runner()` gives the class of a step, and `runnable()` lists them. |
| `AuditExceptionType` | MissedArrival, OverdueDeparture, RoomCharge, AddOn, UnexpectedError                               |

The exception `message` is the refusal code of the Action that refused the unit (for example `folio_not_open`), the code of the audit's own refusal (below), or the error text for `UnexpectedError`. The readiness panel turns a code into a sentence.

## Principles

1. Resolve by policy instead of blocking.
2. Idempotent: running twice gives the same ledger.
3. Resumable: completed steps are skipped on rerun.
4. Failures are isolated per unit as exception rows.
5. Closed dates are immutable.

## Run

`RunNightAudit` (`app/Actions/NightAudit/`) takes the property, the trigger, an optional actor and an optional force with a reason. It returns a `NightAuditResult`: completed, failed, or a refusal.

| Refusal            | When                                                                            |
| ------------------ | ------------------------------------------------------------------------------- |
| `reason_required`  | A forced run without a reason.                                                  |
| `no_business_date` | The property has no current business date (not live yet).                       |
| `already_running`  | The date is Auditing and its audit is Running, or another run claimed it first. |

A refusal writes nothing. Running the audit again after a completed run audits the next date, because the next date is now the current one.

A step that throws is recorded as Failed with its error, the audit becomes Failed, the date stays Auditing and the run stops. The next run claims the Failed audit again and starts at the first step that is not Completed. A step is the unit of resume: when a step restarts, its exception rows from the earlier try are deleted first, and every Action a step calls is idempotent, so a half-done step posts nothing twice.

An error inside one unit never stops a step. The step records an exception row (`UnexpectedError` for an error, the step's own type for a refusal) and goes on.

## Steps

Each step is an `AuditStepRunner` class in `app/Actions/NightAudit/Steps/` that returns a `StepOutcome` (processed and failed counts). `RunNightAudit` writes its `night_audit_steps` row. A step has no actor: the audit is the system, and the user who started it is `night_audits.run_by`.

1. `ClaimBusinessDate`: atomic Open > Auditing, with the property row locked, and creates the audit (or takes back a Failed one). Refuses if not claimed. It has no runner class, because it creates the audit the others run under. Its step row is Completed at once.
2. `ResolveMissedArrivals`: applies `missed_arrival_policy`. With Mark no-show, every Confirmed unit with an arrival date on or before the business date and `late_arrival_expected` off goes through `MarkNoShow` (the no-show fee, the rooms released). With Keep as late arrival it does nothing. A refusal becomes a `MissedArrival` exception.
3. `ResolveOverdueDepartures`: applies `overdue_departure_policy` to every CheckedIn unit that departs on or before the business date. With Extend one night, a unit that departs on the business date goes through `ExtendStay` to the next day, so the night of the business date posts in step 4. A unit that departed earlier is never extended: the nights between could not post on a closed date, so it becomes an exception (`departure_date_passed`). With Block, no unit is extended and each becomes an exception (`overdue_departure_blocked`). A refusal of `ExtendStay` (no free room, a restriction, no rate) becomes an `OverdueDeparture` exception.
4. `PostRoomChargesStep`: one `PostRoomChargesJob` per CheckedIn unit that has a night on the business date, in a `Bus::batch` (see [Posting charges](../13-folio/02-posting-charges.md#room-charge-pipeline-per-unit-night)). The job calls `PostRoomCharges` with a null actor and turns a refusal into a `RoomCharge` exception. The batch runs on the `sync` connection, so the step ends when the batch ends and step 5 never starts early. The batch row in `job_batches` gives progress and the job count. Moving the jobs to a queue connection later needs a continue callback that resumes the audit, which the resumable design allows.
5. `PostScheduledAddOnsStep`: calls `PostScheduledAddOns` for the business date. Each refused add-on becomes an `AddOn` exception on its unit.
6. `UpdateRoomStatuses`: the room of every CheckedIn unit that sleeps on the night of the business date becomes Dirty (a bed gives its room). A room that departed is already Dirty from check-out, so the step does not touch it. A room that is already Dirty is left alone.
7. `SnapshotStatisticsStep`: calls `SnapshotStatistics`, which writes `daily_statistics` and `daily_revenue` for the business date (see [Statistics](05-statistics.md)). A throw fails the audit, and the next run resumes here.
8. `CloseAndOpenNext`: in one transaction, the date becomes Closed (with `closed_by` set to `run_by`), the next calendar day opens as the new Open date, and the audit becomes Completed. It throws if the date is not Auditing. `RunNightAudit` then logs `night-audit.completed` and queues `NotifyNightAuditResultJob` (see [Statistics](05-statistics.md#morning-handoff)). It does the same after it logs `night-audit.failed`. The report packet PDF comes with reports.

A failed run logs `night-audit.failed` with the step and the error.

## Force close

Requires `night-audit.force` and a reason. Sets `forced = true`. Open exceptions carry to the next day's readiness panel. `RunNightAudit` stores the flag and the reason and refuses a forced run without one. The route checks the permission. A force overrides the blockers of the [readiness panel](02-readiness-panel.md).

## Permissions

`night-audit.run`, `night-audit.force`, `night-audit.receive-report`. All three go to Owner and Manager, with a syncing migration. `night-audit.run` opens the sheet and runs the audit, and `night-audit.force` allows a forced run.

## Job

`RunNightAuditJob` (`app/Jobs/`) runs `RunNightAudit` on the queue. It is `ShouldBeUnique` by property and uses `WithoutOverlapping`, and it does not retry: a failed audit is resumed by the next run. The sheet queues it for a manual run, and the [schedule](04-scheduling.md) queues it with the Scheduled trigger. `PostRoomChargesJob` lives in `app/Jobs/` too.

## Build status

| Part                                                                                         | Status                                                                                                                                                                         |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Tables, enums, models, `RunNightAudit`, `ClaimBusinessDate`, steps 2 to 6 and 8, permissions | Built.                                                                                                                                                                         |
| `SnapshotStatistics`                                                                         | Built. See [Statistics](05-statistics.md).                                                                                                                                     |
| Notifications                                                                                | Built. See [Statistics](05-statistics.md#morning-handoff). The packet PDF is built: see [Core reports](../22-reports/01-core-reports.md#built-night-audit-history-and-packet). |
| Routes, the page and the one-click fixes                                                     | [Readiness panel](02-readiness-panel.md).                                                                                                                                      |
| `RunNightAuditJob` and the schedule                                                          | Built. See [Scheduling](04-scheduling.md).                                                                                                                                     |

## Lessons from wastays

About 3,900 lines across about 40 services, with jsonb `blocked_details`, `exceptions`, `summary`, `changelog` and `blockers_snapshot`.
