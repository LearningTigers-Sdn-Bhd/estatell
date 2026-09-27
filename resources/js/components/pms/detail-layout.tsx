import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

export type DetailTab = {
    value: string;
    label: ReactNode;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
};

type Props = {
    tabs: DetailTab[];
    activeTab: string;
    summary: ReactNode;
    /** Content of the active tab. */
    children: ReactNode;
};

/**
 * Detail archetype body: tabs on the left, summary card on the right (stacked on small screens).
 * Each tab is a link to its own URL, so the open tab survives a refresh and can be shared.
 */
export function DetailLayout({ tabs, activeTab, summary, children }: Props) {
    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Tabs
                value={activeTab}
                className="order-2 flex flex-col gap-4 lg:order-1 lg:col-span-2"
            >
                <TabsList>
                    {tabs.map((tab) => (
                        <TabsTrigger
                            key={tab.value}
                            value={tab.value}
                            className="min-w-28"
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
            <Card className="order-1 h-fit lg:order-2">
                <CardContent>{summary}</CardContent>
            </Card>
        </div>
    );
}
