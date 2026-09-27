# Routing

## Purpose

Send charges to the right folio automatically, for example room charges to the company's master folio and extras to the guest's folio.

## Data model

```
folio_routing_rules    id, reservation_id, category (TransactionCategory), target_folio_id, timestamps
```

## Service

`FolioRouter`: returns the target folio for a line. If no rule matches, the line goes to the unit's own folio.

## Rules

- Choosing `MasterPays` creates rules for Room and room tax categories to the master folio.
- Rules are per reservation.

## Out of scope

Coverage percentages and date-ranged rules (wastays `folio_routing_rules.coverage_percentage`, `effective_from/until`).
