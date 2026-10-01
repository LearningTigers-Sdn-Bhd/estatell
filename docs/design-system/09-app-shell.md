# Design system: App shell

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Sidebar layout

Five rows, top to bottom:

```
┌──────────────────────────────┐
│ ▣  Pulau Inn · 10142         │  r1  icon + property name + property code
├──────────────────────────────┤
│ [ Front desk            ⌄ ]  │  r2  ContextSwitcher
├──────────────────────────────┤
│ OVERVIEW                     │  r3  menu groups + menu items
│   Dashboard                  │      no collapsible menus
│   Stay View                  │      fills the remaining height,
│ RESERVATIONS                 │      scrolls on its own if needed
│   Arrivals                   │
│   In-house                   │
│   Departures                 │
│   All reservations           │
│ GUESTS                       │
│   Guests                     │
│ OPERATIONS                   │
│   Housekeeping               │
│   Rate calendar              │
│   Night audit                │
│              ⋮               │
├──────────────────────────────┤
│ Business  Fri 12 Jun 2026    │  r4  BusinessDateIndicator
│ System    Sat 13 Jun · 01:24 │
│ ⚠ Night audit pending      › │
├──────────────────────────────┤
│ 👤 Aisha · Owner          ⌄  │  r5  user menu: Account, Log out
└──────────────────────────────┘
```

| Row | Content                                       | Behavior                                                                                                                                     |
| --- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| r1  | Property icon (logo, or initials), name, code | Fixed at top. Clicking opens the Front desk dashboard.                                                                                       |
| r2  | `ContextSwitcher`                             | Fixed. Hidden when the user has only one context.                                                                                            |
| r3  | Menu groups (labels) and items                | Takes all remaining height (`flex-1`) and scrolls inside a `ScrollArea`. Groups are plain labels, never collapsible; items are never nested. |
| r4  | `BusinessDateIndicator`                       | Fixed in the footer, always visible.                                                                                                         |
| r5  | User menu (name, role)                        | Fixed at the bottom. Opens Account and Log out.                                                                                              |

- The starter kit sidebar, restyled by the preset. The bold menu accent fills the active item with emerald.
- r1 and r2 use `SidebarHeader`, r3 uses `SidebarContent` with `SidebarGroup` + `SidebarGroupLabel` + `SidebarMenu`, r4 and r5 use `SidebarFooter`.
- The context switcher uses the shadcn sidebar "team switcher" pattern.
- Sub-views that would need nesting (for example Arrivals, In-house, Departures) are separate items under a group label, or tabs on the page. Never a collapsible submenu.

### Collapsed sidebar (icon mode)

| Row | Shows                                                                           |
| --- | ------------------------------------------------------------------------------- |
| r1  | Property icon only                                                              |
| r2  | Context icon button; opens the same switcher menu                               |
| r3  | Item icons with tooltips; group labels become separators                        |
| r4  | Date badge ("12 Jun") with a status dot; tooltip shows both dates and the state |
| r5  | Avatar                                                                          |

## Contexts

| Context        | For                 | Groups: items                                                                                                                                                                                                                                                                                                                                                     |
| -------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Front desk** | Daily operations    | Overview: Dashboard, Stay View. Reservations: Arrivals, In-house, Departures, All reservations. Guests: Guests. Operations: Housekeeping, Rate calendar, Night audit                                                                                                                                                                                              |
| **Finance**    | Money after posting | Billing: Folios, Payments. City ledger: Companies, Invoices, Payments received, Statements, Aging                                                                                                                                                                                                                                                                 |
| **Reports**    | Looking back        | Daily: Daily summary (occupancy, ADR, RevPAR), Night audit history. Revenue: Revenue, Payments by method, Tax (SST, tourism tax). Operations: Kitchen meals. Audit: Activity log                                                                                                                                                                                  |
| **Settings**   | Setup               | Property: Details (tabs: General, Operations, Registration card, Age groups, Meal periods, Support access), Terms. Rooms: Room types, Rooms and beds, Room groups. Rates: Rate plans, Packages. Billing setup: Taxes, Charge items, Payment methods, Discounts. Team: Staff, Roles. System: Notifications, Channels (only when the feature is on), Support access |

Rules:

