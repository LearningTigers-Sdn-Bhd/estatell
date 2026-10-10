# Design system: App shell

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Shell layout

A full-width navbar on top, the sidebar below it on the left, the page to the right. The staff areas are links in the sidebar footer, not a menu.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  ▣ Pulau Inn · ID 10142                                    👤  │  navbar
├────────────────┬─────────────────────────────────────────────────────────┤
│ OVERVIEW       │ Breadcrumbs                                             │
│   Dashboard    │                                                         │
│ RESERVATIONS   │                                                         │
│   All reserv.  │  page                                                   │
│ GUESTS         │                                                         │
│   Guests       │                                                         │
│ OPERATIONS     │                                                         │
│   Rate calendar│                                                         │
│       ⋮        │                                                         │
├────────────────┤                                                         │
│ ▸ Front desk   │  area links                                             │
│   Finance      │                                                         │
│   Reports      │                                                         │
│   Settings     │                                                         │
│ ⚠ Audit pending│  dates (last row)                                       │
│ Business 12 Jun│                                                         │
│ System 13 Jun  │                                                         │
└────────────────┴─────────────────────────────────────────────────────────┘
```

### Navbar (`AppNavbar`)

| Part  | Content                                                                                                                    |
| ----- | -------------------------------------------------------------------------------------------------------------------------- |
| Left  | Sidebar toggle, then the property icon (logo, or initials), name and "ID code". Clicking the identity opens the dashboard. |
| Right | User menu (`NavUser`): the avatar only, no name. It opens the name, Account and Log out.                                   |

Fixed at the top of the shell (it never scrolls), 3.5 rem high. Only the content area scrolls; the shell is `h-svh` and `SidebarInset` has `overflow-y-auto`. The breadcrumb bar is 2.5 rem high and scrolls with the page. On mobile the same navbar shows; the sidebar is an off-canvas sheet. Pages without a property (admin, account for admins) show the logo instead of the identity.

### Sidebar rows

| Row | Content                        | Behavior                                                                                                                                     |
| --- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| r1  | Menu groups (labels) and items | Takes all remaining height (`flex-1`) and scrolls inside a `ScrollArea`. Groups are plain labels, never collapsible; items are never nested. |
| r2  | `AreaSwitcher`                 | Footer, above the dates. See below.                                                                                                          |
| r3  | `BusinessDateIndicator`        | Footer, the last row, always visible.                                                                                                        |

- The starter kit sidebar, restyled by the preset. The bold menu accent fills the active item with emerald.
- The sidebar sits below the navbar (`top-14`). r1 uses `SidebarContent` with `SidebarGroup` + `SidebarGroupLabel` + `SidebarMenu`; r2 and r3 use `SidebarFooter`.
- The Dashboard sits in the Overview group. A group with an empty label would show no label.
- Sub-views that would need nesting (for example Arrivals, In-house, Departures) are separate items under a group label, or tabs on the page. Never a collapsible submenu.

### Area links (`AreaSwitcher`)

Plain items, one per area (Front desk, Finance, Reports, Settings), each with its icon. The current area is highlighted and opens in the same tab. The other areas open in a **new browser tab** (`target="_blank"`), so the page in use is kept; they show an external-link icon on the right (hidden in icon mode). Each link opens the area's first page. The list is hidden when the user has only one area.

### Collapsed sidebar (icon mode)

| Row | Shows                                                                           |
| --- | ------------------------------------------------------------------------------- |
| r1  | Item icons with tooltips; group labels become separators                        |
| r2  | Area icons with tooltips                                                        |
| r3  | Date badge ("12 Jun") with a status dot; tooltip shows both dates and the state |

## Contexts

| Context        | For                 | Groups: items                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Front desk** | Daily operations    | Overview: Dashboard, Stay View. Reservations: Arrivals, In-house, Departures, All reservations. Guests: Guests. Operations: Housekeeping, Rate calendar, Night audit                                                                                                                                                                                                                                                                                              |
| **Finance**    | Money after posting | Billing: Folios, Payments. City ledger: Companies, Invoices, Payments received, Statements, Aging                                                                                                                                                                                                                                                                                                                                                                 |
| **Reports**    | Looking back        | Front desk: Arrivals, Departures, In-house, Police report. Revenue: Occupancy (ADR, RevPAR), Revenue, Tax, Non-national guests. Finance: Payments, Folio ledger, Outstanding balance, Deposit liability. Kitchen: Meal counts. Audit: Night audit history (built), Activity log (a later item). See [Core reports](../22-reports/01-core-reports.md#categories)                                                                                                   |
| **Settings**   | Setup               | Property: Details (tabs: General, Operations, Age groups, Meal periods, Support access), Registration card (tabs: General, Layout, Terms), Printing, Boats (only when the feature is on; tabs: Departures, Fares). Rooms: Room types, Rooms and beds, Room groups. Rates: Rate plans, Packages. Billing setup: Taxes, Charge items, Payment methods, Discounts. Team: Staff, Roles. System: Notifications, Channels (only when the feature is on), Support access |

Rules:

- **Account is not a context.** It lives in the user menu.
- **The active context comes from the route name** (see [Routing](../routing.md#sidebar-contexts-decided)).
- **New tab.** An area link opens that area's first page in a new browser tab, except the current area. There is no last-page memory.
- **Permissions decide visibility.** An item shows only if the user has its permission. A context shows only if it has at least one visible item. With one context, the area links are hidden (for example a housekeeping user).
- **⌘K searches all contexts**, so users rarely need the area links.
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
- **Refresh**: the prop updates on every page visit. While in Audit pending, Overdue or Auditing, a small `useHttp` check (`/p/{code}/business-date`) runs every 5 minutes so the indicator changes when the audit finishes. The night audit page updates it immediately after closing.

### Placement

- Footer row r3, the last row of the sidebar, always visible in the expanded sidebar.
- Collapsed sidebar: see "Collapsed sidebar" above.
- Mobile: the same footer rows at the bottom of the sidebar sheet.
- The state is never shown by color alone. The text always says it.

## Navigation code

- Navigation is defined once in PHP: `app/Navigation/`, one class per context (`FrontDeskNavigation`, `FinanceNavigation`, `ReportsNavigation`, `SettingsNavigation`) built from route names and `PermissionEnum`. Each class returns `NavGroupData` groups of `NavItemData` items.
- `StaffNavigation` combines the classes, drops empty groups and contexts, and marks the active context. The `NavigationContext` enum holds each context's label, icon and route name patterns.
- The server sets `active` on each item with `routeIs`, so an item stays active on its sub-pages (for example Details on every settings tab).
- `HandleInertiaRequests` shares `navigation` (`StaffNavigationData`: `active` and `contexts`, already filtered by permission and features) and `businessDate`. `navigation` is null for admins and for suspended properties. On account pages staff get their own property's navigation with `active` null, and the sidebar shows the first context. Those pages have no permission team yet, so `HandleInertiaRequests` sets it to the staff user's property before building the navigation.
- The frontend only renders: `AppNavbar` + `AppSidebar` + `AreaSwitcher` + `BusinessDateIndicator`. No navigation lists in React.

### Build status

| Part                        | Status                                                                                                                                              |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Navbar, sidebar, area links | Built.                                                                                                                                              |
| Front desk, Settings        | Built with the items that have routes (Dashboard; Details). Each module adds its items.                                                             |
| Finance, Reports            | No navigation classes yet. They come with their modules and stay hidden until then.                                                                 |
| Permission filtering        | Built. Each context class leaves out items the user lacks the permission for (Details needs `settings.manage`).                                     |
| r3 `BusinessDateIndicator`  | Built with [Business dates](../20-night-audit/01-business-dates.md). The pending, running and overdue texts become links with the night audit page. |
| ⌘K palette                  | Not built.                                                                                                                                          |

- Labels are plain words. Section names match the table above.
- Admin pages use `layouts/admin-layout.tsx` with `AdminSidebar`. Its items come from `App\Navigation\AdminNavigation`, shared as `adminNavigation` with admins only (null for staff). Account pages use the admin layout when this prop is present, so admins keep their sidebar there. Items marked `external` (Horizon) open in a new tab. The admin two-factor banner lives in this layout.

## Other shell parts

- A ⌘K command palette on every page.
- During impersonation, a banner that cannot be dismissed shows who is viewed, the time left and a Stop button (see [Impersonation](../01-platform/04-impersonation.md)).
- A separate guest-facing layout (`GuestLayout`) for the remote registration card: property logo, larger touch targets, no sidebar.

## Lessons from wastays

- Navigation lived in four helpers (711 lines): `navigation_helper`, `settings_navigation_helper`, `reports_navigation_helper`, `financials_navigation_helper`.
- Financials and Reports opened in a new tab so the front desk page was not lost. estatell does the same: the other areas open in a new tab from links in the sidebar footer, and the sidebar shows only the active area.
