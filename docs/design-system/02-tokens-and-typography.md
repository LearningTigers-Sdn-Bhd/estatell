# Design system: Tokens and typography

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Tokens

- Use only the preset's **semantic tokens**: `background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, the sidebar tokens and the chart tokens.
- **Status tokens** are added in `resources/css/app.css`: `success`, `warning`, `info`, each with a `-foreground`, in light and dark.
- **Grid tokens** are added in `resources/css/app.css` for the date grid engine and the calendars built on it. They are named by role, so the hue can change without touching a component:

| Token                                   | Role                                                                         |
| --------------------------------------- | ---------------------------------------------------------------------------- |
| `grid-surface`                          | Cell and plan label background, a soft tint that replaces bright white       |
| `grid-foreground`                       | Prices, plan names and dates inside the grid, a little softer than body text |
| `grid-header`, `grid-header-foreground` | Date header row, and its weekday text                                        |
| `grid-group`                            | Group heading rows, such as room types                                       |
| `grid-line`                             | Borders between cells                                                        |
| `grid-weekend`, `grid-hover`            | Translucent tints for weekend columns and the hovered row and column         |
| `grid-today`, `grid-today-foreground`   | Today's column                                                               |
| `grid-today-soft`                       | Light tint on the cells of today's column                                    |
| `grid-muted-foreground`                 | Secondary text inside the grid                                               |

- Use them as `bg-grid-header`, `text-grid-muted-foreground`, `border-grid-line`. Components inside a grid never use a palette class or a hue name.
- Every text pair meets WCAG 2.2 AA (4.5:1) in light and dark, and icons meet 3:1. Check a new pair before you ship it. Status colors that fail on a tint get a filled pill (`bg-warning text-warning-foreground`) in place of colored text.
- `weekend` and `hover` are translucent so they lay over any row background. Grid lines are decoration, so they are not held to 3:1.
    - Success badges use a soft (tinted) style. The success color must be clearly different from the emerald primary, so a status never looks like a primary button.
- **Enums drive status colors.** Every PMS enum has:
    - `label()`: the display text.
    - `tone()`: a `Tone` enum case: `neutral`, `info`, `success`, `warning` or `danger`. Data classes send statuses as `BadgeData` (value, label, tone) for `StatusBadge`.
    - `options()`: `list<array{value: string, label: string}>` for pickers.

    They are exported to TypeScript with `spatie/laravel-typescript-transformer`. `StatusBadge` and `EnumSelect` use them.

- **Money, dates and tables** use `tabular-nums`.
- **Not allowed**: palette classes (`red-500`, `slate-*`, `white`), manual `dark:` color overrides, arbitrary values (`w-[137px]`, `#hex`).

## Typography and spacing

- **Fonts**: Geist for headings, applied through `PageHeader` and section headings. Instrument Sans for everything else.
- **Sizes**: Nova's component defaults. No custom type scale until a real need appears.
- **Layout**:
    - `flex` or `grid` with `gap-*`. Never `space-x-*` or `space-y-*`.
    - `size-*` when width and height are equal.
    - `truncate` for single-line overflow.
    - `className` on components is for layout only, never to recolor or restyle them.
- **Overlays** (Dialog, Sheet, Popover) manage their own stacking. No manual `z-index`.
