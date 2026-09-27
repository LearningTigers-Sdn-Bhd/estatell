# Design system: PMS components and pickers

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## PMS components

About 50 components, each built when the first page needs it. Before the reservations module, about 12 are needed.

| Group           | Components                                                                                                                                                                                                                                                                                |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Structure       | `PageHeader`, `FormActions`, `FormField`, `FormOverlay`, `SettingsSection`, `EmptyState`, `DataTable`, `FilterBar`, `BulkActionBar`, `DetailLayout`, `ConfirmAction`, `ContextSwitcher`, `BusinessDateIndicator`                                                                          |
| Display         | `StatusBadge`, `MoneyText`, `DateText`, `DateRangeText`, `BalanceSummary`, `OccupancyPill`, `PaxText`, `GuestSummary`, `AuditTrail`                                                                                                                                                       |
| Pickers         | `EnumSelect` (single or `multiple`), `MultiCombobox`, `DatePicker`, `DateRangePicker`, `WeekdayToggle`, `MoneyInput`, `PhoneInput`, `RoomTypePicker`, `RatePlanPicker`, `GuestPicker`, `CompanyPicker`, `RoomBedPicker`, `ChargeItemPicker`, `PaymentMethodPicker`, `RoomNumberGenerator` |
| Engine          | `pms/grid/`: viewport, virtualizer, date columns                                                                                                                                                                                                                                          |
| Feature folders | See [Where components live](03-component-folders.md)                                                                                                                                                                                                                                      |
| Guest-facing    | `GuestLayout`, `RemoteCardForm`                                                                                                                                                                                                                                                           |

Rules:

- Components receive data and callbacks. No business logic inside.
- Before writing markup, check shadcn, then `pms/`. Create a new component only when neither fits.
- Extend an existing component (variant or prop) before adding a near-duplicate.
- A new component needs a real use and must work in light and dark mode, with keyboard and screen readers.
- `FormField` maps Inertia errors onto shadcn `Field`: `data-invalid` on the field, `aria-invalid` on the control.

## Pickers

| Need                            | Component                                                 |
| ------------------------------- | --------------------------------------------------------- |
| One value, 2–7 options          | `ToggleGroup`                                             |
| One value, fixed list           | `EnumSelect` / `Select`                                   |
| One value, searchable           | Combobox pickers (`GuestPicker`, `RatePlanPicker`, …)     |
| Many values, 2–7 options        | `ToggleGroup multiple` (for example `WeekdayToggle`)      |
| Many values, short list         | `EnumSelect multiple`                                     |
| Many values, long or searchable | `MultiCombobox`                                           |
| Very many options (permissions) | Checkbox list grouped by module (`FieldSet` + `Checkbox`) |
| Money / phone                   | `MoneyInput` / `PhoneInput` (`InputGroup`)                |

- No native `<select>`.
- `EnumSelect multiple` trigger summary: 0 selected shows the placeholder, 1–2 show the labels, 3 or more show "first label +N".
- `MultiCombobox`: chips, ⌫ removes the last chip, collapse to "+N more", optional "Create …" item.

Examples:

```
Status (EnumSelect multiple)            Applies to charges (MultiCombobox)
┌──────────────────────────────┐        ┌────────────────────────────────────────┐
│ Confirmed, In-house    +1  ⌄ │        │ [Room ✕] [F&B ✕] [Laundry ✕]  Add…   ⌄ │
└──────────────────────────────┘        └────────────────────────────────────────┘
```
