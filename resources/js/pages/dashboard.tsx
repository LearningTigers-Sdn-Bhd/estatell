import { Deferred, Head, router, usePage } from '@inertiajs/react';
import confetti from 'canvas-confetti';
import { CalendarCheck, ChartLine } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DashboardAlert } from '@/components/pms/dashboard/dashboard-alert';
import { DashboardTiles } from '@/components/pms/dashboard/dashboard-tiles';
import { QuickActions } from '@/components/pms/dashboard/quick-actions';
import { RecommendedChecklistCard } from '@/components/pms/dashboard/recommended-checklist-card';
import { YoureLiveCard } from '@/components/pms/dashboard/youre-live-card';
import { NightAuditSheet } from '@/components/pms/night-audit/night-audit-sheet';
import { AuditDialogHost } from '@/components/pms/audit-dialog-host';
import { TodoCard, TodoSkeleton } from '@/components/pms/dashboard/todo-card';
import { TonightTile } from '@/components/pms/dashboard/tonight-tile';
import { TilesSkeleton } from '@/components/pms/dashboard/tiles-skeleton';
import { PerformanceTab } from '@/components/pms/dashboard/performance/performance-tab';
import { TabLinks } from '@/components/pms/tab-links';
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
    tab,
    period,
    performance,
}: {
    tab: App.Enums.DashboardTab;
    /** Only on the Performance tab. */
    period: App.Data.Dashboard.PerformancePeriodData | null;
    /** Deferred, only on the Performance tab. */
    performance?: App.Data.Dashboard.PerformanceSummaryData | null;
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
    const hasTodo = todo !== null;
    const hasTonight =
        can.canViewReservations && tiles !== null && tiles?.tonight !== null;
    const hasStats = tiles !== null || housekeeping !== null;
    /** To do and Tonight share the top row on wide screens; Recommended next sits under To do. */
    const threeColumns = hasTodo && hasTonight;
    const code = page.props.property?.code ?? '';
    const refresh = () =>
        router.reload({
            only:
                tab === 'performance'
                    ? ['performance', 'alert']
                    : ['todo', 'tiles', 'housekeeping', 'alert'],
        });
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

    const content =
        tab === 'performance' && period ? (
            <PerformanceTab period={period} performance={performance} />
        ) : (
            <>
                <QuickActions can={can} onRunAudit={() => setAuditOpen(true)} />

                {hasStats && (
                    <DashboardTiles
                        tiles={tiles}
                        can={can}
                        housekeeping={housekeeping}
                    />
                )}

                <div
                    className={cn(
                        'grid gap-4',
                        hasTonight && 'md:grid-cols-2',
                        threeColumns && 'xl:grid-cols-3',
                    )}
                >
                    {hasTodo && (
                        <div
                            className={cn(
                                'flex min-w-0 flex-col',
                                hasTonight && 'md:col-span-2',
                                threeColumns &&
                                    'xl:col-span-2 xl:col-start-1 xl:row-start-1',
                            )}
                        >
                            <Deferred data="todo" fallback={<TodoSkeleton />}>
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
                        </div>
                    )}
                    {hasTonight && (
                        <div
                            className={cn(
                                'flex min-w-0 flex-col',
                                recommended === null && 'md:col-span-2',
                                threeColumns &&
                                    'xl:col-span-1 xl:col-start-3 xl:row-start-1',
                            )}
                        >
                            <Deferred
                                data="tiles"
                                fallback={
                                    <TilesSkeleton units={false} reports />
                                }
                            >
                                {tiles?.tonight && (
                                    <TonightTile tile={tiles.tonight} />
                                )}
                            </Deferred>
                        </div>
                    )}
                    {recommended !== null && (
                        <div
                            className={cn(
                                'flex min-w-0 flex-col',
                                threeColumns &&
                                    'xl:col-span-2 xl:col-start-1 xl:row-start-2',
                            )}
                        >
                            <RecommendedChecklistCard
                                key={page.props.property?.code}
                                storageKey={`estatell:dashboard:recommended:v1:${page.props.property?.code}`}
                                checklist={recommended}
                                canAct={!isImpersonating}
                            />
                        </div>
                    )}
                </div>
            </>
        );

    return (
        <>
            <Head title="Dashboard" />

            <div
                scroll-region
                className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain p-4"
            >
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

                {can.canViewReports ? (
                    <TabLinks
                        activeTab={tab}
                        className="flex flex-col gap-4"
                        panelWidth="full"
                        panelClassName="flex flex-col gap-4"
                        tabs={[
                            {
                                value: 'today',
                                label: 'Today',
                                icon: CalendarCheck,
                                href: dashboard.url(code),
                            },
                            {
                                value: 'performance',
                                label: 'Performance',
                                icon: ChartLine,
                                href: dashboard.url(code, {
                                    query: { tab: 'performance' },
                                }),
                            },
                        ]}
                    >
                        {content}
                    </TabLinks>
                ) : (
                    content
                )}
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
    variant: 'fixed' as const,
    breadcrumbs: [
        {
            title: props.property.name,
            href: dashboard(props.property.code),
        },
    ],
});
