---
paths:
  - 'database/migrations/**'
---

# Migrations

## Normalized schema, minimal jsonb
One fact in one place: foreign keys, never copied strings or duplicated guest data. jsonb only for raw provider payloads, audit diffs and issued-document snapshots. Money is `decimal(12,2)` with no per-row currency (the property has one). Stay dates are `date`, not datetime. Use CHECK constraints and partial unique indexes for invariants. Target database is PostgreSQL. See docs/overview.md "What to avoid".
