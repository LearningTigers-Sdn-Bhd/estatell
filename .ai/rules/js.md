---
paths:
  - 'resources/js/**'
---

# Js

## Follow the DESIGN.md contract
Read DESIGN.md before UI work. shadcn/ui Nova on Base UI (preset b3ZzDdiJy4): use components from `components/ui` first, then `components/pms`, and only then create a new one. Semantic tokens only (no palette classes, manual `dark:` or arbitrary values). `gap-*` not `space-y-*`. Details: docs/design-system.md (open only the needed part).

## Base UI usage
Triggers use `render={<Button />}`, never `asChild`. Navigation uses `<Link className={buttonVariants()}>`, never `Button render={<a />}`. Every Select and Combobox has `items` (otherwise the raw value shows) and `name` inside forms; enum options come from `options()`. Icon-only buttons need `aria-label`; loading buttons use `Spinner` + `disabled` + `focusableWhenDisabled`. Toasts use the Base UI `toast`, not sonner. See docs/design-system/10-base-ui-usage.md.

## AlertDialog structure for destructive confirms
Use AlertDialog only to confirm destructive or irreversible actions. Wrap AlertDialogTitle and AlertDialogDescription in AlertDialogHeader. Put the `<Form>` around AlertDialogFooter, with AlertDialogCancel and a submit Button (variant destructive, Spinner + disabled + focusableWhenDisabled). Name the target in the title, for example "Suspend {name}?". Example: pages/admin/properties/show.tsx.
