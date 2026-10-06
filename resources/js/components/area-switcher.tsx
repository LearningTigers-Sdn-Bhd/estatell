import { Link, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { navIcon } from '@/lib/navigation';

/**
 * Sidebar footer links to the staff areas (Front desk, Finance, Reports, Settings).
 * The current area is highlighted. The other areas open in a new browser tab, so the page in use is kept.
 * Hidden when the user has only one area. See docs/design-system/09-app-shell.md.
 */
export function AreaSwitcher() {
    const { navigation } = usePage().props;

    if (!navigation || navigation.contexts.length < 2) {
        return null;
    }

    const activeKey = navigation.active ?? navigation.contexts[0].key;

    return (
        <SidebarMenu aria-label="Areas">
            {navigation.contexts.map((context) => {
                const Icon = navIcon(context.icon);
                const isActive = context.key === activeKey;

                return (
                    <SidebarMenuItem key={context.key}>
                        <SidebarMenuButton
                            isActive={isActive}
                            tooltip={{ children: context.label }}
                            render={
                                isActive ? (
                                    <Link href={context.href} prefetch />
                                ) : (
                                    <a
                                        href={context.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    />
                                )
                            }
                        >
                            {Icon && <Icon />}
                            <span>{context.label}</span>
                            {!isActive && (
                                <ExternalLink
                                    aria-hidden
                                    className="ml-auto size-3.5 text-muted-foreground group-data-[collapsible=icon]:hidden"
                                />
                            )}
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                );
            })}
        </SidebarMenu>
    );
}
