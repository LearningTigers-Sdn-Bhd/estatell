# Design system: Component folders

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

| Folder                      | Holds                                                                   | Example                                                    |
| --------------------------- | ----------------------------------------------------------------------- | ---------------------------------------------------------- |
| `components/ui/`            | shadcn Base UI components                                               | `button`, `select`, `combobox`                             |
| `components/pms/` (root)    | Shared PMS components used by 2 or more modules                         | `status-badge`, `money-text`, `guest-picker`, `data-table` |
| `components/pms/{engine}/`  | Shared engines made of several files                                    | `grid/`                                                    |
| `components/pms/{feature}/` | A domain-heavy page with 3 or more components used only by that feature | `stay-view/`, `folio/`                                     |
| `pages/{module}/{page}.tsx` | The page only: layout and composition                                   | `pages/front-desk/stay-view.tsx`                           |

Simple pages (Index, Detail, Form, Settings) do not get a feature folder. The page file composes `pms/` and `ui/` components directly.

Feature folders:

```
resources/js/components/pms/
├── grid/                  shared engine: viewport, virtualizer, date columns
├── stay-view/
├── rate-calendar/
├── booking-form/
├── folio/
├── registration-card/
├── onboarding/
├── night-audit/
├── housekeeping-board/
└── command-palette/
```

## Import rules

```
pages/*            → may import pms/{feature}, pms/, ui/
pms/{feature}/     → may import pms/ (root and engines), ui/
pms/ (root)        → may import ui/ only
pms/{feature}/  ✕  must not import another pms/{other-feature}/
```

- When a second feature needs a component from a feature folder, move it to the `pms/` root or an engine folder.
- Enforce with a `no-restricted-imports` rule in `vp check`.

## Naming

- A feature folder mirrors its page name: `pages/front-desk/stay-view.tsx` ↔ `pms/stay-view/`.
- Files are kebab-case. Exports are PascalCase.
- Hooks used by one feature live in its folder, for example `pms/stay-view/use-stay-selection.ts`.
- The backend uses the same feature name: `app/Services/StayView/`, `app/Data/StayView/`. TypeScript types are generated from the Data classes.

## Nothing but pages under `pages/`

The `@inertiajs/vite` plugin resolves every file in `resources/js/pages` as a page, and the `layout` mapping in `app.tsx` works from page names. A component placed in `pages/` becomes an accidental page and bundle.
