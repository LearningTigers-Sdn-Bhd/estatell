import { Anchor, Ship, Ticket } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import { index as boatsIndex } from '@/routes/settings/boats/boats';
import { index as departuresIndex } from '@/routes/settings/boats/departures';
import { index as faresIndex } from '@/routes/settings/boats/fares';

/** The Departures, Fares and Boats tabs of the Boats settings page. Each tab has its own URL. */
export function BoatSettingsTabs({
    activeTab,
    children,
}: {
    activeTab: 'departures' | 'fares' | 'boats';
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            className="flex flex-col gap-4"
            panelWidth="full"
            tabs={[
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
            ]}
        >
            {children}
        </TabLinks>
    );
}
