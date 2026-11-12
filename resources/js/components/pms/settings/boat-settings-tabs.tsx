import { Anchor, Ship, Ticket, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { index as boatsIndex } from '@/routes/settings/boats/boats';
import { index as crewIndex } from '@/routes/settings/boats/crew';
import { index as departuresIndex } from '@/routes/settings/boats/departures';
import { index as faresIndex } from '@/routes/settings/boats/fares';

/** The Boats settings tabs, for the tab bar and the breadcrumbs. */
export function boatSettingsTabs(): TabLink[] {
    return [
        {
            value: 'departures',
            label: 'Departures',
            icon: Ship,
            href: departuresIndex(),
        },
        {
            value: 'fares',
            label: 'Fares',
            icon: Ticket,
            href: faresIndex(),
        },
        {
            value: 'boats',
            label: 'Boats',
            icon: Anchor,
            href: boatsIndex(),
        },
        {
            value: 'crew',
            label: 'Crew',
            icon: Users,
            href: crewIndex(),
        },
    ];
}

/** The Departures, Fares, Boats and Crew tabs of the Boats settings page. Each tab has its own URL. */
export function BoatSettingsTabs({
    activeTab,
    children,
}: {
    activeTab: 'departures' | 'fares' | 'boats' | 'crew';
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            className="flex flex-col gap-4"
            panelWidth="full"
            tabs={boatSettingsTabs()}
        >
            {children}
        </TabLinks>
    );
}
