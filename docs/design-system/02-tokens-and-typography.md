# Design system: Tokens and typography

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Tokens

- Use only the preset's **semantic tokens**: `background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, the sidebar tokens and the chart tokens.
- **Status tokens** are added in `resources/css/app.css`: `success`, `warning`, `info`, each with a `-foreground`, in light and dark.
    - Success badges use a soft (tinted) style. The success color must be clearly different from the emerald primary, so a status never looks like a primary button.
- **Enums drive status colors.** Every PMS enum has:
    - `label()`: the display text.
    - `tone()`: `neutral`, `info`, `success`, `warning` or `danger`.
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
