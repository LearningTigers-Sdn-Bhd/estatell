---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Pages only, archetypes and form containers
Only Inertia pages live in `pages/` (the Vite plugin treats every file there as a page); components go in `components/`. Each page uses one archetype (Index, Detail, Form, Settings, Wizard, Board, Grid calendar). Forms: Dialog 1–5 fields, Sheet 4–12, full page 13+ or with repeating rows, uploads, steps, own URL or long editing; one overlay level only. See docs/design-system/04-page-archetypes.md and 05-form-containers.md.

## Server sends data, browser derives views
Filtering, sorting, grouping and mode switches on loaded data make no request. Bounded lists (rooms, rate plans, charge items, staff) load once; unbounded lists (reservations, folio lines, AR) paginate on the server with filters in the URL. Live pages poll a small `version` and partially reload only on change; own Actions use optimistic updates. Browser math is display only. See docs/design-system/08-data-loading-and-grids.md.
