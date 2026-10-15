import { Head, usePage } from '@inertiajs/react';
import confetti from 'canvas-confetti';
import { useEffect, useState } from 'react';
import { DashboardAlert } from '@/components/pms/dashboard/dashboard-alert';
import { DashboardTiles } from '@/components/pms/dashboard/dashboard-tiles';
import { QuickActions } from '@/components/pms/dashboard/quick-actions';
import { RecommendedChecklistCard } from '@/components/pms/dashboard/recommended-checklist-card';
import { YoureLiveCard } from '@/components/pms/dashboard/youre-live-card';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { dashboard } from '@/routes';

export default function Dashboard({
    alert,
    can,
    tiles,
    youreLive,
    recommended,
}: {
    alert: App.Data.Dashboard.DashboardAlertData | null;
    can: App.Data.Dashboard.DashboardAccessData;
    /** Deferred: absent on the first load, null when the user sees no tile. */
    tiles?: App.Data.Dashboard.DashboardTilesData | null;
    /** Null for users without staff.manage, and for a property that is not Live. */
    youreLive: App.Data.Onboarding.YoureLiveData | null;
    /** Null for users without settings.manage, and when every item is done or dismissed. */
    recommended: App.Data.Onboarding.RecommendedChecklistData | null;
}) {
    const [auditOpen, setAuditOpen] = useState(false);
    const isImpersonating = useIsImpersonating();
    const wentLive =
        (usePage().flash as { wentLive?: boolean } | undefined)?.wentLive ===
        true;

    useEffect(() => {
        if (!wentLive) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        void confetti({ particleCount: 120, spread: 70, origin: { y: 0.3 } });
        // Once per visit: the flash is only on the page that follows going live.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                {youreLive !== null && (
                    <YoureLiveCard
                        youreLive={youreLive}
                        wentLive={wentLive}
                        canShare={!isImpersonating}
                    />
                )}

                {alert && (
                    <DashboardAlert
                        alert={alert}
                        onOpenAudit={
                            can.canRunNightAudit
                                ? () => setAuditOpen(true)
                                : undefined
                        }
                    />
                )}

                <QuickActions can={can} onRunAudit={() => setAuditOpen(true)} />

                {recommended !== null && (
                    <RecommendedChecklistCard
                        checklist={recommended}
                        canAct={!isImpersonating}
                    />
                )}

                {tiles !== null && <DashboardTiles tiles={tiles} can={can} />}
            </div>

            {can.canRunNightAudit && (
                <NightAuditSheet open={auditOpen} onOpenChange={setAuditOpen} />
            )}
        </>
    );
}

Dashboard.layout = (props: {
    property: App.Data.Property.PropertySummaryData;
}) => ({
    breadcrumbs: [
        {
            title: props.property.name,
            href: dashboard(props.property.code),
        },
    ],
});
