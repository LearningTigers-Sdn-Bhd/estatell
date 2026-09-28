# Design system: Page archetypes

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

Every screen uses one archetype.

| Archetype     | Built from                                                                | Used by                                         |
| ------------- | ------------------------------------------------------------------------- | ----------------------------------------------- |
| Index         | `PageHeader` + `FilterBar` + `DataTable` + `BulkActionBar` + `EmptyState` | Reservations, guests, companies, AR, rate plans |
| Detail        | `PageHeader` + `DetailLayout` (Tabs + side summary card)                  | Reservation, guest, company, folio              |
| Form          | `FormField` sections + sticky `FormActions`                               | Create and edit pages with 13+ fields           |
| Settings      | Tabs + `SettingsSection` (one card = one form, saved separately)          | Property, taxes, payments, terms, notifications |
| Wizard        | The Form archetype in the onboarding layout (`useLayoutProps`)            | Onboarding                                      |
| Board         | Grouped card grid + filters                                               | Housekeeping, readiness panel                   |
| Grid calendar | The `pms/grid` engine                                                     | Rate calendar, Stay View                        |

Rules:

- The app shell owns the page width and padding. Pages do not add page-level `max-w-*` or replacement padding.
- Forms are never embedded inline in tables, boards or dashboards.
- Deferred props always show a `Skeleton` while loading.
- Avoid nested cards and duplicated headings.
- Detail and Settings tabs are links with their own URL (`/…/{id}`, `/…/{id}/staff`; `/settings`, `/settings/operations`), not client-only state. Each tab has its own named route and controller method, and the methods share one private `renderTab()`. A refresh keeps the tab, and the server sends only the open tab's data. `TabLinks` renders the triggers as `Link`s (`DetailLayout` uses it). Every tab has an icon before its label and the same minimum width (`min-w-28`). Example: `Admin\PropertyController`, `Property\PropertySettingsController`.
