# Statistics and morning handoff

## Data model

```
daily_statistics    property_id, business_date, rooms_available, rooms_sold,
                    beds_available, beds_sold, occupancy, adr, revpar,
                    arrivals, departures, no_shows, in_house_guests
daily_revenue       property_id, business_date, transaction_code_id, amount, line_count
```

## Rules

- Filled once by `SnapshotStatistics` from `folio_lines` by posting date.
- Closed dates are immutable, so snapshots never need recalculation.

## Morning handoff

`NotifyNightAuditResult` sends `NightAuditCompleted` (or `NightAuditExceptions` / `NightAuditFailed`) to users with `night-audit.receive-report`, respecting [notification preferences](../04-notifications/02-notification-preferences.md). Content: occupancy, ADR, RevPAR, revenue by code, payments by method, today's meal counts, exceptions to review. The report packet PDF is rendered from the snapshot tables.

## Lessons from wastays

`night_audit_financial_summaries` had a jsonb changelog and recalculate and repair services.
