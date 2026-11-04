import { FileText, MapPin, Palette, Search, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import {
    amenities,
    design,
    general,
    nearbyPlaces,
    seo,
} from '@/routes/settings/booking-website';

export type BookingWebsiteTab =
    | 'general'
    | 'design'
    | 'amenities'
    | 'nearby-places'
    | 'seo';

/**
 * Header and tab links of Settings › Booking website. Each tab is its own page and URL, and loads only its own data.
 */
export function BookingWebsiteShell({
    description,
    badge,
    activeTab,
    children,
}: {
    description: string;
    badge?: ReactNode;
    activeTab: BookingWebsiteTab;
    children: ReactNode;
}) {
    const tabs: TabLink[] = [
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
            value: 'seo',
            label: 'SEO',
            icon: Search,
            href: seo.url(),
        },
    ];

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
                className="flex flex-col gap-4"
            >
                {children}
            </TabLinks>
        </div>
    );
}
