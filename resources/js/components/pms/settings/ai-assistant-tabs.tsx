import { KeyRound, MapPin, MessageSquareShare, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { profile } from '@/routes/settings';
import {
    capabilities,
    handover,
    index,
    key,
} from '@/routes/settings/ai-assistant';

export type AiAssistantTab = 'places' | 'capabilities' | 'handover' | 'key';

/** The tabs of Settings › AI assistant. Usage and Connections come with their checklist items. */
export function aiAssistantTabs(): TabLink[] {
    return [
        { value: 'places', label: 'Places', icon: MapPin, href: index() },
        {
            value: 'capabilities',
            label: 'Capabilities',
            icon: Sparkles,
            href: capabilities(),
        },
        {
            value: 'handover',
            label: 'Handover',
            icon: MessageSquareShare,
            href: handover(),
        },
        { value: 'key', label: 'API key', icon: KeyRound, href: key() },
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

/** The header and tab bar of Settings › AI assistant. Each tab is its own page and URL. */
export function AiAssistantShell({
    activeTab,
    children,
}: {
    activeTab: AiAssistantTab;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title="AI assistant"
                description="A chat that answers guests and staff from your Concierge content, and hands over to staff when it cannot help."
            />
            <TabLinks
                activeTab={activeTab}
                className="flex flex-col gap-4"
                panelWidth="full"
                tabs={aiAssistantTabs()}
            >
                {children}
            </TabLinks>
        </div>
    );
}
