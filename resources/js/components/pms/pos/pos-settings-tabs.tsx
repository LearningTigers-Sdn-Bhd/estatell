import { Head } from '@inertiajs/react';
import { Plug, Utensils, Webhook } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { profile } from '@/routes/settings';
import { index as posApiClientsIndex } from '@/routes/settings/pos-api-clients';
import { index as posIntegrationsIndex } from '@/routes/settings/pos-integrations';
import { index as posOutletsIndex } from '@/routes/settings/pos-outlets';
import type { BreadcrumbItem } from '@/types';

export type PosSettingsTab = 'outlets' | 'integrations' | 'api';

const tabTitles: Record<PosSettingsTab, string> = {
    outlets: 'POS outlets',
    integrations: 'POS integrations',
    api: 'POS API access',
};

export function posSettingsTabs(): TabLink[] {
    return [
        {
            value: 'outlets',
            label: 'Outlets',
            icon: Utensils,
            href: posOutletsIndex(),
        },
        {
            value: 'integrations',
            label: 'Integrations',
            icon: Plug,
            href: posIntegrationsIndex(),
        },
        {
            value: 'api',
            label: 'API access',
            icon: Webhook,
            href: posApiClientsIndex(),
        },
    ];
}

export function posSettingsBreadcrumbs(
    activeTab: PosSettingsTab,
): BreadcrumbItem[] {
    return withTabCrumb(
        [
            { title: 'Settings', href: profile() },
            { title: 'POS', href: posOutletsIndex() },
        ],
        posSettingsTabs(),
        activeTab,
    );
}

export function PosSettingsShell({
    activeTab,
    children,
}: {
    activeTab: PosSettingsTab;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={tabTitles[activeTab]} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS"
                    description="Outlets, Loyverse connection, and API tokens for charging restaurant or ancillary bills to a guest's room."
                />

                <TabLinks
                    activeTab={activeTab}
                    className="flex flex-col gap-6"
                    panelWidth="full"
                    panelClassName="flex flex-col gap-6"
                    tabs={posSettingsTabs()}
                >
                    {children}
                </TabLinks>
            </div>
        </>
    );
}
