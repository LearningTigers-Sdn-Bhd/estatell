---
paths:
  - 'app/Enums/**'
---

# Enums

## Backed enums with label, tone and options
Use string-backed enums (`php artisan make:enum --string`) with TitleCase cases. Provide `label()`, `options()` (list of value/label for pickers) and `tone()` (neutral, info, success, warning, danger) when shown in the UI, and `canTransitionTo()` for statuses. Enums are exported to TypeScript; the frontend never hard-codes enum labels. No free-string statuses anywhere.

## Add enum methods only for their role
Use string-backed enums with TitleCase cases. User-facing enums provide label(), selectable enums provide options(), displayed statuses provide tone(), and state enums own transition rules. Keep enum-specific metadata and implementation mappings on the enum.
