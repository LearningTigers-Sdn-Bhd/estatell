# Design system: Accessibility, performance and PDFs

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Accessibility

- Semantic HTML before ARIA.
- Every control has an accessible name and full keyboard operation.
- Visible focus, logical focus order. Focus moves into and back out of overlays correctly.
- AA contrast: 4.5:1 for text, 3:1 for large text and UI parts.
- Meaning is never carried by color alone. `StatusBadge` always shows the label.
- Pointer targets at least 24 × 24 px; 44 × 44 px for primary touch actions (guest-facing pages, housekeeping on phones).
- Respect reduced motion.

## Performance and development speed

- Pages load as separate bundles through the page resolver.
- `babel-plugin-react-compiler` is enabled. No manual `useMemo` or `useCallback` for performance.
- Chart code loads only on report pages.
- Use the shadcn CLI and skill for every component: `docs`, `add --diff`, `search`.
- Check each component on the real page that uses it, in light and dark mode. No separate component showcase page.

## PDFs

dompdf does not support flex or grid layouts.

- PDFs use a separate Blade PDF layout with table-based markup.
- A small token set: font, 4-point spacing scale, rule widths.
- PDFs do not reuse screen components.
- The shared layout is `resources/views/pdf/layout.blade.php`: A4 portrait, DejaVu Sans (dompdf's built-in Unicode font), spacing in steps of 4 pt, rules of 0.5 pt (light) and 1 pt (strong). Each document extends it (the first is the payment receipt).
- A PDF opens inline in a new tab (`stream()`), so staff can print or save it.
