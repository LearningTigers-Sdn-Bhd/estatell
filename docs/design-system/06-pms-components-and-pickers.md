# Design system: PMS components and pickers

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## PMS components

About 50 components, each built when the first page needs it. Before the reservations module, about 12 are needed.

| Group           | Components                                                                                                                                                                                                                                                                                |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Structure       | `PageHeader`, `FormActions`, `FormField`, `FormOverlay`, `SettingsSection`, `TabLinks`, `EmptyState`, `DataTable`, `FilterBar`, `BulkActionBar`, `DetailLayout`, `ConfirmAction`, `AreaSwitcher`, `AppNavbar`, `BusinessDateIndicator`                                                    |
| Display         | `StatusBadge`, `MoneyText`, `DateText`, `DateRangeText`, `BalanceSummary`, `OccupancyPill`, `PaxText`, `GuestSummary`, `AuditTrail`                                                                                                                                                       |
| Pickers         | `EnumSelect` (single or `multiple`), `MultiCombobox`, `DatePicker`, `DateRangePicker`, `WeekdayToggle`, `MoneyInput`, `PhoneInput`, `RoomTypePicker`, `RatePlanPicker`, `GuestPicker`, `CompanyPicker`, `RoomBedPicker`, `ChargeItemPicker`, `PaymentMethodPicker`, `RoomNumberGenerator` |
| Engine          | `pms/grid/`: viewport, virtualizer, date columns                                                                                                                                                                                                                                          |
| Feature folders | See [Where components live](03-component-folders.md)                                                                                                                                                                                                                                      |
| Guest-facing    | `GuestLayout`, `RemoteCardForm`                                                                                                                                                                                                                                                           |

`BulkActionBar` (`components/pms/bulk-action-bar.tsx`) floats at the bottom of an Index page while rows are selected: the count, the bulk actions passed as children, and a clear button. It is `sticky` inside the page content, so it stays clear of the sidebar, and full width on a phone. Esc clears the selection unless a dialog or menu is open. A menu inside it opens upwards. First used by the [core reports](../22-reports/01-core-reports.md#row-selection-and-export).

`FormActions` is built with the [booking form](../12-reservations/05-booking-form.md#implementation), the first full-page form.

### Pagination

`PaginationFooter` is shared by server-paginated tables and card lists. On desktop, the result count sits left and the controls sit right. On mobile, the controls use icons and the count sits centered below them.

- First, Previous, Next and Last use outline links. First and Previous are disabled on the first page; Next and Last are disabled on the last page.
- A page input replaces numbered page links. Press Enter to navigate. Typing and leaving the input do not navigate.
- The input accepts whole numbers from 1 to the last page. Invalid input shows an error.
- Navigation keeps the server URL's filters and sorting, plus the current scroll position.
- A single page shows only the result count. An empty list hides the footer.

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
