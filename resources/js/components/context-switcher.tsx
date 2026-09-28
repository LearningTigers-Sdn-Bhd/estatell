import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useEffect } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { navIcon } from '@/lib/navigation';

type Context = App.Data.Navigation.NavContextData;

const storageKey = (userId: number, context: App.Enums.NavigationContext) =>
    `estatell.last-page.${userId}.${context}`;

/** The context's first page, or the last page the user visited in it on this property. */
function targetFor(userId: number, context: Context): string {
    const propertyPrefix = context.href.split('/').slice(0, 3).join('/') + '/';

    try {
        const lastPage = window.localStorage.getItem(
            storageKey(userId, context.key),
        );

        if (lastPage?.startsWith(propertyPrefix)) {
            return lastPage;
        }
    } catch {
        // Storage can be blocked; fall back to the first page.
    }

    return context.href;
}

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

/**
 * Switches the staff sidebar between contexts (Front desk, Finance, Reports, Settings).
 * Opens the last visited page of each context and supports the `g` + key shortcuts.
 * Hidden when the user has only one context. See docs/design-system/09-app-shell.md.
 */
export function ContextSwitcher({
    navigation,
    current,
}: {
    navigation: App.Data.Navigation.StaffNavigationData;
    current: Context;
}) {
    const { auth } = usePage().props;
    const { url } = usePage();
    const { isMobile, state } = useSidebar();
    const userId = auth.user.id;
    const contexts = navigation.contexts;

    useEffect(() => {
        if (navigation.active === null) {
            return;
        }

        try {
            window.localStorage.setItem(
                storageKey(userId, navigation.active),
                url,
            );
        } catch {
            // The last page is a convenience only.
        }
    }, [userId, navigation.active, url]);

    useEffect(() => {
        if (contexts.length < 2) {
            return;
        }

        let pendingSince = 0;

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                isTyping(event.target)
            ) {
                return;
            }

            if (Date.now() - pendingSince < 1000) {
                pendingSince = 0;
                const context = contexts.find(
                    (item) => item.shortcut === event.key,
                );

                if (context) {
                    event.preventDefault();
                    router.visit(targetFor(userId, context));
                }

                return;
            }

            if (event.key === 'g') {
                pendingSince = Date.now();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [contexts, userId]);

    if (contexts.length < 2) {
        return null;
    }

    const CurrentIcon = navIcon(current.icon);

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <SidebarMenuButton
                                className="data-popup-open:bg-sidebar-accent"
                                tooltip={{ children: current.label }}
                            />
                        }
                    >
                        {CurrentIcon && <CurrentIcon />}
                        <span className="truncate font-medium">
                            {current.label}
                        </span>
                        <ChevronsUpDown className="ml-auto" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--anchor-width) min-w-56"
                        align="start"
                        side={
                            !isMobile && state === 'collapsed'
                                ? 'right'
                                : 'bottom'
                        }
                    >
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Switch to</DropdownMenuLabel>
                            {contexts.map((context) => {
                                const Icon = navIcon(context.icon);

                                return (
                                    <DropdownMenuItem
                                        key={context.key}
                                        render={
                                            <Link
                                                href={targetFor(
                                                    userId,
                                                    context,
                                                )}
                                            />
                                        }
                                    >
                                        {Icon && <Icon />}
                                        {context.label}
                                        <DropdownMenuShortcut>
                                            G {context.shortcut.toUpperCase()}
                                        </DropdownMenuShortcut>
                                    </DropdownMenuItem>
                                );
                            })}
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
