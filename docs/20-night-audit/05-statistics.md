# Statistics and morning handoff

## Data model

```
daily_statistics    property_id, business_date, rooms_available, rooms_sold,
                    beds_available, beds_sold, occupancy, adr, revpar,
                    arrivals, departures, no_shows, in_house_guests,
                    day_use_sold, day_use_revenue
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

| Field                         | Rule                                                                                                                                                                                                                                                                                                                                                                  |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `rooms_available`             | Active rooms of Room room types on the date, not counting a room in an open room block. A dorm room counts in the bed fields only.                                                                                                                                                                                                                                    |
| `rooms_sold`                  | CheckedIn and CheckedOut units that sleep on the night of the date, one per room. A CheckedOut unit counts when it checked out before the audit and its night was posted at check-out ([Night audit pending](../18-front-desk/01-check-in-and-check-out.md#night-audit-pending)). It keeps only nights it slept, so no other CheckedOut unit has a night on the date. |
| `beds_available`, `beds_sold` | The same two rules for beds.                                                                                                                                                                                                                                                                                                                                          |
| `occupancy`                   | (`rooms_sold` + `beds_sold`) / (`rooms_available` + `beds_available`), as a percent. A room or a bed is one unit. Zero when nothing is available.                                                                                                                                                                                                                     |
| `adr`                         | Room revenue / (`rooms_sold` + `beds_sold`). Room revenue is the room revenue code plus the discount lines under its charges, net of tax. Zero when nothing is sold.                                                                                                                                                                                                  |
| `revpar`                      | Room revenue / (`rooms_available` + `beds_available`). Zero when nothing is available.                                                                                                                                                                                                                                                                                |
| `arrivals`, `departures`      | Units checked in and checked out on the business date, from the `reservation.unit_checked_in` and `reservation.unit_checked_out` events, one for each unit. The events carry the stay day as `business_date`: the calendar date for a check-in or check-out made while the audit is pending, so a 21 Oct arrival counts on 21 Oct. To build.                          |
| `no_shows`                    | Units marked no-show on the business date, from the `reservation.unit_no_show` event.                                                                                                                                                                                                                                                                                 |
| `in_house_guests`             | The adults and children of the CheckedIn units at the close.                                                                                                                                                                                                                                                                                                          |
| `day_use_sold`                | [Day use](../12-reservations/10-day-use.md#reports) units with `arrival_date` on the date, CheckedIn or CheckedOut, `released_at` null. Units, not room-hours. Default 0. Not in occupancy, ADR or RevPAR.                                                                                                                                                            |
| `day_use_revenue`             | The DAY_USE code plus the discount lines under its charges on the date, net of tax. Default 0.                                                                                                                                                                                                                                                                        |

`daily_revenue` has one row for each transaction code with lines on the date: the amount and the line count. Charges, taxes and adjustments count, payment lines do not. A line counts on its posting date, so a reversal reduces the date it was posted on. Money math uses `brick/money`.

A date can net below zero: a correction posted today reverses the charges of earlier nights, and today may have fewer new charges. `adr`, `revpar`, `day_use_revenue` and the revenue amounts keep the negative figure, so the statistics match `daily_revenue`. Only `occupancy` is checked to be non-negative.

## Morning handoff

`NotifyNightAuditResult` (`app/Actions/NightAudit/`) runs in `NotifyNightAuditResultJob` (`app/Jobs/`), which `RunNightAudit` queues when the audit ends, after it is Completed or Failed. The close is already committed by then, and a message that cannot be sent never changes the audit. A resumed audit sends one message for each end: a Failed message when it fails and a Completed or Exceptions message when it completes. The Action sends one of:

| Notification           | When                                              | Recipients                   |
| ---------------------- | ------------------------------------------------- | ---------------------------- |
| `NightAuditCompleted`  | Completed with no open exception.                 | `night-audit.receive-report` |
| `NightAuditExceptions` | Completed with at least one open exception.       | `night-audit.receive-report` |
| `NightAuditFailed`     | Failed. `RunNightAudit` queues it on the failure. | `night-audit.run`            |

The recipients are the active staff of the property (not admins, not deactivated users) who hold the permission. Each message respects the [notification preferences](../04-notifications/02-notification-preferences.md) and is scoped to the property. `AuditResultSummary` (`app/Services/NightAudit/`) reads the content:

- The occupancy, ADR and RevPAR and the front desk counts, from `daily_statistics`.
- Revenue by code, from `daily_revenue`.
- Payments by method: the completed folio payments whose folio line was posted on the business date, and the city ledger payments (`ar_payments`) received on the business date that are not voided (the rule of the Payments report), added together by method name, plus one total for refunds. Each method also has a count, which the packet prints.
- The meals to prepare, live from `MealCounts` (`app/Services/Meals/`, through `MealEntitlementResolver`), not stored in the snapshot. "Today" is the date that opens after the audit: the units that are Confirmed, CheckedIn or CheckedOut and have that date inside their stay (an early check-out has already moved the departure date), with their adults and children. The section is left out when no plan has a meal inclusion.
- The open exceptions of the audit with the reason in plain words, and a link to the night audit sheet. `forAudit($audit, allExceptions: true)` returns every exception, open and resolved, for the packet. The message never uses it.

A Failed message names the step and the error and says that the next run resumes at that step. In-app messages carry the title, the business date, the number of open exceptions and the link.

The night audit packet PDF is built with the core reports. It reads `AuditResultSummary` for a Completed audit of a Closed date and opens from the Night audit history report and from the Run Night Audit sheet. See [Core reports](../22-reports/01-core-reports.md#built-night-audit-history-and-packet).

## Build order

Item 19 of the [checklist](../checklist.md) is two commits, both built: the snapshot with `DailyRevenueQuery`, then the notifications. The packet PDF and the live daily revenue report came with the core reports (both built).

## Lessons from wastays

`night_audit_financial_summaries` had a jsonb changelog and recalculate and repair services.
