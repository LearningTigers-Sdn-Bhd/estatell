import { usePage } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { AreaSwitcher } from '@/components/area-switcher';
import { BusinessDateIndicator } from '@/components/business-date-indicator';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
} from '@/components/ui/sidebar';
import { toNavItem } from '@/lib/navigation';
import { dashboard as groupDashboard } from '@/routes/group';

/**
 * Staff sidebar, below the navbar and workspace header. It lists the groups and items of the active context from
 * `App\Navigation\StaffNavigation`; groups collapse, with the state kept only in memory. Inside a property, staff with
 * group mode get a one-click link back to it (the groups come from `groupSwitcher`, which the server limits). The
 * footer is a darker card (a band in icon mode) with the business date and the `AreaSwitcher`, so it reads apart from
 * the scrolling menu.
 * See docs/design-system/09-app-shell.md.
 */
export function AppSidebar() {
    const { navigation, groupSwitcher } = usePage().props;
    const current = navigation
        ? (navigation.contexts.find(
              (context) => context.key === navigation.active,
          ) ?? navigation.contexts[0])
        : undefined;
    const groupLinks =
        current?.key === 'group'
            ? []
            : groupSwitcher.map((group) => ({
                  title: group.name,
                  href: groupDashboard.url({ propertyGroup: group.id }),
                  icon: Network,
              }));

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-24 bottom-0 h-auto"
        >
            <SidebarContent className="overscroll-none">
                {groupLinks.length > 0 && (
                    <NavMain label="All properties" items={groupLinks} />
                )}
                {current?.groups.map((group) => (
                    <NavMain
                        key={group.label}
                        label={group.label}
                        items={group.items.map(toNavItem)}
                        collapsible
                    />
                ))}
            </SidebarContent>

            <SidebarFooter className="m-2 gap-0 overflow-hidden rounded-lg border border-sidebar-border bg-sidebar-accent p-0 transition-[margin,padding,border-radius] duration-200 ease-linear group-data-[collapsible=icon]:m-0 group-data-[collapsible=icon]:rounded-none group-data-[collapsible=icon]:border-x-0 group-data-[collapsible=icon]:border-b-0 group-data-[collapsible=icon]:p-2">
                <AreaSwitcher />
                <BusinessDateIndicator />
            </SidebarFooter>
        </Sidebar>
    );
}
