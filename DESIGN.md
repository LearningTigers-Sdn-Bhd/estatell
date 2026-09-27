# estatell UI contract

The short rules for every estatell screen. Read this before any UI work. The full reference, with examples and reasons, is split into parts listed in [docs/design-system.md](docs/design-system.md). Open only the part you need.

## Stack

- shadcn/ui, **Nova** style, **Base UI** base, preset **`b3ZzDdiJy4`** (olive base, emerald primary, Instrument Sans body, Geist headings, Lucide icons).
- Inertia 3 + React 19 + TypeScript. Tailwind 4.

## 1. Components first

1. Use a shadcn component from `resources/js/components/ui/`. Add missing ones with `npx shadcn@latest add`.
2. Otherwise use a PMS component from `resources/js/components/pms/`.
3. Create a new component only when neither fits. It needs a real use, light and dark mode, and keyboard support.

Never build basic components (select, dialog, date picker, table) ourselves.

## 2. Where code lives

| Folder                      | Holds                                                                  |
| --------------------------- | ---------------------------------------------------------------------- |
| `components/ui/`            | shadcn Base UI components                                              |
| `components/pms/`           | Shared PMS components (2+ modules)                                     |
| `components/pms/{engine}/`  | Shared engines (`grid/`)                                               |
| `components/pms/{feature}/` | Components used only by one domain-heavy page (`stay-view/`, `folio/`) |
| `pages/{module}/{page}.tsx` | Pages only. Never put components here.                                 |

Imports: `pages` → `pms/{feature}`, `pms/`, `ui/`. `pms/{feature}` → `pms/`, `ui/`. `pms/` → `ui/`. Feature folders never import each other.

## 3. Styling

- Semantic tokens only (`bg-background`, `text-muted-foreground`, `bg-primary`) plus status tokens `success`, `warning`, `info`.
- No palette classes, no manual `dark:` overrides, no arbitrary values, no manual `z-index` on overlays.
- `flex`/`grid` with `gap-*`, never `space-y-*`. `size-*` for equal sizes. `truncate` for overflow.
- `className` on components is for layout, never for recoloring.
- Nova default sizes. Headings use Geist through `PageHeader`.
- Money, dates and tables use `tabular-nums`.

## 4. Pages

Every page uses one archetype: **Index, Detail, Form, Settings, Wizard, Board, Grid calendar**. The shell owns page width and padding. Forms are never inline in tables or dashboards.

The sidebar has four contexts: **Front desk, Finance, Reports, Settings** (Account is in the user menu). It always shows the business date and the system date. Navigation is defined in PHP (`app/Navigation/`), never as lists in React.

## 5. Form containers

| Container   | Fields                           |
| ----------- | -------------------------------- |
| AlertDialog | 0–1 (confirm destructive action) |
| Dialog      | 1–5, one section, no scroll      |
| Sheet       | 4–12, page behind stays visible  |
| Full page   | 13+                              |

Always a full page for repeating rows, uploads, multiple steps, needing a URL, or long editing. One overlay level only. Mobile handled by `FormOverlay`.

## 6. Base UI rules

- Triggers: `render={<Button />}`, never `asChild`.
- Navigation: `<Link className={buttonVariants()}>`, never `Button render={<a />}`.
- Every Select and Combobox has `items` (otherwise the raw value shows) and `name` in forms.
- Enum options come from PHP enum `options()`. Display enums through `label()` or `StatusBadge`.
- Icon-only buttons have `aria-label`. Loading buttons use `Spinner` + `disabled` + `focusableWhenDisabled`.
- Toasts use the Base UI `toast` component, not Sonner.

## 7. Data loading

- The server sends data. The browser derives views.
- Filtering, sorting, grouping and mode switches on loaded data make **no request**.
- Bounded lists (rooms, rate plans, charge items) load once. Unbounded lists (reservations, folio lines) paginate on the server.
- Live pages poll a `version` hash and reload partially only on change. Own Actions update optimistically.
- The server decides prices, availability, tax and balances. Browser calculations are display only.

## 8. Before merging

- [ ] Components first; no near-duplicates.
- [ ] Semantic tokens only.
- [ ] Select/Combobox have `items` (+ `name` in forms).
- [ ] No `asChild`, no `sonner`, no `Button render={<a />}` for navigation.
- [ ] Form container matches the field count rules.
- [ ] No requests for filtering or sorting loaded data.
- [ ] Checked on the real page in light and dark mode, keyboard only.
