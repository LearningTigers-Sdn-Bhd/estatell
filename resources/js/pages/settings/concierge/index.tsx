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
    GuideProgress,
    GuideReadiness,
    LobbyCheckIn,
} from '@/components/pms/concierge/overview-tab';

/** Concierge › Overview: the guest guide sections and the guest action switches, with the guide progress and the lobby QR in the rail. */
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
    const readiness = guideReadiness({ sections, houseRules });

    return (
        <>
            <Head title="Concierge" />
            <ConciergeShell
                activeTab="overview"
                asideLabel="Guide progress and lobby check-in"
                aside={
                    <div className="flex flex-col gap-4">
                        <GuideProgress items={readiness} />
                        <LobbyCheckIn lobbyUrl={lobbyUrl} lobbyQr={lobbyQr} />
                    </div>
                }
            >
                <PanelStack>
                    <GuideReadiness items={readiness} />
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
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('overview'),
});
