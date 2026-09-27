# Design system: Foundation

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Foundation

estatell uses **shadcn/ui** with the **Nova** style on the **Base UI** base, configured by preset **`b3ZzDdiJy4`**.

| Setting          | Value                           |
| ---------------- | ------------------------------- |
| Style            | Nova (reduced spacing, compact) |
| Base             | Base UI                         |
| Base color       | Olive                           |
| Primary / charts | Emerald                         |
| Body font        | Instrument Sans                 |
| Heading font     | Geist                           |
| Icons            | Lucide                          |
| Radius           | Default                         |
| Menu accent      | Bold                            |

Principles:

- **Never build basic components ourselves.** They come from shadcn as source code in `resources/js/components/ui/` and are upgraded with `shadcn add --diff`.
- **Build only PMS-specific components,** in `resources/js/components/pms/`.
- The preset is the single source for colors, fonts, radius and menu style.

Useful CLI commands:

```bash
npx shadcn@latest preset decode b3ZzDdiJy4
npx shadcn@latest docs <component>
npx shadcn@latest add <component> --dry-run
npx shadcn@latest add <component> --diff <file>
npx shadcn@latest search @shadcn -q "<query>"
```

## Switching from Radix to Base UI

The starter kit ships on Radix. The switch happens once, before any domain screens are built.

| Area                          | Change                                                                                      |
| ----------------------------- | ------------------------------------------------------------------------------------------- |
| 24 installed `ui/` components | Reinstalled as Base UI + Nova. They are unmodified, so this is safe.                        |
| Starter kit files             | 13 uses of `asChild` in 9 files become `render`                                             |
| Toasts                        | Sonner (`app.tsx`, `hooks/use-flash-toast.ts`) is replaced by the Base UI `toast` component |
| `package.json`                | `@radix-ui/*` removed, `@base-ui/react` added (dependency change, needs approval)           |
| Fonts                         | Geist (headings) added next to Instrument Sans in `vite.config.ts`                          |

The preset code does not store the base. Re-run init with the preset and `base` together, and preview with a dry run first. The `migrate-radix-to-base` skill covers the starter kit's own files.
