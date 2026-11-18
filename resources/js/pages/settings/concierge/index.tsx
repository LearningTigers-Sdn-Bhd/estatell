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

/** Concierge › Overview: how complete the guest guide is, the lobby QR and the guest action switches. */
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
                asideLabel="Lobby and guest actions"
                aside={
                    <PanelStack>
                        <LobbyCheckIn lobbyUrl={lobbyUrl} lobbyQr={lobbyQr} />
                        <GuestActionsCard
                            houseRules={houseRules}
                            walkInEnabled={walkInEnabled}
                            refundRequestsEnabled={refundRequestsEnabled}
                            locationCheckEnabled={locationCheckEnabled}
                            hasLocation={hasLocation}
                        />
                    </PanelStack>
                }
            >
                <GuideReadiness
                    items={guideReadiness({ sections, houseRules })}
                />
            </ConciergeShell>
        </>
    );
}

ConciergeOverview.layout = () => ({
    breadcrumbs: conciergeBreadcrumbs('overview'),
});
