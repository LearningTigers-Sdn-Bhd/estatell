---
paths:
  - 'app/Data/**'
---

# Data

## spatie/laravel-data for props and payloads
Inertia props and request payloads use spatie/laravel-data classes in `app/Data/{Feature}/`, named after the feature (e.g. `StayView/StayData`). TypeScript types are generated from them with laravel-typescript-transformer; never hand-write a TS type that a Data class already defines. Props contain only what the user may see.

## Map domain objects through named Data factories
Build Data objects through explicit named factories such as fromModel(), fromEnum(), or forProperty() when mapping domain objects. Keep visibility and presentation mapping in the Data class instead of assembling public payload arrays in controllers.
