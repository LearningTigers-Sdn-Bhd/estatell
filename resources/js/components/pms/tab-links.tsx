import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

export type TabLink = {
    value: string;
    label: ReactNode;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
};

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
