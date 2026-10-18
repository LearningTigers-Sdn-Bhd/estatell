import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { toNavItem } from '@/lib/navigation';
import { index } from '@/routes/portal';

/**
 * Sidebar for company portal pages. Items come from `App\Navigation\PortalNavigation`.
 */
export function PortalSidebar() {
    const { portalNavigation } = usePage().props;
    const items = portalNavigation ?? [];

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            render={<Link href={index()} prefetch />}
                        >
                            <AppLogo withIcon />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={items
                        .filter((item) => !item.external)
                        .map(toNavItem)}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={items.filter((item) => item.external).map(toNavItem)}
                    className="mt-auto"
                />
                <NavUser variant="sidebar" />
            </SidebarFooter>
        </Sidebar>
    );
}
