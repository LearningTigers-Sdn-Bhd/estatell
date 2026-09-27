---
paths:
  - 'app/Data/**'
---

# Data

## spatie/laravel-data for props and payloads
Inertia props and request payloads use spatie/laravel-data classes in `app/Data/{Feature}/`, named after the feature (e.g. `StayView/StayData`). TypeScript types are generated from them with laravel-typescript-transformer; never hand-write a TS type that a Data class already defines. Props contain only what the user may see.
