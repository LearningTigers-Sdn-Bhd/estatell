import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { toNavItem } from '@/lib/navigation';
import { redirect as homeRedirect } from '@/routes/home';

export function AccountSidebar() {
    const { accountNavigation } = usePage().props;

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-14 bottom-0 h-auto"
        >
            <SidebarContent>
                <NavMain
                    label="Account"
                    items={(accountNavigation ?? []).map(toNavItem)}
                />
            </SidebarContent>
            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            render={<Link href={homeRedirect()} />}
                            tooltip="Back to workspace"
                        >
                            <ArrowLeft />
                            <span>Back to workspace</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
        </Sidebar>
    );
}
