import { Sailboat, Tent, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { index as facilitiesIndex } from '@/routes/settings/facilities';
import { index as boatsIndex } from '@/routes/settings/facilities/boats';
import { index as guidesIndex } from '@/routes/settings/facilities/guides';

/** The Facilities settings tabs, for the tab bar and the breadcrumbs. */
export function facilitySettingsTabs(): TabLink[] {
    return [
        {
            value: 'facilities',
            label: 'Facilities',
            icon: Tent,
            href: facilitiesIndex(),
        },
        {
            value: 'guides',
            label: 'Guides',
            icon: UserRound,
            href: guidesIndex(),
        },
        { value: 'boats', label: 'Boats', icon: Sailboat, href: boatsIndex() },
    ];
}

/** The Facilities, Guides and Boats tabs of the Facilities settings page. Each tab has its own URL. */
export function FacilitySettingsTabs({
    activeTab,
    children,
}: {
    activeTab: 'facilities' | 'guides' | 'boats';
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            className="flex flex-col gap-4"
            panelWidth="full"
            tabs={facilitySettingsTabs()}
        >
            {children}
        </TabLinks>
    );
}
