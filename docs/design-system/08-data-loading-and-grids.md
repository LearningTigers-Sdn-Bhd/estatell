# Design system: Data loading and grids

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Data loading

**The server sends data. The browser derives views.**

| Needs the server (round trip)                                          | Done in the browser (no request)                          |
| ---------------------------------------------------------------------- | --------------------------------------------------------- |
| New data: another date window, the next page of a long list            | Filter, search, sort and group loaded data                |
| Writes: every Action                                                   | Mode switches, highlighting, legends                      |
| Authoritative calculations: prices, availability, tax, posted balances | Display calculations: layout, counts and totals on screen |
| Sensitive details: ID documents, full folio lines                      | Peek summaries from loaded data                           |

### Lists

- **Bounded lists** belong to one property and stay small: room types, rooms, rate plans, charge items, staff, companies. Load them once and filter in the browser.
- **Unbounded lists** grow forever: reservations, folio lines, AR invoices, activity log. Filter and paginate on the server. Filter state lives in the URL.

### Liveness

- Poll a small `version` hash with `useHttp`. Do a partial reload only when it changes.
- Use Inertia 3 optimistic updates for the user's own Actions. Rollback is automatic when the server rejects.
- Prefetch neighboring date windows and hovered links.

### Guardrails

- Browser calculations are display only. The server decides prices, availability, tax and balances when writing.
- Props are built per permission. Never send data the user may not see.
- Each feature keeps its own state (`useReducer` in its `pms/{feature}/` folder). No global state library.
- Pure browser functions (for example `projectBars()`) need unit tests. The runner is decided when the first one is written (`vp test`, or Vitest with approval).

### Budgets

| Measure                                 | Budget                                                     |
| --------------------------------------- | ---------------------------------------------------------- |
| Page props response time (p95)          | under 200 ms                                               |
| Version poll response time              | under 50 ms                                                |
| Stay View payload (100 rooms × 14 days) | under ~60 KB gzipped                                       |
| Lazy loading                            | `Model::preventLazyLoading()` in development and tests     |
| Queries                                 | Query-count tests on heavy pages (Stay View, booking form) |
| Filter, mode switch, peek               | zero requests                                              |

## Tables and grids

- **`DataTable`**: shadcn `table` + `@tanstack/react-table`. Browser-side for bounded lists, server-side (`spatie/laravel-query-builder`) for unbounded lists. Column visibility is stored in `localStorage` per user.
- **`pms/grid` engine**: CSS grid + `@tanstack/react-virtual` for rows and columns. Fixed row height. Bars placed with `grid-column: start / span n`. Built with the rate calendar first, then reused by Stay View.
