import { Bus, Route, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { index as driversIndex } from '@/routes/settings/transfers/drivers';
import { index as routesIndex } from '@/routes/settings/transfers/routes';
import { index as vehiclesIndex } from '@/routes/settings/transfers/vehicles';

/** The Transfers settings tabs, for the tab bar and the breadcrumbs. */
export function transferSettingsTabs(): TabLink[] {
    return [
        { value: 'routes', label: 'Routes', icon: Route, href: routesIndex() },
        {
            value: 'vehicles',
            label: 'Vehicles',
            icon: Bus,
            href: vehiclesIndex(),
        },
        {
            value: 'drivers',
            label: 'Drivers',
            icon: UserRound,
            href: driversIndex(),
        },
    ];
}

/** The Routes, Vehicles and Drivers tabs of the Transfers settings page. Each tab has its own URL. */
export function TransferSettingsTabs({
    activeTab,
    children,
}: {
    activeTab: 'routes' | 'vehicles' | 'drivers';
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            className="flex flex-col gap-4"
            panelWidth="full"
            tabs={transferSettingsTabs()}
        >
            {children}
        </TabLinks>
    );
}
