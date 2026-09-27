# Readiness panel

## Purpose

Fix problems during the day so the audit has nothing to do at night.

## Service

`AuditReadiness`: runs the checks and returns counts and affected units. The dashboard card polls every 60 seconds (Inertia 3 polling).

## Checks

| Check                                | One-click fixes                           | Hard blocker |
| ------------------------------------ | ----------------------------------------- | ------------ |
| Arrivals not checked in              | Check in, Mark late arrival, Mark no-show | No (policy)  |
| Departures not checked out           | Check out, Extend stay                    | No (policy)  |
| In-house units without a room or bed | Assign                                    | **Yes**      |
| Pending payments                     | Confirm or fail                           | **Yes**      |
| Unit folio open after check-out      | Settle, transfer to master                | No           |
| Channel booking errors               | Retry, open revision                      | No           |
| Overbooked nights                    | Move room type, upgrade                   | No           |

Every fix calls an existing Action. No audit-specific resolution code.

## Lessons from wastays

7 pre-close and 8 post-close blocking checks, most needing a person at night.
