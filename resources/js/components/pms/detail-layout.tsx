import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Props = {
    tabs: TabLink[];
    activeTab: string;
    /** Side card. Without it, the tabs take the full width. */
    summary?: ReactNode;
    /** `full` drops the 80% tab panel width, for a tab that lays out its own columns. */
    panelWidth?: 'default' | 'full';
    /** Content of the active tab. */
    children: ReactNode;
};

/**
 * Detail archetype body: tabs on the left, optional summary card on the right (stacked on small screens).
 * Each tab is a link to its own URL, so the open tab survives a refresh and can be shared.
 */
export function DetailLayout({
    tabs,
    activeTab,
    summary,
    panelWidth,
    children,
}: Props) {
    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                panelWidth={panelWidth}
                className={cn(
                    'flex flex-col gap-4',
                    summary
                        ? 'order-2 lg:order-1 lg:col-span-2'
                        : 'lg:col-span-3',
                )}
            >
                {children}
            </TabLinks>
            {summary && (
                <Card className="order-1 h-fit lg:order-2">
                    <CardContent>{summary}</CardContent>
                </Card>
            )}
        </div>
    );
}
