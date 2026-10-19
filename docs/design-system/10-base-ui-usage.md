# Design system: Base UI usage

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Link buttons

Base UI docs: links should not be rendered as buttons through `render`.

| Case                              | Use                                                                       |
| --------------------------------- | ------------------------------------------------------------------------- |
| Navigates to a page               | Inertia `<Link>` with `buttonVariants()`                                  |
| Performs an action (POST, DELETE) | `<Button>`, or `<Link as="button" method="post">` with `buttonVariants()` |
| Trigger for an overlay or menu    | `render={<Button />}` on the trigger                                      |

```tsx
<Link href={reservations.create()} className={buttonVariants({ size: 'sm' })}>
  <PlusIcon data-icon="inline-start" />
  New reservation
</Link>

<Button onClick={checkIn}>Check in</Button>

<DialogTrigger render={<Button variant="outline" />}>Add payment</DialogTrigger>
```

Add `nativeButton={false}` only when a trigger renders an element that is not a button. Never use `Button render={<a />}` for navigation.

## Button labels

- Icon-only buttons need an `aria-label`, usually with a `Tooltip`.

    ```tsx
    <Button size="icon" variant="ghost" aria-label="Print folio">
        <PrinterIcon />
    </Button>
    ```

- Loading buttons keep their label and use `Spinner` + `disabled` + `focusableWhenDisabled`.

    ```tsx
    <Button disabled={processing} focusableWhenDisabled>
        {processing && <Spinner data-icon="inline-start" />}
        Save
    </Button>
    ```

- Do not rely on label changes ("Save" → "Saving…"). Some screen readers do not announce changes to a focused button's text. Report results with a `toast`.
- Labels are a verb and object: "Check in", "Post charge", "Record payment".
- Icons in buttons use `data-icon="inline-start"` or `"inline-end"`, with no sizing classes.

## Select

Base UI renders the **raw value** in the trigger unless the root has `items`. Without `items`, a selected `per_guest` shows as `per_guest` instead of "Per guest".

```tsx
<Select name="pricing_basis" items={pricingBasisOptions}>
    <SelectTrigger>
        <SelectValue placeholder="Choose pricing" />
    </SelectTrigger>
    <SelectContent alignItemWithTrigger={false}>
        <SelectGroup>
            {pricingBasisOptions.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                    {option.label}
                </SelectItem>
            ))}
        </SelectGroup>
    </SelectContent>
</Select>
```

Rules:

- `items` is required on every Select and Combobox. The same array renders the options and the trigger label.
- Placeholder: `<SelectValue placeholder="…" />`. Add a `{ value: null, label: '…' }` item to allow clearing.
- Set `name` on the root in forms. Base UI renders a hidden input, so Inertia `<Form>` submits it.
- Object values use `{ value, label }`, or `itemToStringLabel` (display) and `itemToStringValue` (submission).
- Use `alignItemWithTrigger={false}` for dropdown-style positioning.
- `EnumSelect` options can carry an `icon` (shown in the menu and in the trigger) and a `description`. `menuClassName` sets the menu width, for example `w-80`.
- Items always sit inside their group (`SelectItem` in `SelectGroup`).

## Multiple values

- `Select multiple` and `Combobox multiple` return arrays. Set `name` on the root.
- Confirm the submitted key format (`tags` or `tags[]`) when the first multi-value field is built.
- The Form Request validates the value as `array` with `Rule::in(...)` or `exists`.

## Enum options

- Backed enums provide `label()`, `tone()` and `options()`.
- Select and Combobox `items` for enum fields always come from `options()`, never hand-written arrays.
- Enum values are displayed through `label()` or `StatusBadge`, never raw.

## Radix to Base UI differences

| Radix (do not use)                                      | Base UI (use)                                          |
| ------------------------------------------------------- | ------------------------------------------------------ |
| `asChild`                                               | `render={<Component />}`                               |
| ToggleGroup `type="single" defaultValue="a"`            | `defaultValue={['a']}`                                 |
| ToggleGroup `type="multiple"`                           | `multiple`                                             |
| Controlled single toggle `value={v}`                    | `value={[v]} onValueChange={(v) => set(v[0])}`         |
| Accordion `type="single" collapsible`                   | `defaultValue={['item']}`, `multiple` for several open |
| Slider `defaultValue={[50]}`                            | `defaultValue={50}`                                    |
| `toast()` from sonner                                   | Base UI `toast` component                              |
| `<DropdownMenuItem asChild><Link /></DropdownMenuItem>` | `<DropdownMenuItem render={<Link href={…} />}>`        |
| SelectContent `position="popper"`                       | `alignItemWithTrigger={false}`                         |

## Other shadcn composition rules

- Dialog, Sheet and Drawer always have a Title (use `sr-only` if hidden).
- Use full Card composition: `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`, `CardFooter`.
- `TabsTrigger` always sits inside `TabsList`.
- `Avatar` always has `AvatarFallback`.
- Use `Alert` for callouts, `Empty` for empty states, `Separator` instead of `<hr>`, `Skeleton` for loading, `Badge` instead of styled spans.
- Forms use `FieldGroup` + `Field`. Related checkboxes and radios use `FieldSet` + `FieldLegend`. Inputs with add-ons use `InputGroup`.

## Merge checklist

- [ ] Every Select and Combobox has `items`, and `name` inside forms.
- [ ] Navigation uses `Link` + `buttonVariants()`.
- [ ] Every icon-only button has an `aria-label`.
- [ ] Loading buttons use `Spinner` + `disabled` + `focusableWhenDisabled`.
- [ ] No `asChild` and no `sonner` imports.
- [ ] Enum values are shown through `label()` or `StatusBadge`.
- [ ] Filtering and sorting on bounded data make no request.
- [ ] The form container matches the field count and the override rules.
- [ ] Semantic tokens only; no palette classes, manual `dark:` or arbitrary values.
- [ ] Checked on the real page in light and dark mode, with keyboard only.
