import { usePage } from '@inertiajs/react';
import { ExternalLink, LayoutDashboard, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { GUIDE_SECTIONS } from '@/components/pms/concierge/guide-sections';
import { LobbyPrintCard } from '@/components/pms/concierge/lobby-qr';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { Button, buttonVariants } from '@/components/ui/button';
import { profile } from '@/routes/settings';
import {
    amenities,
    contacts,
    faqs,
    hotelInfo,
    index,
    nearbyPlaces,
    policies,
    wifi,
} from '@/routes/settings/concierge';

export type ConciergeTab = 'overview' | (typeof GUIDE_SECTIONS)[number]['key'];

const HREFS: Record<ConciergeTab, () => string> = {
    overview: () => index.url(),
    'hotel-info': () => hotelInfo.url(),
    policies: () => policies.url(),
    faqs: () => faqs.url(),
    amenities: () => amenities.url(),
    'nearby-places': () => nearbyPlaces.url(),
    wifi: () => wifi.url(),
    contacts: () => contacts.url(),
};

/** The URL of a Concierge tab. */
export function conciergeTabUrl(tab: ConciergeTab): string {
    return HREFS[tab]();
}

/** The tabs of Settings › Concierge: the overview, then each section of the guest guide. */
export function conciergeTabs(): TabLink[] {
    return [
        {
            value: 'overview',
            label: 'Overview',
            icon: LayoutDashboard,
            href: conciergeTabUrl('overview'),
        },
        ...GUIDE_SECTIONS.map((section) => ({
            value: section.key,
            label: section.label,
            icon: section.icon,
            href: conciergeTabUrl(section.key),
        })),
    ];
}

/** Settings › Concierge › {tab}. */
export function conciergeBreadcrumbs(activeTab: ConciergeTab) {
    return withTabCrumb(
        [
            { title: 'Settings', href: profile() },
            { title: 'Concierge', href: index() },
        ],
        conciergeTabs(),
        activeTab,
    );
}

/**
 * Header and tab links of Settings › Concierge. Each tab is its own page and URL, and loads only its own data.
 * The header is the same on every tab: open the lobby page or print its QR code. The tab content sits in a
 * page-scroll `Workspace`; a tab may add a right rail with `aside`.
 */
export function ConciergeShell({
    activeTab,
    aside,
    asideLabel,
    children,
}: {
    activeTab: ConciergeTab;
    /** Right rail of the tab, for example the lobby QR or the form to add an item. */
    aside?: ReactNode;
    /** The accessible name of the rail. */
    asideLabel?: string;
    children: ReactNode;
}) {
    const { lobbyUrl, lobbyQr } = usePage<{
        lobbyUrl: string;
        lobbyQr: string;
    }>().props;

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <div className="flex flex-1 flex-col gap-6 print:hidden">
                <PageHeader
                    title="Concierge"
                    description="Set up the guide guests read before and during their stay, and the QR code they scan in the lobby."
                    actions={
                        <>
                            <a
                                href={lobbyUrl}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <ExternalLink data-icon="inline-start" />
                                View lobby
                            </a>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => window.print()}
                            >
                                <Printer data-icon="inline-start" />
                                Print lobby QR
                            </Button>
                        </>
                    }
                />
                <TabLinks
                    tabs={conciergeTabs()}
                    activeTab={activeTab}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:flex-1"
                    panelClassName="flex flex-col lg:flex-1"
                >
                    <Workspace
                        scroll="page"
                        aside={aside}
                        asideLabel={asideLabel}
                    >
                        {children}
                    </Workspace>
                </TabLinks>
            </div>

            <LobbyPrintCard url={lobbyUrl} qr={lobbyQr} />
        </div>
    );
}
