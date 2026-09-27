import { Link, usePage } from '@inertiajs/react';
import { Activity, LayoutGrid, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { dashboard } from '@/routes/admin';
import type { NavItem } from '@/types';

const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    settings: Settings,
    activity: Activity,
};

function toNavItem(item: App.Data.Navigation.NavItemData): NavItem {
    return { title: item.title, href: item.href, icon: icons[item.icon] };
}

/**
 * Sidebar for platform admin pages. Items come from `App\Navigation\AdminNavigation`.
 */
export function AdminSidebar() {
    const { adminNavigation } = usePage().props;
    const items = adminNavigation ?? [];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            render={<Link href={dashboard()} prefetch />}
                        >
                            <AppLogo />
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
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
