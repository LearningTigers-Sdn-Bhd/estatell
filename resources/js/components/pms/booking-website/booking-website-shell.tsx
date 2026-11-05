import {
    FileText,
    KeyRound,
    MapPin,
    Palette,
    Search,
    Sparkles,
    Tag,
} from 'lucide-react';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import type { TabLink } from '@/components/pms/tab-links';
import {
    amenities,
    api,
    design,
    general,
    nearbyPlaces,
    rates,
    seo,
} from '@/routes/settings/booking-website';

export type BookingWebsiteTab =
    | 'general'
    | 'design'
    | 'amenities'
    | 'nearby-places'
    | 'rates'
    | 'seo'
    | 'api';

/**
 * Header and tab links of Settings › Booking website. Each tab is its own page and URL, and loads only its own data.
 * Design and SEO belong to Microsite mode, API to API only mode.
 * The tab content sits in a page-scroll `Workspace`; a tab may add a right rail with `aside`.
 */
export function BookingWebsiteShell({
    description,
    badge,
    activeTab,
    aside,
    asideLabel,
    children,
}: {
    description: string;
    badge?: ReactNode;
    activeTab: BookingWebsiteTab;
    /** Right rail of the tab, for example the publish checklist or the Google preview. */
    aside?: ReactNode;
    /** The accessible name of the rail. */
    asideLabel?: string;
    children: ReactNode;
}) {
    const apiOnly = usePage().props.bookingMode === 'api-only';
    const allTabs: (TabLink & { mode?: 'microsite' | 'api-only' })[] = [
        {
            value: 'general',
            label: 'General',
            icon: FileText,
            href: general.url(),
        },
        {
            value: 'design',
            label: 'Design',
            icon: Palette,
            href: design.url(),
            mode: 'microsite',
        },
        {
            value: 'amenities',
            label: 'Amenities',
            icon: Sparkles,
            href: amenities.url(),
        },
        {
            value: 'nearby-places',
            label: 'Nearby places',
            icon: MapPin,
            href: nearbyPlaces.url(),
        },
        {
            value: 'rates',
            label: 'Rates',
            icon: Tag,
            href: rates.url(),
        },
        {
            value: 'seo',
            label: 'SEO',
            icon: Search,
            href: seo.url(),
            mode: 'microsite',
        },
        {
            value: 'api',
            label: 'API',
            icon: KeyRound,
            href: api.url(),
            mode: 'api-only',
        },
    ];
    const tabs: TabLink[] = allTabs
        .filter(
            (tab) =>
                tab.mode === undefined ||
                tab.mode === (apiOnly ? 'api-only' : 'microsite'),
        )
        .map(({ mode: _mode, ...tab }) => tab);

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title="Booking website"
                description={description}
                badge={badge}
            />
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                panelWidth="full"
                className="flex flex-col gap-4 lg:flex-1"
                panelClassName="flex flex-col lg:flex-1"
            >
                <Workspace scroll="page" aside={aside} asideLabel={asideLabel}>
                    {children}
                </Workspace>
            </TabLinks>
        </div>
    );
}
