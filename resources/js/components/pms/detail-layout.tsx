import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Card, CardContent } from '@/components/ui/card';

type Props = {
    tabs: TabLink[];
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
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                className="order-2 flex flex-col gap-4 lg:order-1 lg:col-span-2"
            >
                {children}
            </TabLinks>
            <Card className="order-1 h-fit lg:order-2">
                <CardContent>{summary}</CardContent>
            </Card>
        </div>
    );
}
