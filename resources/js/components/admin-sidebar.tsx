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
import { dashboard } from '@/routes/admin';

/**
 * Sidebar for platform admin pages. Groups come from `App\Navigation\AdminNavigation`; their labels stay plain.
 * External items (Horizon) move to the footer.
 */
export function AdminSidebar() {
    const { adminNavigation } = usePage().props;
    const groups = adminNavigation ?? [];
    const externalItems = groups
        .flatMap((group) => group.items)
        .filter((item) => item.external);

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            render={<Link href={dashboard()} prefetch />}
                        >
                            <AppLogo withIcon />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map((group) => {
                    const items = group.items.filter((item) => !item.external);

                    return items.length > 0 ? (
                        <NavMain
                            key={group.label}
                            label={group.label}
                            items={items.map(toNavItem)}
                        />
                    ) : null;
                })}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={externalItems.map(toNavItem)}
                    className="mt-auto"
                />
                <NavUser variant="sidebar" />
            </SidebarFooter>
        </Sidebar>
    );
}
