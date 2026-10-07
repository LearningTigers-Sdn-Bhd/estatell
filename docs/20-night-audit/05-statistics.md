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

- Filled once by `SnapshotStatistics` (step 7 of the [audit run](03-audit-run.md#steps), `AuditStep::SnapshotStatistics`) from `folio_lines` by posting date.
- The step is idempotent: it upserts on the unique keys, so a resumed run writes the same rows.
- A snapshot that throws fails the audit. The date stays Auditing and the next run resumes at this step.
- Closed dates are immutable, so snapshots never need recalculation.

## Calculation

`StatisticsCalculator` (`app/Services/NightAudit/`) reads and returns the numbers. It writes nothing.

| Field                         | Rule                                                                                                       |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `rooms_available`             | Sellable rooms on the date, not counting rooms in a room block. A dorm room counts in the bed fields only. |
| `rooms_sold`                  | CheckedIn units that sleep on the night of the date, one per room.                                         |
| `beds_available`, `beds_sold` | The same two rules for beds.                                                                               |
| `occupancy`                   | `rooms_sold` / `rooms_available` (beds for dorms), as a percent. Zero when nothing is available.           |
| `adr`                         | Room revenue / `rooms_sold`. Room revenue is the room revenue code, net of tax.                            |
| `revpar`                      | Room revenue / `rooms_available`.                                                                          |
| `arrivals`, `departures`      | Units checked in and checked out on the date.                                                              |
| `no_shows`                    | Units marked no-show on the date.                                                                          |
| `in_house_guests`             | Guests of the CheckedIn units at the close.                                                                |

`daily_revenue` has one row for each transaction code with lines on the date. Money math uses `brick/money`.

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

Item 19 of the [checklist](../checklist.md) is three commits: the snapshot, then the notifications, then the packet PDF with the core reports.

## Lessons from wastays

`night_audit_financial_summaries` had a jsonb changelog and recalculate and repair services.
