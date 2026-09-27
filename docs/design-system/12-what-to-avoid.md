# Design system: What to avoid and setup status

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## What to avoid

| Avoid                                                                                                                                    | Instead                                     |
| ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Building basic components ourselves (wastays built 97 files of them)                                                                     | shadcn Base UI components                   |
| A server round trip for every filter, mode switch or sheet (wastays submitted a form on every filter change and rebuilt the whole board) | Load data once, derive views in the browser |
| The server drawing layout (bars, cards)                                                                                                  | The server sends data; the browser draws it |
| Full reloads on a timer                                                                                                                  | Version polling + partial reloads           |
| Big forms in Dialogs, Dialogs inside Dialogs, forms inline in tables                                                                     | Form container rules                        |
| Palette colors, manual `dark:`, arbitrary values, custom type scales                                                                     | Preset tokens, status tokens, Nova defaults |
| `space-y-*`, `w-* h-*`, custom pulse divs, custom badge spans                                                                            | `gap-*`, `size-*`, `Skeleton`, `Badge`      |
| `asChild`, Sonner, Radix APIs                                                                                                            | Base UI `render`, `toast` and its APIs      |
| `Button render={<a />}` for navigation                                                                                                   | `Link` + `buttonVariants()`                 |
| Select without `items`, hand-written enum options, native `<select>`                                                                     | `EnumSelect`, Combobox, `MultiCombobox`     |
| Components under `pages/`, imports between feature folders                                                                               | `pms/{feature}/` rules                      |
| Business logic in components; deciding prices or availability in the browser                                                             | Actions and server-side calculations        |

## Setup status

| Step                                                                                                                                                                                                                                                                    | Status  |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| 1. Re-init with preset `b3ZzDdiJy4` on Base UI (`components.json` style `base-nova`)                                                                                                                                                                                    | Done    |
| 2. Migrate starter kit files: `asChild` → `render`, Sonner → `toast` (`FlashToaster` shows server flash toasts), Geist loaded from Bunny in `vite.config.ts`                                                                                                            | Done    |
| 3. Add components: `field`, `input-group`, `textarea`, `switch`, `radio-group`, `tabs`, `table`, `popover`, `combobox`, `command`, `calendar`, `empty`, `alert-dialog`, `pagination`, `scroll-area`, `progress`, `kbd`, `hover-card`, `toast`, `drawer`. `chart` later. | Done    |
| 4. Add status tokens to `app.css`                                                                                                                                                                                                                                       | Done    |
| 5. Build `pms/` components as pages need them                                                                                                                                                                                                                           | Ongoing |

Notes:

- shadcn components import `cn` from the `cn` package. Starter kit code still uses `cn` from `@/lib/utils`.
- The `shadcn` npm package is a dependency because `app.css` imports `shadcn/tailwind.css`.
