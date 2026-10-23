# Design system: App shell

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Shell layout

The staff shell has three rows: the full-width navbar; the workspace label and breadcrumbs; then the sidebar and content. The staff areas stay as links in the sidebar footer, not a menu.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  ▣ Pulau Inn · ID ES1142                                    👤  │  navbar
├────────────────┬─────────────────────────────────────────────────────────┤
│ ▣ Front desk   │ Breadcrumbs                                             │
├────────────────┼─────────────────────────────────────────────────────────┤
│ OVERVIEW       │                                                         │
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

The navbar is 3.5 rem high. In the staff shell, the navbar and workspace row stay outside the scrolling content. The shell is `h-svh`; the document cannot scroll or overscroll while that shell is mounted. Content and sidebar navigation scroll separately with `overscroll-none`, so reaching an edge does not move the navbar. Fixed grid pages keep their own internal scroll regions. Other layouts keep their existing scroll behavior.

The property icon fits the uploaded logo inside a rounded square without cropping. With no logo, or if loading fails, it shows initials from that property's name. The shared `property.logoUrl` uses the property-scoped read-only `property.logo` route; viewing it does not require settings permission. Uploading and removing a logo still require `settings.manage`.

### Staff workspace row

- `StaffWorkspaceHeader` shows the active server-provided context: Front desk, Finance, Reports or Settings. Its icon and label are display only, not a link or dropdown.
- On desktop, the row is 2.5 rem high. The label column uses the sidebar width tokens and follows its collapsed state. The full-height divider aligns with the sidebar divider. Collapsed mode shows only the icon, with the workspace name available to assistive technology and in a title tooltip.
- Breadcrumbs sit to the right and stay fixed with this row. The sidebar starts below both header rows.
- On mobile, the workspace icon and label are hidden. Breadcrumbs sit directly below the navbar in a 2.5 rem row with 1 rem horizontal padding. The sidebar remains a drawer. The property identity stays in the navbar.
- This three-row structure applies to staff pages and the [company portal](#company-portal-shell). Admin, partner and account layouts stay unchanged.
- The staff navbar and workspace/breadcrumb row use the sidebar background, foreground and border tokens in both themes. Content keeps its own background.

### Theme shortcut

`AppearanceToggle` is one icon button that cycles **Light → Dark → System → Light**. Staff and account pages show it beside the navbar avatar. Admin and partner pages show it at the right end of the breadcrumb header. Mobile keeps the same placement.

The icon shows the saved preference: sun, moon or monitor. Its accessible label and tooltip name the current preference and the next action. It uses `useAppearance`, so changes apply immediately and share the saved setting with Account → Appearance. System mode follows the device theme. No request or page reload is needed.

### User menu variants

`NavUser` uses a placement variant, not a role check:

- **Navbar** (default): circular avatar only, for staff and all account pages. The menu opens below and aligns to the right.
- **Sidebar**: admin and partner sidebar footers show a full-width row with the avatar, name, email and chevrons. The menu opens above and aligns to the left. Collapsed sidebars show only the avatar with a name tooltip; mobile drawers show the full row.
- Both variants share the same Account and Log out actions. Long names and emails truncate.

### Sidebar rows

| Row | Content                        | Behavior                                                                                                                                     |
| --- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| r1  | Menu groups (labels) and items | Takes all remaining height (`flex-1`) and scrolls inside a `ScrollArea`. Groups are plain labels, never collapsible; items are never nested. |
| r2  | `AreaSwitcher`                 | Footer, above the dates. See below.                                                                                                          |
| r3  | `BusinessDateIndicator`        | Footer, the last row, always visible.                                                                                                        |

- The starter kit sidebar, restyled by the preset. The bold menu accent fills the active item with emerald.
- The staff sidebar sits below the navbar and workspace row (`top-24`). r1 uses `SidebarContent` with `SidebarGroup` + `SidebarGroupLabel` + `SidebarMenu`; r2 and r3 use `SidebarFooter`.
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

| Context        | For                 | Groups: items                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| -------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Front desk** | Daily operations    | Overview: Dashboard, Stay View. Reservations: Arrivals, In-house, Departures, All reservations. Guests: Guests. Operations: Housekeeping, Rate calendar, Night audit                                                                                                                                                                                                                                                                                                                          |
| **Finance**    | Money after posting | Billing: Folios, Payments. City ledger: Companies, Invoices, Payments received, Statements, Aging                                                                                                                                                                                                                                                                                                                                                                                             |
| **Reports**    | Looking back        | Front desk: Arrivals, Departures, In-house, Police report. Revenue: Occupancy (ADR, RevPAR), Revenue, Tax, Non-national guests. Finance: Payments, Folio ledger, Outstanding balance, Deposit liability. Kitchen: Meal counts. Audit: Night audit history, Activity log. See [Core reports](../22-reports/01-core-reports.md#categories)                                                                                                                                                      |
| **Settings**   | Setup               | Property: Details (tabs: General, Operations, Age groups, Meal periods, Support access), Registration card (tabs: General, Layout, Terms), Printing, OTA lists, Booking sources, Boats (only when the feature is on; tabs: Departures, Fares). Rooms: Room types, Rooms and beds, Room groups. Rates: Rate plans, Packages. Billing setup: Taxes, Charge items, Payment methods, Discounts. Team: Staff, Roles. System: Notifications, Channels (only when the feature is on), Support access |

Rules:

- **Account is not a staff context.** It lives in the user menu and opens its own account shell.
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
- `HandleInertiaRequests` shares `navigation` (`StaffNavigationData`: `active` and `contexts`, already filtered by permission and features) and `businessDate`. `navigation` is null for admins and for suspended properties. On account pages, these props remain available but the account shell does not render them. Those pages have no permission team yet, so `HandleInertiaRequests` sets it to the staff user's property before building the navigation.
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
- Admin pages use `layouts/admin-layout.tsx` with `AdminSidebar`. Its items come from `App\Navigation\AdminNavigation`, shared as `adminNavigation` with admins only (null for staff). Items marked `external` (Horizon) open in a new tab. The admin two-factor banner lives in this layout and remains available in the account shell.

### Account shell

- All account pages use `layouts/account/layout.tsx` on its own, for staff, admins and partners. They do not retain a workspace sidebar or a second menu inside the page.
- The navbar shows the estatell wordmark, sidebar toggle and user menu. The account sidebar has Profile, Security, Appearance and Notifications (property staff only), with icons and the active page highlighted.
- `App\Navigation\AccountNavigation` builds the items and active state on the server, shared as `accountNavigation` on account routes only. Security stays hidden during impersonation. The support-session banner stays visible.
- The sidebar footer has **Back to workspace**. It opens the existing home redirect in the same tab, which chooses the staff, admin or partner home and respects onboarding redirects.
- Account pages show no area links or business dates. Mobile uses the same account items in the sidebar drawer. The page forms and account URLs do not change.

### Company portal shell

Company portal pages (`layouts/portal-layout.tsx`) use the same three rows as staff, with these differences:

- **Navbar:** `AppNavbar` with `identity="app"` and `palette={false}`: sidebar toggle, the estatell logo (links to `/portal`), `AppearanceToggle` and the avatar-only `NavUser`. There is no ⌘K palette, because it searches staff pages.
- **Breadcrumb row:** `PortalBreadcrumbHeader`. It has the same height and tokens as the staff workspace row, with no workspace label column. The sidebar starts below both rows. On mobile the sidebar is a drawer and the row sits under the navbar.
- **Sidebar:** `PortalSidebar` builds its items from `App\Navigation\PortalNavigation`. The footer has no user menu.

| Where                  | Sidebar                                                                                                                                                                                                                           |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/portal`, `/portal/…` | Dashboard (`/portal`), Properties (`/portal/properties`)                                                                                                                                                                          |
| `/portal/{code}/…`     | A property switcher at the top, then Dashboard, Bookings (also active on New booking and a booking), Statements and Company details. There is no Book item: **Book a stay** (dashboard) and **New booking** (Bookings) lead to it |

- **Property switcher:** a `SidebarHeader` dropdown. The button shows the current property name with the company name under it. The menu lists the user's other Active links at Live properties, each with its company name, then a separator and **All properties** (`/portal/properties`). Properties that are not Live and pending invitations are not listed. A collapsed sidebar shows initials with a tooltip. The server shares the list as `portalSwitcher`, scoped to the user's Active links.
- **Breadcrumbs:** `/portal` Dashboard; `/portal/properties` Properties; `/portal/{code}` Dashboard; Bookings; Bookings › New booking; Bookings › {our reference}; Statements; Company details.

## Other shell parts

- A ⌘K command palette on every page.
- During impersonation, a banner that cannot be dismissed shows who is viewed, the partner name for a partner session, the time left and a Stop button (see [Impersonation](../01-platform/04-impersonation.md)).
- A separate guest-facing layout (`GuestLayout`) for the remote registration card: property logo, larger touch targets, no sidebar.

## Lessons from wastays

- Navigation lived in four helpers (711 lines): `navigation_helper`, `settings_navigation_helper`, `reports_navigation_helper`, `financials_navigation_helper`.
- Financials and Reports opened in a new tab so the front desk page was not lost. estatell does the same: the other areas open in a new tab from links in the sidebar footer, and the sidebar shows only the active area.
