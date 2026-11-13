import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

export type TabLink = {
    value: string;
    label: ReactNode;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
    /** The tab's name in the breadcrumbs, when `label` is not plain text (a count or a badge). */
    title?: string;
};

/** The plain name of a tab, for the breadcrumbs. */
export function tabTitle(tab: TabLink): string {
    return tab.title ?? (typeof tab.label === 'string' ? tab.label : tab.value);
}

/**
 * Adds the open tab as the last crumb, with a switch menu of the other tabs. Pages call it in their `layout` so
 * the crumb is there on the first paint. Leaves the crumbs as they are when there is only one tab. When the tab has
 * the same name as the crumb before it (Boats › Boats), that crumb gets the menu instead of a repeated crumb.
 */
export function withTabCrumb(
    breadcrumbs: BreadcrumbItem[],
    tabs: TabLink[],
    activeTab: string,
): BreadcrumbItem[] {
    const active = tabs.find((tab) => tab.value === activeTab);

    if (!active || tabs.length < 2) {
        return breadcrumbs;
    }

    const title = tabTitle(active);
    const siblings = {
        label: 'Tabs',
        items: tabs.map((tab) => ({
            title: tabTitle(tab),
            href: tab.href,
            current: tab.value === activeTab,
        })),
    };
    const last = breadcrumbs.at(-1);

    if (last?.title === title) {
        return [...breadcrumbs.slice(0, -1), { ...last, siblings }];
    }

    return [...breadcrumbs, { title, href: active.href, siblings }];
}

type Props = {
    tabs: TabLink[];
    activeTab: string;
    className?: string;
    /** Extra classes for each tab, for example `md:min-w-0` to size tabs to their label on every screen. */
    triggerClassName?: string;
    /** `full` drops the 80% panel width, for a tab with a side-by-side preview. */
    panelWidth?: 'default' | 'full';
    /** Extra classes for the tab panel, for example to make it a flex column that fills the page. */
    panelClassName?: string;
    /** Content of the active tab. */
    children: ReactNode;
};

/**
 * Page tabs where each tab is a link to its own URL, so the open tab survives a refresh and can be shared.
 * The server sends only the open tab's data. Used by the Detail and Settings archetypes.
 * When the tabs do not fit, the row scrolls sideways on its own, with no scrollbar, and the page stays still.
 */
export function TabLinks({
    tabs,
    activeTab,
    className,
    triggerClassName,
    panelWidth = 'default',
    panelClassName,
    children,
}: Props) {
    const rowRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const row = rowRef.current;
        const active = row?.querySelector<HTMLElement>('[data-active]');

        if (row && active) {
            row.scrollLeft =
                active.offsetLeft - (row.clientWidth - active.offsetWidth) / 2;
        }
    }, [activeTab]);

    return (
        <Tabs value={activeTab} className={cn('min-w-0', className)}>
            <div
                ref={rowRef}
                className="relative -mb-1.5 no-scrollbar max-w-full shrink-0 overflow-x-auto pb-1.5"
            >
                <TabsList variant="line">
                    {tabs.map((tab) => (
                        <TabsTrigger
                            key={tab.value}
                            value={tab.value}
                            className={cn(
                                'flex-none md:min-w-28',
                                triggerClassName,
                            )}
                            nativeButton={false}
                            render={
                                <Link
                                    href={tab.href}
                                    preserveScroll
                                    preserveState
                                    prefetch
                                />
                            }
                        >
                            <tab.icon aria-hidden />
                            {tab.label}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </div>
            <div
                role="tabpanel"
                className={cn(
                    panelWidth === 'full' ? 'w-full' : 'w-full md:w-4/5',
                    panelClassName,
                )}
            >
                {children}
            </div>
        </Tabs>
    );
}
