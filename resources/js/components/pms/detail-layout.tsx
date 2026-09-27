import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

export type DetailTab = {
    value: string;
    label: ReactNode;
    content: ReactNode;
};

type Props = {
    tabs: DetailTab[];
    summary: ReactNode;
    defaultTab?: string;
};

/**
 * Detail archetype body: tabs on the left, summary card on the right (stacked on small screens).
 */
export function DetailLayout({ tabs, summary, defaultTab }: Props) {
    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Tabs
                defaultValue={defaultTab ?? tabs[0]?.value}
                className="order-2 flex flex-col gap-4 lg:order-1 lg:col-span-2"
            >
                <TabsList>
                    {tabs.map((tab) => (
                        <TabsTrigger key={tab.value} value={tab.value}>
                            {tab.label}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {tabs.map((tab) => (
                    <TabsContent key={tab.value} value={tab.value}>
                        {tab.content}
                    </TabsContent>
                ))}
            </Tabs>
            <Card className="order-1 h-fit lg:order-2">
                <CardContent>{summary}</CardContent>
            </Card>
        </div>
    );
}
