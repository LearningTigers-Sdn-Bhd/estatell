import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { GuestActionsCard } from '@/components/pms/concierge/guest-actions-card';
import { PanelStack } from '@/components/pms/concierge/guide-layout';
import {
    guideReadiness,
    type Sections,
} from '@/components/pms/concierge/guide-sections';
import {
    GuideReadiness,
    LobbyCheckIn,
} from '@/components/pms/concierge/overview-tab';

/** Concierge › Overview: how complete the guest guide is and the guest action switches, with the lobby QR in the rail. */
export default function ConciergeOverview({
    lobbyUrl,
    lobbyQr,
    houseRules,
    walkInEnabled,
    refundRequestsEnabled,
    locationCheckEnabled,
    hasLocation,
    sections,
}: {
    lobbyUrl: string;
    lobbyQr: string;
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    locationCheckEnabled: boolean;
    hasLocation: boolean;
    sections: Sections;
}) {
    return (
        <>
            <Head title="Concierge" />
            <ConciergeShell
                activeTab="overview"
                asideLabel="Lobby check-in"
                aside={<LobbyCheckIn lobbyUrl={lobbyUrl} lobbyQr={lobbyQr} />}
            >
                <PanelStack>
                    <GuideReadiness
                        items={guideReadiness({ sections, houseRules })}
                    />
                    <GuestActionsCard
                        houseRules={houseRules}
                        walkInEnabled={walkInEnabled}
                        refundRequestsEnabled={refundRequestsEnabled}
                        locationCheckEnabled={locationCheckEnabled}
                        hasLocation={hasLocation}
                    />
                </PanelStack>
            </ConciergeShell>
        </>
    );
}

ConciergeOverview.layout = () => ({
    breadcrumbs: conciergeBreadcrumbs('overview'),
});
