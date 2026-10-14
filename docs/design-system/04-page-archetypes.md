# Design system: Page archetypes

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

Every screen uses one archetype.

| Archetype     | Built from                                                                     | Used by                                         |
| ------------- | ------------------------------------------------------------------------------ | ----------------------------------------------- |
| Index         | `PageHeader` + `FilterBar` + `DataTable` + `BulkActionBar` + `EmptyState`      | Reservations, guests, companies, AR, rate plans |
| Detail        | `PageHeader` + `DetailLayout` (Tabs + side summary card)                       | Guest, company, folio (reservation: see below)  |
| Form          | `FormField` sections + sticky `FormActions`                                    | Create and edit pages with 13+ fields           |
| Settings      | Tabs + `SettingsSection` (one card = one form, saved separately)               | Property, taxes, payments, terms, notifications |
| Wizard        | The Form archetype in the onboarding layout (`useLayoutProps`)                 | Onboarding                                      |
| Board         | Grouped card grid + filters                                                    | Housekeeping, readiness panel                   |
| Dashboard     | Alert + quick action buttons + a bento grid: one action list beside link tiles | [Dashboard](../18-front-desk/04-dashboard.md)   |
| Grid calendar | Optional tab links + toolbar + the `pms/grid` engine, no `PageHeader`          | Rate calendar, Stay View                        |
| Error         | Standalone wordmark, illustration, heading, explanation and recovery buttons   | 403, 404, 419, 500 and 503                      |

## Authentication layout

- Auth pages use `AuthSimpleLayout`, a full-width shell with a fixed `100dvh` height and an inner scroll region.
- Two horizontal and two vertical dashed lines cross near the edges. Their inset is 24px on mobile and 48px from `sm`.
- The dashed frame and static dot grid use `primary` at 30% opacity. The dots fade to transparent toward the center. Both are decorative and ignore pointer events.
- A faint emerald gradient uses `auth-accent` around the edges and fades to transparent before the form. It adapts to the theme without animation.
- The shell keeps content inside the frame with 40px padding on mobile and 80px from `sm`.
- Content has a `max-w-sm` width and centers when space permits. Long forms and short viewports scroll without hiding the first field or final action.
- The header shows the text wordmark, a Geist heading and a short description. Forms have no card border, shadow or marketing panel.
- Fields use consistent gaps, errors stay below their controls, status notices use `Alert`, and loading buttons use `Spinner`.
- Login, password recovery, two-factor challenge, set password, owner setup and verify email share this layout. Registration and password confirmation inherit it too.
- Invitation and suspended-property pages also inherit the shared auth shell. Their forms and actions stay unchanged.

## Shared page rules

- The app shell owns the page width and padding. Pages do not add page-level `max-w-*` or replacement padding. Error pages have no app shell and own their centered width and padding.
- Layout variants: a page sets `Page.layout = { variant }`. `default` grows with its content and the window scrolls. `fixed` fixes the page to the window height as a flex column, so the window does not scroll and the app header stays above. A Grid calendar gives its grid `fill`, and the grid scrolls on its own with a footer row at the bottom. A full-page Form puts its title, fields and summary in one scroll region marked `scroll-region`, and `FormActions` stays below it, so the footer is always visible. The booking form uses it. `fixed` was called `grid-only` before.
- Forms are never embedded inline in tables, boards or dashboards.
- Deferred props always show a `Skeleton` while loading.
- Avoid nested cards and duplicated headings.
- Error pages use one `errors` page with Geist headings, Instrument Sans body text, a theme-aware Estatell wordmark and a transparent illustration per status. They have no navigation or shared domain props. Light, Dark and System follow the saved appearance, including errors before web middleware.
- Error recovery always opens a fresh GET request. **Go home** uses the role-aware dashboard redirect (sign-in for guests). **Go back** and **Refresh** use a same-origin referrer or that redirect. **Try again** uses the requested GET URL, or the same recovery rule after a submission. Failed submissions are never replayed.
- Laravel renders branded 403, 404, 419, 500 and 503 responses with the original status and maintenance headers. Local 500 diagnostics, API/JSON errors, other statuses and normal redirects keep their existing behavior. Upstream outages and pre-rendered static maintenance pages are outside this page.
- In local development, open `/_preview/errors/{status}` for 403, 404, 419, 500 or 503. Previews show the standalone page with its status code, including 500 without replacing local exception diagnostics. These URLs return 404 outside the local environment.
- **Detail workspace** is a Detail variant for a page staff work in all day (the reservation page). It fills the shell height with no page scroll: header row, tab row, then up to three side-by-side panels (list, main, aside). Each panel has a rounded border, small padding and scrolls on its own. A tab with no list drops to two panels. Below `lg` it is one scrolling column. It is built with `ReservationWorkspace` (`components/pms/reservations/`), not `DetailLayout`; promote it to `components/pms/` when a second page needs it.
- Detail and Settings tabs are links with their own URL (`/…/{id}`, `/…/{id}/staff`; `/settings`, `/settings/operations`), not client-only state. Each tab has its own named route and controller method, and the methods share one private `renderTab()`. A refresh keeps the tab, and the server sends only the open tab's data. `TabLinks` renders the triggers as `Link`s in the `line` variant (underline on the active tab) (`DetailLayout` uses it). Every tab has an icon before its label and the same minimum width (`min-w-28`). Example: `Admin\PropertyController`, `Property\PropertySettingsController`. The tab panel is 80% wide from `md`. A tab with a wide table or a side-by-side preview passes `panelWidth="full"` (the rate plan Pricing and Discounts tabs).
