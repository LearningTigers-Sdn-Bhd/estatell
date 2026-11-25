import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    CircleCheck,
    ClipboardList,
    History,
    Percent,
    TrendingUp,
} from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DashboardBadge } from '@/components/pms/dashboard/dashboard-badge';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes/admin';
import { index as commissions } from '@/routes/admin/commissions';
import { index as partners } from '@/routes/admin/partners';
import {
    index as properties,
    show as property,
} from '@/routes/admin/properties';

type Summary = App.Data.Platform.AdminDashboardSummaryData;
type Todo = App.Data.Platform.AdminDashboardTodoData;

/** One skeleton card while a deferred prop loads. */
function CardSkeleton({
    rows,
    className,
}: {
    rows: number;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader>
                <Skeleton className="h-5 w-32" />
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {Array.from({ length: rows }, (_, key) => (
                    <Skeleton key={key} className="h-4 w-2/3" />
                ))}
            </CardContent>
        </Card>
    );
}

/** Properties by status: four link tiles, each opening the Properties list on that status. */
function StatusTiles({ summary }: { summary: Summary }) {
    const tiles: {
        status: App.Enums.PropertyStatus;
        title: string;
        count: number;
        tone: 'primary' | 'neutral' | 'warning';
    }[] = [
        { status: 'live', title: 'Live', count: summary.live, tone: 'primary' },
        {
            status: 'onboarding',
            title: 'Onboarding',
            count: summary.onboarding,
            tone: 'neutral',
        },
        {
            status: 'pending_approval',
            title: 'Pending approval',
            count: summary.pendingApproval,
            tone: 'warning',
        },
        {
            status: 'suspended',
            title: 'Suspended',
            count: summary.suspended,
            tone: 'neutral',
        },
    ];

    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {tiles.map((tile) => (
                <TileCard
                    key={tile.status}
                    title={tile.title}
                    href={properties({
                        query: { filter: { status: tile.status } },
                    })}
                    icon={Building2}
                    tone={tile.tone}
                >
                    <p className="font-heading text-2xl font-semibold tabular-nums">
                        {tile.count}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {tile.count === 1 ? 'property' : 'properties'}
                    </p>
                </TileCard>
            ))}
        </div>
    );
}

