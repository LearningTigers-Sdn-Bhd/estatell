import { Deferred, Head, router, usePage } from '@inertiajs/react';
import confetti from 'canvas-confetti';
import { useEffect, useState } from 'react';
import { DashboardAlert } from '@/components/pms/dashboard/dashboard-alert';
import { DashboardTiles } from '@/components/pms/dashboard/dashboard-tiles';
import { QuickActions } from '@/components/pms/dashboard/quick-actions';
import { RecommendedChecklistCard } from '@/components/pms/dashboard/recommended-checklist-card';
import { YoureLiveCard } from '@/components/pms/dashboard/youre-live-card';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import { AuditDialogHost } from '@/components/pms/audit-dialog-host';
import { TodoCard, TodoSkeleton } from '@/components/pms/dashboard/todo-card';
import { cn } from 'cn';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { dashboard } from '@/routes';

export default function Dashboard({
    alert,
    can,
    tiles,
    youreLive,
    recommended,
    todo,
    housekeeping,
}: {
    alert: App.Data.Dashboard.DashboardAlertData | null;
    can: App.Data.Dashboard.DashboardAccessData;
    /** Deferred: absent on the first load, null when the user sees no tile. */
    tiles?: App.Data.Dashboard.DashboardTilesData | null;
    /** Null for users without staff.manage, and for a property that is not Live. */
    youreLive: App.Data.Onboarding.YoureLiveData | null;
    /** Null for users without settings.manage, and when every item is done or dismissed. */
    recommended: App.Data.Onboarding.RecommendedChecklistData | null;
    todo?: App.Data.Dashboard.DashboardTodoData | null;
    housekeeping?: App.Data.Dashboard.HousekeepingTileData | null;
}) {
    const [auditOpen, setAuditOpen] = useState(false);
    const [action, setAction] =
        useState<App.Data.NightAudit.ReadinessActionData | null>(null);
    const [loadingKey, setLoadingKey] = useState<string | null>(null);
    const page = usePage();
    const hasLeft = todo !== null || recommended !== null;
    const hasRight = tiles !== null || housekeeping !== null;
    const refresh = () =>
        router.reload({ only: ['todo', 'tiles', 'housekeeping', 'alert'] });
    const closeAction = () => {
        setAction(null);
        setLoadingKey(null);
        refresh();
    };
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

                <div
                    className={cn(
                        'grid items-stretch gap-4',
                        hasLeft && hasRight && 'lg:grid-cols-9',
                    )}
                >
                    {hasLeft && (
                        <div
                            className={cn(
                                'order-2 flex min-w-0 flex-col gap-4 lg:order-1',
                                hasRight && 'lg:col-span-5',
                            )}
                        >
                            {todo !== null && (
                                <Deferred
                                    data="todo"
                                    fallback={<TodoSkeleton />}
                                >
                                    {todo && (
                                        <TodoCard
                                            todo={todo}
                                            loadingKey={loadingKey}
                                            onAction={(row) => {
                                                setLoadingKey(row.key);
                                                setAction(row.action);
                                            }}
                                        />
                                    )}
                                </Deferred>
                            )}
                            {recommended !== null && (
                                <RecommendedChecklistCard
                                    key={page.props.property?.code}
                                    storageKey={`estatell:dashboard:recommended:v1:${page.props.property?.code}`}
                                    checklist={recommended}
                                    canAct={!isImpersonating}
                                />
                            )}
                        </div>
                    )}
                    {hasRight && (
                        <div
                            className={cn(
                                'order-1 min-w-0 lg:order-2',
                                hasLeft && 'lg:col-span-4',
                            )}
                        >
                            <DashboardTiles
                                tiles={tiles}
                                can={can}
                                housekeeping={housekeeping}
                            />
                        </div>
                    )}
                </div>
            </div>

            {can.canRunNightAudit && (
                <NightAuditSheet
                    open={auditOpen}
                    onOpenChange={(open) => {
                        setAuditOpen(open);
                        if (!open) refresh();
                    }}
                />
            )}
            <AuditDialogHost
                action={action}
                onLoaded={() => {
                    setLoadingKey(null);
                }}
                onClose={closeAction}
                onNavigate={() => {
                    setAction(null);
                    setLoadingKey(null);
                }}
            />
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
