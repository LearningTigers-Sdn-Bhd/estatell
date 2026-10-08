---
paths:
  - 'app/Models/**'
---

# Models

## Models hold relations, casts and scopes only
No business logic in models; put it in Actions or Services. Every domain model has `property_id` and is always queried scoped to the current property. Never copy labels or other tables' data into columns (store `room_id`, not `room_number`). Cast statuses to backed enums.

## Use Fillable attribute allowlists
Declare mass-assignable attributes with Laravel's #[Fillable([...])] model attribute. Use allowlists; do not introduce $guarded blocklists.

## Eager-load relationships per query
Eager-load relationships explicitly at each query with with(), withCount(), load(), or loadMissing(). Do not add model-level $with defaults.
