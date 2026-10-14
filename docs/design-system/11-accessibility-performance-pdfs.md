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
- Chart code (recharts) loads only on pages that show a chart: reports and the dashboard Tonight tile.
- Use the shadcn CLI and skill for every component: `docs`, `add --diff`, `search`.
- Check each component on the real page that uses it, in light and dark mode. No separate component showcase page.

## PDFs

dompdf does not support flex or grid layouts.

- PDFs use a separate Blade PDF layout with table-based markup.
- A small token set: font, 4-point spacing scale, rule widths.
- PDFs do not reuse screen components.
- The shared layout is `resources/views/pdf/layout.blade.php`: A4, spacing in steps of 4 pt, rules of 0.5 pt (light) and 1 pt (strong). Each document extends it (the first is the payment receipt).
- The property's [printing settings](../02-property/05-printing-settings.md) set the font style (Office, Modern, Fancy or Handwritten, each falling back to Noto Sans SC and TC for Chinese), the body size (8, 9 or 10.5 pt), the letterhead on the first page and the footer on every page. Reports also set the orientation (portrait or landscape) from their layout. Report tables have a 4 pt gap between columns. A report with more than 9 visible columns prints its table 1 pt smaller, and dates, times, references and phone numbers never wrap.
- **Info strip.** A row of label and value cells under the letterhead (Bill to, Stay, Booked on, Your ref). A cell with no value is left out and the rest share the width, so a strip has 2 to 4 cells. There is no column-count setting.
- **Empty values never print.** No "-" for a missing value, no "0.00" payments row when nothing was paid, no country-only address, no empty strip cell. Leave the line or section out.
- **Amounts** sit in a right-aligned column. On the booking summary and the invoices, a payment or credit prints in brackets.
- What the customer documents show (grouping, nightly rows, tax split, optional sections) comes from the [Documents tab](../02-property/05-printing-settings.md#documents-tab). A document never adds its own switches.
- The registration card keeps its own layout and font choice.
- A PDF opens inline in a new tab (`stream()`), so staff can print or save it.
