# Design system: Page archetypes

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

Every screen uses one archetype.

| Archetype     | Built from                                                                | Used by                                         |
| ------------- | ------------------------------------------------------------------------- | ----------------------------------------------- |
| Index         | `PageHeader` + `FilterBar` + `DataTable` + `BulkActionBar` + `EmptyState` | Reservations, guests, companies, AR, rate plans |
| Detail        | `PageHeader` + `DetailLayout` (Tabs + side summary card)                  | Guest, company, folio (reservation: see below)  |
| Form          | `FormField` sections + sticky `FormActions`                               | Create and edit pages with 13+ fields           |
| Settings      | Tabs + `SettingsSection` (one card = one form, saved separately)          | Property, taxes, payments, terms, notifications |
| Wizard        | The Form archetype in the onboarding layout (`useLayoutProps`)            | Onboarding                                      |
| Board         | Grouped card grid + filters                                               | Housekeeping, readiness panel                   |
| Grid calendar | Optional tab links + toolbar + the `pms/grid` engine, no `PageHeader`     | Rate calendar, Stay View                        |

Rules:

- The app shell owns the page width and padding. Pages do not add page-level `max-w-*` or replacement padding.
- Layout variants: a page sets `Page.layout = { variant }`. `default` grows with its content and the window scrolls. `fixed` fixes the page to the window height as a flex column, so the window does not scroll and the app header stays above. A Grid calendar gives its grid `fill`, and the grid scrolls on its own with a footer row at the bottom. A full-page Form puts its title, fields and summary in one scroll region marked `scroll-region`, and `FormActions` stays below it, so the footer is always visible. The booking form uses it. `fixed` was called `grid-only` before.
- Forms are never embedded inline in tables, boards or dashboards.
- Deferred props always show a `Skeleton` while loading.
- Avoid nested cards and duplicated headings.
- **Detail workspace** is a Detail variant for a page staff work in all day (the reservation page). It fills the shell height with no page scroll: header row, tab row, then up to three side-by-side panels (list, main, aside). Each panel has a rounded border, small padding and scrolls on its own. A tab with no list drops to two panels. Below `lg` it is one scrolling column. It is built with `ReservationWorkspace` (`components/pms/reservations/`), not `DetailLayout`; promote it to `components/pms/` when a second page needs it.
- Detail and Settings tabs are links with their own URL (`/…/{id}`, `/…/{id}/staff`; `/settings`, `/settings/operations`), not client-only state. Each tab has its own named route and controller method, and the methods share one private `renderTab()`. A refresh keeps the tab, and the server sends only the open tab's data. `TabLinks` renders the triggers as `Link`s in the `line` variant (underline on the active tab) (`DetailLayout` uses it). Every tab has an icon before its label and the same minimum width (`min-w-28`). Example: `Admin\PropertyController`, `Property\PropertySettingsController`. The tab panel is 80% wide from `md`. A tab with a wide table or a side-by-side preview passes `panelWidth="full"` (the rate plan Pricing and Discounts tabs).
