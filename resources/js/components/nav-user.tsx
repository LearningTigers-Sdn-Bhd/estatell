import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';

/** The placement controls the trigger; Account and Log out are shared. */
export function NavUser({
    variant = 'navbar',
}: {
    variant?: 'navbar' | 'sidebar';
}) {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    const isSidebar = variant === 'sidebar';
    const menu = (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    isSidebar ? (
                        <SidebarMenuButton
                            size="lg"
                            tooltip={auth.user.name}
                            aria-label={auth.user.name}
                            data-test="sidebar-menu-button"
                        />
                    ) : (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10 rounded-full"
                            aria-label={auth.user.name}
                            data-test="sidebar-menu-button"
                        />
                    )
                }
            >
                <UserInfo
                    user={auth.user}
                    showName={isSidebar}
                    showEmail={isSidebar}
                    collapseDetails={isSidebar}
                />
                {isSidebar && (
                    <ChevronsUpDown className="ml-auto group-data-[collapsible=icon]:hidden" />
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="min-w-56 rounded-lg"
                align={isSidebar ? 'start' : 'end'}
                side={isSidebar ? 'top' : 'bottom'}
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return isSidebar ? (
        <SidebarMenu>
            <SidebarMenuItem>{menu}</SidebarMenuItem>
        </SidebarMenu>
    ) : (
        menu
    );
}