- **Account is not a context.** It lives in the user menu.
- **The active context comes from the route name** (see [Routing](../routing.md#sidebar-contexts-decided)).
- **Same tab.** Switching context opens its last visited page. The last page per context is stored in `localStorage` per user. ⌘-click or middle-click still opens a new tab.
- **Permissions decide visibility.** An item shows only if the user has its permission. A context shows only if it has at least one visible item. With one context, the switcher is hidden (for example a housekeeping user).
- **⌘K searches all contexts**, so users rarely need the switcher.
- **Shortcuts:** `g f` Front desk, `g n` Finance, `g r` Reports, `g s` Settings.
- Onboarding (wizard layout), admin and guest pages have their own layouts without contexts.

## Business date and system date

Front desk staff must always know which business date they are posting to. After midnight and before the night audit, the business date and the calendar date differ.

### States

| State         | When                                                  | Display                                                                |
| ------------- | ----------------------------------------------------- | ---------------------------------------------------------------------- |
| In sync       | Business date = system date in the property time zone | Business date prominent, system date and time small. Neutral.          |
| Audit pending | System date is 1 day after the business date          | `warning` tone: "Night audit pending ›" links to the readiness panel   |
| Auditing      | Business date status is `Auditing`                    | `info` tone: "Night audit running ›" links to the audit progress       |
| Overdue       | System date is 2 or more days after the business date | `danger` tone: "Business date is 2 days behind ›" links to night audit |
| Not live      | Property is still onboarding                          | "Not live yet". No business date.                                      |

### Data

- **Business date**: shared Inertia prop `businessDate` with `date`, `status`, `timeZone`, `auditTime` and `serverNow`. Built from `CurrentBusinessDate` for the current property.
- **System date and time**: calculated in the browser in the **property time zone** (`@date-fns/tz`), ticking every minute. Uses `serverNow` to correct a wrong device clock (common on front desk PCs), so it never trusts the device time alone.
- **Refresh**: the prop updates on every page visit. While in Audit pending or Auditing, a small `useHttp` check (`/p/{code}/business-date`) runs every 5 minutes so the indicator changes when the audit finishes. The night audit page updates it immediately after closing.

### Placement

- Footer row r4, above the user menu, always visible in the expanded sidebar.
- Collapsed sidebar: see "Collapsed sidebar" above.
- Mobile: the same footer rows at the bottom of the sidebar sheet.
- The state is never shown by color alone. The text always says it.

## Navigation code

- Navigation is defined once in PHP: `app/Navigation/`, one class per context (`FrontDeskNavigation`, `FinanceNavigation`, `ReportsNavigation`, `SettingsNavigation`) built from route names and `PermissionEnum`. Each class returns `NavGroupData` groups of `NavItemData` items.
- `StaffNavigation` combines the classes, drops empty groups and contexts, and marks the active context. The `NavigationContext` enum holds each context's label, icon, shortcut key and route name patterns.
- The server sets `active` on each item with `routeIs`, so an item stays active on its sub-pages (for example Details on every settings tab).
- `HandleInertiaRequests` shares `navigation` (`StaffNavigationData`: `active` and `contexts`, already filtered by permission and features) and `businessDate`. `navigation` is null for admins and for suspended properties. On account pages staff get their own property's navigation with `active` null, and the sidebar shows the first context. Those pages have no permission team yet, so `HandleInertiaRequests` sets it to the staff user's property before building the navigation.
- The frontend only renders: `AppSidebar` + `ContextSwitcher` + `BusinessDateIndicator`. No navigation lists in React.
- The last page per context is stored in `localStorage` under `estatell.last-page.{userId}.{context}`. It is used only when it starts with the same `/p/{code}/`.

### Build status

| Part                       | Status                                                                                                          |
| -------------------------- | --------------------------------------------------------------------------------------------------------------- |
| r1, r2, r3, r5             | Built.                                                                                                          |
| Front desk, Settings       | Built with the items that have routes (Dashboard; Details). Each module adds its items.                         |
| Finance, Reports           | No navigation classes yet. They come with their modules and stay hidden until then.                             |
| Permission filtering       | Built. Each context class leaves out items the user lacks the permission for (Details needs `settings.manage`). |
| r4 `BusinessDateIndicator` | Comes with [Business dates](../20-night-audit/01-business-dates.md).                                            |
| ⌘K palette                 | Not built.                                                                                                      |

- Labels are plain words. Section names match the table above.
- Admin pages use `layouts/admin-layout.tsx` with `AdminSidebar`. Its items come from `App\Navigation\AdminNavigation`, shared as `adminNavigation` with admins only (null for staff). Account pages use the admin layout when this prop is present, so admins keep their sidebar there. Items marked `external` (Horizon) open in a new tab. The admin two-factor banner lives in this layout.

## Other shell parts

- A ⌘K command palette on every page.
- During impersonation, a banner that cannot be dismissed shows who is viewed, the time left and a Stop button (see [Impersonation](../01-platform/04-impersonation.md)).
- A separate guest-facing layout (`GuestLayout`) for the remote registration card: property logo, larger touch targets, no sidebar.

## Lessons from wastays

- Navigation lived in four helpers (711 lines): `navigation_helper`, `settings_navigation_helper`, `reports_navigation_helper`, `financials_navigation_helper`.
- Financials and Reports opened in a new tab so the front desk page was not lost. estatell keeps the page by remembering the last page per context instead.