/** What needs an admin now. Each row links to the list filtered to it. */
function TodoCard({ todo }: { todo: Todo }) {
    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ClipboardList
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    To do
                </CardTitle>
                <CardAction>
                    <Badge variant="outline" className="tabular-nums">
                        {todo.total} {todo.total === 1 ? 'item' : 'items'}
                    </Badge>
                </CardAction>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                {todo.rows.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center px-(--card-spacing)">
                        <EmptyState
                            icon={CircleCheck}
                            title="Nothing needs you right now"
                            description="Approvals, enquiries and errors show here when they come in."
                        />
                    </div>
                ) : (
                    <ul className="divide-y border-y">
                        {todo.rows.map((row) => (
                            <li
                                key={row.key}
                                className="relative flex items-center gap-3 px-(--card-spacing) py-3 transition-colors hover:bg-muted/50"
                            >
                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                    <DashboardBadge
                                        label={String(row.count)}
                                        tone={row.tone}
                                    />
                                    <p className="min-w-0 truncate">
                                        {row.label}
                                        {row.detail && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {row.detail}
                                            </span>
                                        )}
                                    </p>
                                </div>
                                <Link
                                    href={row.href}
                                    className={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                        className:
                                            'after:absolute after:inset-0',
                                    })}
                                >
                                    Open
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/** New properties and active partners this month, and the commission figures. */
function SideTiles({ summary }: { summary: Summary }) {
    const { commissions: totals } = summary;

    return (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
            <TileCard
                title="This month"
                href={partners()}
                icon={TrendingUp}
                tone="soft-primary"
            >
                <p className="font-heading text-2xl font-semibold tabular-nums">
                    {summary.newThisMonth}
                </p>
                <p className="text-sm text-muted-foreground">
                    new {summary.newThisMonth === 1 ? 'property' : 'properties'}
                    , {summary.activePartners} active{' '}
                    {summary.activePartners === 1 ? 'partner' : 'partners'}
                </p>
            </TileCard>
            <TileCard
                title="Commissions"
                href={commissions()}
                icon={Percent}
                tone="neutral"
            >
                <p className="font-heading text-2xl font-semibold tabular-nums">
                    <MoneyText
                        amount={totals.immediatePayable}
                        currency={totals.currency}
                    />
                </p>
                <p className="text-sm text-muted-foreground tabular-nums">
                    to pay on <DateText date={totals.payableOn} />
                </p>
                <p className="text-sm text-muted-foreground tabular-nums">
                    <MoneyText
                        amount={totals.owedToEstatell}
                        currency={totals.currency}
                    />{' '}
                    owed to Estatell
                </p>
            </TileCard>
        </div>
    );
}

type RecentRow = App.Data.Platform.AdminRecentPropertyData;

const column = dataTableColumns<RecentRow>();

const recentColumns = [
    column.accessor('code', {
        header: 'Code',
        enableSorting: false,
        cell: (info) => (
            <span className="text-muted-foreground tabular-nums">
                {info.getValue()}
            </span>
        ),
    }),
    column.accessor('name', {
        header: 'Property',
        enableSorting: false,
        cell: (info) => (
            <span className="block max-w-80 truncate font-medium">
                {info.getValue()}
            </span>
        ),
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('event', {
        header: 'Last change',
        enableSorting: false,
    }),
    column.accessor('at', {
        header: 'Date',
        enableSorting: false,
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

/** The latest properties by sign-up or go-live. A click on a row opens the property. */
function RecentTable({ recent }: { recent: RecentRow[] }) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 font-heading font-medium">
                    <History
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    Recent properties
                </h2>
                <Link
                    href={properties()}
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'sm',
                    })}
                >
                    View all
                    <ArrowRight data-icon="inline-end" />
                </Link>
            </div>
            <DataTable
                columns={recentColumns}
                data={recent}
                getRowId={(row) => row.code}
                sort=""
                onSortChange={() => {}}
                onRowClick={(row) => router.visit(property(row.code))}
                pagination={{
                    currentPage: 1,
                    lastPage: 1,
                    from: recent.length > 0 ? 1 : null,
                    to: recent.length > 0 ? recent.length : null,
                    total: recent.length,
                    prevUrl: null,
                    nextUrl: null,
                }}
                empty={
                    <EmptyState icon={Building2} title="No properties yet" />
                }
            />
        </section>
    );
}

export default function AdminDashboard({
    todo,
    summary,
}: {
    /** Deferred. */
    todo?: Todo;
    /** Deferred. */
    summary?: Summary;
}) {
    return (
        <>
            <Head title="Admin" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Dashboard"
                    description="What needs you, and how the platform is doing."
                />

                <Deferred
                    data="summary"
                    fallback={
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {[0, 1, 2, 3].map((key) => (
                                <CardSkeleton key={key} rows={2} />
                            ))}
                        </div>
                    }
                >
                    {summary && <StatusTiles summary={summary} />}
                </Deferred>

                <div className="grid gap-3 xl:grid-cols-3">
                    <div className="min-w-0 xl:col-span-2">
                        <Deferred
                            data="todo"
                            fallback={
                                <CardSkeleton rows={5} className="h-full" />
                            }
                        >
                            {todo && <TodoCard todo={todo} />}
                        </Deferred>
                    </div>
                    <Deferred
                        data="summary"
                        fallback={<CardSkeleton rows={4} />}
                    >
                        {summary && <SideTiles summary={summary} />}
                    </Deferred>
                </div>

                <Deferred data="summary" fallback={<CardSkeleton rows={5} />}>
                    {summary && <RecentTable recent={summary.recent} />}
                </Deferred>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: dashboard(),
        },
    ],
};
