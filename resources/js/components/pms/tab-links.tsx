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
    /** Content of the active tab. */
    children: ReactNode;
};

/**
 * Page tabs where each tab is a link to its own URL, so the open tab survives a refresh and can be shared.
 * The server sends only the open tab's data. Used by the Detail and Settings archetypes.
 */
export function TabLinks({ tabs, activeTab, className, children }: Props) {
    return (
        <Tabs value={activeTab} className={className}>
            <TabsList>
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
            <div role="tabpanel">{children}</div>
        </Tabs>
    );
}
