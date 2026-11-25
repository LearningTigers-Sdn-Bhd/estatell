import { Head } from '@inertiajs/react';
import {
    BookOpen,
    ChefHat,
    Boxes,
    KeyRound,
    Layers,
    CalendarClock,
    LayoutGrid,
    Plug,
    Store,
    UtensilsCrossed,
    Utensils,
    Webhook,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { profile } from '@/routes/settings';
import { index as posApiClientsIndex } from '@/routes/settings/pos-api-clients';
import { index as posIntegrationsIndex } from '@/routes/settings/pos-integrations';
import { index as posOutletsIndex } from '@/routes/settings/pos-outlets';
import { index as nativeDiningIndex } from '@/routes/settings/pos/dining-options';
import { index as nativeFloorsIndex } from '@/routes/settings/pos/floors';
import { index as nativeKitchenIndex } from '@/routes/settings/pos/kitchen';
import { index as nativeInventoryIndex } from '@/routes/settings/pos/inventory';
import { index as nativeSchedulesIndex } from '@/routes/settings/pos/schedules';
import { index as nativeMenuIndex } from '@/routes/settings/pos/menu';
import { index as nativeModifiersIndex } from '@/routes/settings/pos/modifiers';
import { index as nativeOutletsIndex } from '@/routes/settings/pos/outlets';
import { index as nativeStaffIndex } from '@/routes/settings/pos/staff-pins';
import type { BreadcrumbItem } from '@/types';

export type PosSettingsTab =
    | 'outlets'
    | 'integrations'
    | 'api'
    | 'registers'
    | 'menu'
    | 'modifiers'
    | 'dining'
    | 'floors'
    | 'kitchen'
    | 'inventory'
    | 'schedules'
    | 'staff';

const tabTitles: Record<PosSettingsTab, string> = {
    outlets: 'POS outlets',
    integrations: 'POS integrations',
    api: 'POS API access',
    registers: 'Registers and accounts',
    menu: 'Menu',
    modifiers: 'Modifiers',
    dining: 'Dining options',
    floors: 'Floor plan',
    kitchen: 'Kitchen stations',
    inventory: 'Inventory and recipes',
    schedules: 'Menu schedules and combos',
    staff: 'Staff PINs and terminals',
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
        {
            value: 'registers',
            label: 'Registers',
            icon: Store,
            href: nativeOutletsIndex(),
        },
        {
            value: 'menu',
            label: 'Menu',
            icon: BookOpen,
            href: nativeMenuIndex(),
        },
        {
            value: 'modifiers',
            label: 'Modifiers',
            icon: Layers,
            href: nativeModifiersIndex(),
        },
        {
            value: 'dining',
            label: 'Dining options',
            icon: UtensilsCrossed,
            href: nativeDiningIndex(),
        },
        {
            value: 'schedules',
            label: 'Schedules and combos',
            icon: CalendarClock,
            href: nativeSchedulesIndex(),
        },
        {
            value: 'floors',
            label: 'Floor plan',
            icon: LayoutGrid,
            href: nativeFloorsIndex(),
        },
        {
            value: 'kitchen',
            label: 'Kitchen stations',
            icon: ChefHat,
            href: nativeKitchenIndex(),
        },
        {
            value: 'inventory',
            label: 'Inventory',
            icon: Boxes,
            href: nativeInventoryIndex(),
        },
        {
            value: 'staff',
            label: 'Staff and terminals',
            icon: KeyRound,
            href: nativeStaffIndex(),
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
                    description="Outlets, menu, registers and staff PINs for the native POS, plus the Loyverse connection and API tokens for charging bills to a guest's room."
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
