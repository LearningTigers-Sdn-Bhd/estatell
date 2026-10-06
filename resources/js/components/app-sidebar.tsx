import { usePage } from '@inertiajs/react';
import { AreaSwitcher } from '@/components/area-switcher';
import { BusinessDateIndicator } from '@/components/business-date-indicator';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
} from '@/components/ui/sidebar';
import { toNavItem } from '@/lib/navigation';

/**
 * Staff sidebar, below `AppNavbar`. It lists the groups and items of the active context from
 * `App\Navigation\StaffNavigation`. The footer shows the business date and the `AreaSwitcher`.
 * See docs/design-system/09-app-shell.md.
 */
export function AppSidebar() {
    const { navigation } = usePage().props;
    const current = navigation
        ? (navigation.contexts.find(
              (context) => context.key === navigation.active,
          ) ?? navigation.contexts[0])
        : undefined;

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-14 h-[calc(100svh-3.5rem)]"
        >
            <SidebarContent>
                {current?.groups.map((group) => (
                    <NavMain
                        key={group.label}
                        label={group.label}
                        items={group.items.map(toNavItem)}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <AreaSwitcher />
                <BusinessDateIndicator />
            </SidebarFooter>
        </Sidebar>
    );
}
