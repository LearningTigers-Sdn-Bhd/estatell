import { MapPin, Settings2, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { AiAssistantStatusRail } from '@/components/pms/settings/ai-assistant-status';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { profile } from '@/routes/settings';
import { capabilities, general, index } from '@/routes/settings/ai-assistant';

export type AiAssistantTab = 'places' | 'capabilities' | 'general';

/** The tabs of Settings › AI assistant. Usage and Connections come with their checklist items. */
export function aiAssistantTabs(): TabLink[] {
    return [
        {
            value: 'general',
            label: 'General',
            icon: Settings2,
            href: general(),
        },
        { value: 'places', label: 'Places', icon: MapPin, href: index() },
        {
            value: 'capabilities',
            label: 'Capabilities',
            icon: Sparkles,
            href: capabilities(),
        },
    ];
}

/** Settings › AI assistant › {tab}. */
export function aiAssistantBreadcrumbs(activeTab: AiAssistantTab) {
    return withTabCrumb(
        [
            { title: 'Settings', href: profile() },
            { title: 'AI assistant', href: index() },
        ],
        aiAssistantTabs(),
        activeTab,
    );
}

/**
 * The header, tab bar and status rail of Settings › AI assistant. Each tab is its own page and URL. The tab content
 * sits in a fixed-height `Workspace` like Settings › Concierge: the tab and the rail each scroll on their own. Every
 * tab shows the same status rail. Each page sets the layout `variant: 'fixed'`.
 */
export function AiAssistantShell({
    activeTab,
    status,
    children,
}: {
    activeTab: AiAssistantTab;
    status: App.Data.AiAssistant.AiAssistantStatusData;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
            <div className="flex min-h-0 flex-1 flex-col gap-6">
                <PageHeader
                    title="AI assistant"
                    description="A chat that answers guests and staff from your Concierge content, and hands over to staff when it cannot help."
                />
                <TabLinks
                    tabs={aiAssistantTabs()}
                    activeTab={activeTab}
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <Workspace
                        asideLabel="AI assistant status"
                        aside={
                            <AiAssistantStatusRail
                                status={status}
                                showKeyLink={activeTab !== 'general'}
                            />
                        }
                    >
                        {children}
                    </Workspace>
                </TabLinks>
            </div>
        </div>
    );
}
