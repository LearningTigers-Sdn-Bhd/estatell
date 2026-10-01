import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { BusinessDateIndicator } from '@/components/business-date-indicator';
import { ContextSwitcher } from '@/components/context-switcher';
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
import { useInitials } from '@/hooks/use-initials';
import { toNavItem } from '@/lib/navigation';
import { dashboard } from '@/routes';
import { redirect as homeRedirect } from '@/routes/home';

/**
 * Staff sidebar. Contexts, groups and items come from `App\Navigation\StaffNavigation`.
 * See docs/design-system/09-app-shell.md.
 */
export function AppSidebar() {
    const { property, navigation } = usePage().props;
    const getInitials = useInitials();
    const home = property ? dashboard() : homeRedirect();
    const current = navigation
        ? (navigation.contexts.find(
              (context) => context.key === navigation.active,
          ) ?? navigation.contexts[0])
        : undefined;

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            render={<Link href={home} prefetch />}
                            tooltip={
                                property
                                    ? { children: property.name }
                                    : undefined
                            }
                        >
                            {property ? (
                                <>
                                    <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-xs font-semibold text-sidebar-primary-foreground">
                                        {getInitials(property.name)}
                                    </div>
                                    <div className="grid flex-1 text-left text-sm leading-tight">
                                        <span className="truncate font-semibold">
                                            {property.name}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {property.code}
                                        </span>
                                    </div>
                                </>
                            ) : (
                                <AppLogo />
                            )}
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {navigation && current && (
                    <ContextSwitcher
                        navigation={navigation}
                        current={current}
                    />
                )}
            </SidebarHeader>

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
                <BusinessDateIndicator />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
