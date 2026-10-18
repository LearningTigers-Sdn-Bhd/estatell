import { usePage } from '@inertiajs/react';
import { NavMain } from '@/components/nav-main';
import { PortalPropertySwitcher } from '@/components/portal-property-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { toNavItem } from '@/lib/navigation';

/**
 * Sidebar for company portal pages, below the navbar and breadcrumb row. Items come from
 * `App\Navigation\PortalNavigation`; inside a property the header is the property switcher.
 * See docs/design-system/09-app-shell.md.
 */
export function PortalSidebar() {
    const { portalNavigation, portalCompany } = usePage().props;
    const items = portalNavigation ?? [];

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-24 bottom-0 h-auto"
        >
            {portalCompany && (
                <SidebarHeader>
                    <PortalPropertySwitcher />
                </SidebarHeader>
            )}

            <SidebarContent className="overscroll-none">
                <NavMain label="" items={items.map(toNavItem)} />
            </SidebarContent>
        </Sidebar>
    );
}
