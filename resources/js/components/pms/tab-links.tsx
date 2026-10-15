import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
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
    /** Extra classes for each tab, for example `min-w-0` to size tabs to their label. */
    triggerClassName?: string;
    /** Keep long tab labels reachable on narrow screens. */
    scrollable?: boolean;
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
 */
export function TabLinks({
    tabs,
    activeTab,
    className,
    triggerClassName,
    scrollable = false,
    panelWidth = 'default',
    panelClassName,
    children,
}: Props) {
    const triggers = (
        <TabsList variant="line">
            {tabs.map((tab) => (
                <TabsTrigger
                    key={tab.value}
                    value={tab.value}
                    className={cn('min-w-28 flex-none', triggerClassName)}
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
    );

    return (
        <Tabs value={activeTab} className={cn('min-w-0', className)}>
            {scrollable ? (
                <div className="max-w-full overflow-x-auto pb-1.5">
                    {triggers}
                </div>
            ) : (
                triggers
            )}
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
