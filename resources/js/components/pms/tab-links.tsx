import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

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
    /** `full` drops the 80% panel width, for a tab with a side-by-side preview. */
    panelWidth?: 'default' | 'full';
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
    panelWidth = 'default',
    children,
}: Props) {
    return (
        <Tabs value={activeTab} className={className}>
            <TabsList variant="line">
                {tabs.map((tab) => (
                    <TabsTrigger
                        key={tab.value}
                        value={tab.value}
                        className="min-w-28 flex-none"
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
            <div
                role="tabpanel"
                className={panelWidth === 'full' ? 'w-full' : 'w-full md:w-4/5'}
            >
                {children}
            </div>
        </Tabs>
    );
}
