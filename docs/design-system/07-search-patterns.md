# Design system: Search patterns

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

|          | Combobox                                                                                         | Command palette (⌘K)                          |
| -------- | ------------------------------------------------------------------------------------------------ | --------------------------------------------- |
| Where    | Inside a form field                                                                              | Global overlay, from any page                 |
| Result   | Sets a form value (`name` + `items`)                                                             | Navigates, or runs an Action                  |
| Scope    | One entity type                                                                                  | Guests, reservations, rooms, actions, pages   |
| Built on | Base UI Combobox                                                                                 | `Command` in a `Dialog`                       |
| Data     | Bounded lists filtered in the browser; unbounded lists from a `useHttp` search, cached per query | One grouped search endpoint, cached per query |

Rules:

- Results are grouped and keyboard hints are always visible.
- Items show labels, never raw values.
- Pressing `Tab` on a palette result shows that item's actions. Each action calls an existing Action.
- Items and actions the user has no permission for are hidden.
- `GuestPicker` shows the `GuestMatcher` duplicate warning with "Use existing" and "Create new anyway".

GuestPicker, open:

```
┌────────────────────────────────────────────┐
│ 🔍 ahmad▌                               ✕  │
├────────────────────────────────────────────┤
│ Existing guests                            │
│ ▸ Ahmad Faris        +60 12-345 6789  VIP  │
│   Ahmad Zulkifli     +60 19-222 1010       │
├────────────────────────────────────────────┤
│ ＋ Add "ahmad" as new guest                │
└────────────────────────────────────────────┘
```

Command palette:

```
┌──────────────────────────────────────────────────────┐
│ 🔍 204▌                                              │
├──────────────────────────────────────────────────────┤
│ Rooms                                                │
│ ▸ Room 204     Deluxe · Left wing     Dirty          │
│ Reservations                                         │
│   RES-2026-00412  Ahmad F.    Room 204  In-house     │
├──────────────────────────────────────────────────────┤
│ ↑↓ move   ↵ open   tab actions   esc close           │
└──────────────────────────────────────────────────────┘
```
