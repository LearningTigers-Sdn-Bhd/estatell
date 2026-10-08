---
paths:
  - 'app/**'
---

# App

## Use immutable Laravel date helpers
Use Laravel date helpers such as now() for application timestamps. The application configures them to return CarbonImmutable; do not construct mutable Carbon dates.

## Use full translation strings as keys
Use full source strings in __() calls, not dotted short translation keys.
