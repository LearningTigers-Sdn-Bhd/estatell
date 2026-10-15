import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label = 'Platform',
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const [auditOpen, setAuditOpen] = useState(false);

    return (
        <SidebarGroup className="px-2 py-0">
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
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
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                render={<Link href={item.href} prefetch />}
                                isActive={
                                    item.isActive || isCurrentUrl(item.href)
                                }
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
        </SidebarGroup>
    );
}
