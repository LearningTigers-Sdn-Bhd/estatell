import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardAlert } from '@/components/pms/dashboard/dashboard-alert';
import { DashboardTiles } from '@/components/pms/dashboard/dashboard-tiles';
import { QuickActions } from '@/components/pms/dashboard/quick-actions';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import { dashboard } from '@/routes';

export default function Dashboard({
    alert,
    can,
    tiles,
}: {
    alert: App.Data.Dashboard.DashboardAlertData | null;
    can: App.Data.Dashboard.DashboardAccessData;
    /** Deferred: absent on the first load, null when the user sees no tile. */
    tiles?: App.Data.Dashboard.DashboardTilesData | null;
}) {
    const [auditOpen, setAuditOpen] = useState(false);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-4 p-4">
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
