import { Link } from '@inertiajs/react';
import { ChevronDown, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

/**
 * One sidebar group: a label and its items. With `collapsible`, the label folds the group. The open state lives
 * only in memory (the sidebar stays mounted between visits), a group opens again when you move to one of its
 * pages, and icon mode always shows every item. See docs/design-system/09-app-shell.md.
 */
export function NavMain({
    items,
    label = 'Platform',
    collapsible = false,
}: {
    items: NavItem[];
    label?: string;
    collapsible?: boolean;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    const [auditOpen, setAuditOpen] = useState(false);
    const [open, setOpen] = useState(true);

    const hasActiveItem = items.some(
        (item) => item.isActive || isCurrentUrl(item.href),
    );
    const [hadActiveItem, setHadActiveItem] = useState(hasActiveItem);

    if (hasActiveItem !== hadActiveItem) {
        setHadActiveItem(hasActiveItem);

        if (hasActiveItem) {
            setOpen(true);
        }
    }

    const menu = (
        <SidebarMenu>
            {items.map((item) =>
                item.action === 'night-audit' ? (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            onClick={() => setAuditOpen(true)}
                            tooltip={{ children: item.title }}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </SidebarMenuButton>
                        <NightAuditSheet
                            open={auditOpen}
                            onOpenChange={setAuditOpen}
                        />
                    </SidebarMenuItem>
                ) : item.external ? (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            render={
                                <a
                                    href={
                                        typeof item.href === 'string'
                                            ? item.href
                                            : item.href.url
                                    }
                                    target="_blank"
                                    rel="noopener noreferrer"
                                />
                            }
                            isActive={item.isActive || isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                            <ExternalLink
                                aria-hidden
                                className="ml-auto size-3.5 text-muted-foreground group-data-[collapsible=icon]:hidden"
                            />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ) : (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            render={<Link href={item.href} prefetch />}
                            isActive={item.isActive || isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </SidebarMenuButton>
                        {item.badge ? (
                            <SidebarMenuBadge className="rounded-full bg-primary text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-active/menu-button:text-primary-foreground">
                                {item.badge}
                                <span className="sr-only"> waiting</span>
                            </SidebarMenuBadge>
                        ) : null}
                    </SidebarMenuItem>
                ),
            )}
        </SidebarMenu>
    );

    if (!collapsible || !label) {
        return (
            <SidebarGroup className="px-2 py-0">
                {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
                {menu}
            </SidebarGroup>
        );
    }

    const isIconMode = state === 'collapsed' && !isMobile;
    const isOpen = open || isIconMode;

    return (
        <Collapsible open={isOpen} onOpenChange={setOpen}>
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel
                    render={<CollapsibleTrigger />}
                    className="w-full hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    {label}
                    <ChevronDown
                        aria-hidden
                        className={cn(
                            'ml-auto transition-transform',
                            !isOpen && '-rotate-90',
                        )}
                    />
                </SidebarGroupLabel>
                <CollapsibleContent>{menu}</CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}
