# Statistics and morning handoff

## Data model

```
daily_statistics    property_id, business_date, rooms_available, rooms_sold,
                    beds_available, beds_sold, occupancy, adr, revpar,
                    arrivals, departures, no_shows, in_house_guests
daily_revenue       property_id, business_date, transaction_code_id, amount, line_count
```

Both tables are unique on `(property_id, business_date)` (and the code for `daily_revenue`).

## Rules

- Filled once by `SnapshotStatistics` (`app/Actions/NightAudit/`), which the step runner `SnapshotStatisticsStep` calls as step 7 of the [audit run](03-audit-run.md#steps) (`AuditStep::SnapshotStatistics`), from `folio_lines` by posting date.
- `daily_revenue` is a copy of `DailyRevenueQuery` (`app/Services/Reports/`) for one closed date. The live [daily revenue report](../22-reports/01-core-reports.md) reads the same query for any range, including the open date, so a property can see revenue before the audit runs. A test checks that the stored rows equal the query.
- The step is idempotent: it upserts on the unique keys and removes a revenue row whose code no longer has lines, so a resumed run writes the same rows.
- A snapshot that throws fails the audit. The date stays Auditing and the next run resumes at this step.
- Closed dates are immutable, so snapshots never need recalculation.

## Calculation

`StatisticsCalculator` (`app/Services/NightAudit/`) reads and returns the numbers. It writes nothing.

| Field                         | Rule                                                                                                                                                                 |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `rooms_available`             | Active rooms of Room room types on the date, not counting a room in an open room block. A dorm room counts in the bed fields only.                                   |
| `rooms_sold`                  | CheckedIn units that sleep on the night of the date, one per room.                                                                                                   |
| `beds_available`, `beds_sold` | The same two rules for beds.                                                                                                                                         |
| `occupancy`                   | (`rooms_sold` + `beds_sold`) / (`rooms_available` + `beds_available`), as a percent. A room or a bed is one unit. Zero when nothing is available.                    |
| `adr`                         | Room revenue / (`rooms_sold` + `beds_sold`). Room revenue is the room revenue code plus the discount lines under its charges, net of tax. Zero when nothing is sold. |
| `revpar`                      | Room revenue / (`rooms_available` + `beds_available`). Zero when nothing is available.                                                                               |
| `arrivals`, `departures`      | Units checked in and checked out on the business date, from the `reservation.unit_checked_in` and `reservation.unit_checked_out` events, one for each unit.          |
| `no_shows`                    | Units marked no-show on the business date, from the `reservation.unit_no_show` event.                                                                                |
| `in_house_guests`             | The adults and children of the CheckedIn units at the close.                                                                                                         |

`daily_revenue` has one row for each transaction code with lines on the date: the amount and the line count. Charges, taxes and adjustments count, payment lines do not. A line counts on its posting date, so a reversal reduces the date it was posted on. Money math uses `brick/money`.

## Morning handoff

`NotifyNightAuditResult` (`app/Actions/NightAudit/`) is queued after commit when the audit ends. It sends one of:

| Notification           | When                                              | Recipients                   |
| ---------------------- | ------------------------------------------------- | ---------------------------- |
| `NightAuditCompleted`  | Completed with no open exception.                 | `night-audit.receive-report` |
| `NightAuditExceptions` | Completed with at least one open exception.       | `night-audit.receive-report` |
| `NightAuditFailed`     | Failed. `RunNightAudit` queues it on the failure. | `night-audit.run`            |

Each respects the [notification preferences](../04-notifications/02-notification-preferences.md) and is scoped to the property. The content is the occupancy, ADR and RevPAR, revenue by code, payments by method (from `payments`), today's meal counts (live from `MealEntitlementResolver`, not stored in the snapshot), and the exceptions to review with a link to the sheet. A Failed message names the step and the error.

The report packet PDF is not part of this item. It is rendered from the snapshot tables with the [core reports](../22-reports/01-core-reports.md).

## Build order

Item 19 of the [checklist](../checklist.md) is two commits: the snapshot with `DailyRevenueQuery`, then the notifications. The packet PDF and the live daily revenue report come with the core reports.

## Lessons from wastays

`night_audit_financial_summaries` had a jsonb changelog and recalculate and repair services.
