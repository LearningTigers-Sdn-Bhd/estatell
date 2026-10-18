# Design system: Tokens and typography

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Tokens

- Use only the preset's **semantic tokens**: `background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, the sidebar tokens and the chart tokens.
- **Status tokens** are added in `resources/css/app.css`: `success`, `warning`, `info`, each with a `-foreground`, in light and dark. `warning` also has `warning-text`, a readable amber for text on a soft warning tint.
- **Auth accent**: `auth-accent` is a faint emerald tint for the auth shell's edge gradient, with separate light and dark values. It is decorative, not a text color. Tailwind exposes it through `--color-auth-accent`.
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
- **Swatch tokens** are added in `resources/css/app.css` for colors a property picks, such as the [booking source badge](../12-reservations/11-booking-sources.md#badge). Ten hues: `swatch-gray`, `swatch-red`, `swatch-orange`, `swatch-amber`, `swatch-green`, `swatch-teal`, `swatch-blue`, `swatch-indigo`, `swatch-violet`, `swatch-pink`, each with a `-foreground`, in light and dark. Light is a mid-tone background (L 0.52) with a near-white foreground; dark is a light background (L 0.76) with a dark foreground (L 0.2), so a badge stays visible on a dark card. Every pair meets 4.5:1 for letters; measured WCAG ratios (light / dark): gray 5.27 / 8.43, red 5.67 / 8.10, orange 5.51 / 8.15, amber 5.34 / 8.34, green 4.97 / 8.79, teal 5.09 / 8.82, blue 5.32 / 8.45, indigo 5.49 / 8.33, violet 5.65 / 8.13, pink 5.74 / 8.00 (green in light is the tightest; re-check any change). `BookingSourceBadge` exports `swatchClasses` and `swatchColorClass()`. The `SwatchColor` enum names them, and a component maps each case to fixed class strings (`bg-swatch-blue text-swatch-blue-foreground`), so Tailwind sees them. They are never used for status: status stays on `Tone`.
- Every text pair meets WCAG 2.2 AA (4.5:1) in light and dark, and icons meet 3:1. Check a new pair before you ship it. Amber text on a tint uses `text-warning-text`. Other status colors that fail on a tint get a filled pill (`bg-warning text-warning-foreground`) in place of colored text.
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

## App wordmark

- The app and authentication layouts use **Estatell**, with a capital **E**, instead of the starter icon and app-name label.
- `AppLogoIcon` uses the email's font stack: Arial Black, Arial Bold, Arial, sans-serif, with weight 900.
- The `font-wordmark` and `tracking-wordmark` tokens keep the same font and proportional letter spacing at each size. No font download is needed.
- Authentication headers use `text-4xl`. Navigation uses `text-2xl`.
- Admin and partner sidebars use `AppLogo withIcon`: the existing house artwork from `/icon.png` beside the wordmark. Collapsed sidebars show only the house icon. Other layouts keep the text-only wordmark and compact **E**.
- The wordmark uses the semantic `primary` color in both themes and has the accessible name **Estatell**.
- Email branding, error-page artwork and favicons stay unchanged.

## Error-page wordmark

- Use the approved landing-page artwork, not a font recreation or the app icon. The error-page assets live in `public/images/errors/`.
- Light mode uses the original green `wordmark-light.webp`. Dark mode uses `wordmark-dark.svg`, an off-white fill through the original artwork's alpha mask. The letter shapes and transparent boundary stay the same.
- The `--error-wordmark` image URL changes in the existing light and dark theme definitions. The page uses that token, so the correct logo appears before React hydrates. Give it the accessible name **Estatell**.
- These assets apply only to the standalone error page. App and authentication layouts use the text wordmark described above.
