import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    CircleCheck,
    ClipboardList,
    History,
    Percent,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Label,
    Line,
    LineChart,
    Pie,
    PieChart,
    XAxis,
    YAxis,
} from 'recharts';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DashboardBadge } from '@/components/pms/dashboard/dashboard-badge';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { formatMoney, MoneyText } from '@/components/pms/money-text';
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
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
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

const STATUS_SLICES: {
    status: App.Enums.PropertyStatus;
    label: string;
    key: 'live' | 'onboarding' | 'pendingApproval' | 'suspended';
    color: string;
}[] = [
    { status: 'live', label: 'Live', key: 'live', color: 'var(--success)' },
    {
        status: 'onboarding',
        label: 'Onboarding',
        key: 'onboarding',
        color: 'var(--info)',
    },
    {
        status: 'pending_approval',
        label: 'Pending approval',
        key: 'pendingApproval',
        color: 'var(--warning)',
    },
    {
        status: 'suspended',
        label: 'Suspended',
        key: 'suspended',
        color: 'var(--destructive)',
    },
];

const statusConfig = {
    count: { label: 'Properties' },
} satisfies ChartConfig;

/**
 * Properties by status as a donut with the total in the middle. Each legend row names its status and links to the
 * Properties list on it, so color never carries the meaning alone.
 */
function PropertiesCard({ summary }: { summary: Summary }) {
    const slices = STATUS_SLICES.map((slice) => ({
        ...slice,
        count: summary[slice.key],
    }));
    const total = slices.reduce((sum, slice) => sum + slice.count, 0);

    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Building2
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <Link
                        href={properties()}
                        className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Properties
                    </Link>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col items-center gap-4 sm:flex-row">
                <ChartContainer
                    config={statusConfig}
                    className="aspect-square w-full max-w-40 shrink-0"
                    role="img"
                    aria-label="Properties by status"
                >
                    <PieChart>
                        <ChartTooltip
                            cursor={false}
                            content={<ChartTooltipContent hideLabel />}
                        />
                        <Pie
                            data={
                                total === 0
                                    ? [
                                          {
                                              label: 'None',
                                              count: 1,
                                              color: 'var(--muted)',
                                          },
                                      ]
                                    : slices.filter((slice) => slice.count > 0)
                            }
                            dataKey="count"
                            nameKey="label"
                            innerRadius="62%"
                            outerRadius="100%"
                            stroke="var(--card)"
                            strokeWidth={2}
                            isAnimationActive={false}
                        >
                            {(total === 0
                                ? [{ label: 'None', color: 'var(--muted)' }]
                                : slices.filter((slice) => slice.count > 0)
                            ).map((slice) => (
                                <Cell key={slice.label} fill={slice.color} />
                            ))}
                            <Label
                                content={({ viewBox }) =>
                                    viewBox && 'cx' in viewBox ? (
                                        <text
                                            x={viewBox.cx}
                                            y={viewBox.cy}
                                            textAnchor="middle"
                                            dominantBaseline="middle"
                                        >
                                            <tspan
                                                x={viewBox.cx}
                                                y={viewBox.cy}
                                                className="fill-foreground font-heading text-2xl font-semibold tabular-nums"
                                            >
                                                {total}
                                            </tspan>
                                            <tspan
                                                x={viewBox.cx}
                                                y={(viewBox.cy ?? 0) + 20}
                                                className="fill-muted-foreground text-xs"
                                            >
                                                {total === 1
                                                    ? 'property'
                                                    : 'properties'}
                                            </tspan>
                                        </text>
                                    ) : null
                                }
                            />
                        </Pie>
                    </PieChart>
                </ChartContainer>
                <ul className="flex w-full min-w-0 flex-col gap-1">
                    {slices.map((slice) => (
                        <li key={slice.status}>
                            <Link
                                href={properties({
                                    query: { filter: { status: slice.status } },
                                })}
                                className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span
                                        aria-hidden
                                        className="size-2.5 shrink-0 rounded-sm"
                                        style={{ background: slice.color }}
                                    />
                                    <span className="truncate">
                                        {slice.label}
                                    </span>
                                </span>
                                <span className="font-medium tabular-nums">
                                    {slice.count}
                                </span>
                            </Link>
                        </li>
                    ))}
                    <li className="px-2 pt-1 text-sm text-muted-foreground">
                        <Link
                            href={partners()}
                            className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {summary.activePartners} active{' '}
                            {summary.activePartners === 1
                                ? 'partner'
                                : 'partners'}
                        </Link>
                    </li>
                </ul>
            </CardContent>
        </Card>
    );
}

/** `2026-11` as "Nov", or "Nov 2026" with the year. */
function monthLabel(month: string, withYear = false): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(year, number - 1, 1).toLocaleDateString('en-GB', {
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
    });
}

const revenueConfig = {
    bookingEngine: { label: 'Booking engine', color: 'var(--chart-2)' },
    channelManager: { label: 'Channel manager', color: 'var(--chart-4)' },
} satisfies ChartConfig;

/** Estatell's commission income per month for six months, stacked by service. Display only: the server sums it. */
function RevenueCard({ summary }: { summary: Summary }) {
    const currency = summary.commissions.currency;
    const data = summary.months.map((month) => ({
        month: month.month,
        bookingEngine: Number(month.bookingEngine),
        channelManager: Number(month.channelManager),
    }));
    const current = summary.months.at(-1);
    const thisMonth = current
        ? (
              Number(current.bookingEngine) + Number(current.channelManager)
          ).toFixed(2)
        : '0.00';

    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Percent
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <Link
                        href={commissions()}
                        className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Estatell revenue
                    </Link>
                </CardTitle>
                <CardAction className="text-right">
                    <p className="font-heading text-lg font-semibold tabular-nums">
                        <MoneyText amount={thisMonth} currency={currency} />
                    </p>
                    <p className="text-xs text-muted-foreground">this month</p>
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col">
                <ChartContainer
                    config={revenueConfig}
                    className="aspect-auto h-56 w-full"
                    role="img"
                    aria-label="Estatell commission income for the last six months, by service"
                >
                    <BarChart accessibilityLayer data={data}>
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="month"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(month: string) => monthLabel(month)}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            width={56}
                            tickFormatter={(value: number) =>
                                value.toLocaleString('en-GB', {
                                    notation: 'compact',
                                })
                            }
                        />
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    labelFormatter={(_, payload) =>
                                        payload[0]
                                            ? monthLabel(
                                                  payload[0].payload.month,
                                                  true,
                                              )
                                            : ''
                                    }
                                    formatter={(value, name) => (
                                        <span className="flex w-full items-center justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                {revenueConfig[
                                                    name as keyof typeof revenueConfig
                                                ]?.label ?? name}
                                            </span>
                                            <span className="font-mono font-medium text-foreground tabular-nums">
                                                {formatMoney(
                                                    Number(value).toFixed(2),
                                                    currency,
                                                )}
                                            </span>
                                        </span>
                                    )}
                                />
                            }
                        />
                        <ChartLegend content={<ChartLegendContent />} />
                        <Bar
                            dataKey="bookingEngine"
                            stackId="income"
                            fill="var(--color-bookingEngine)"
                        />
                        <Bar
                            dataKey="channelManager"
                            stackId="income"
                            fill="var(--color-channelManager)"
                            radius={[4, 4, 0, 0]}
                        />
                    </BarChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

const growthConfig = {
    signUps: { label: 'Sign-ups', color: 'var(--chart-2)' },
    goLives: { label: 'Go-lives', color: 'var(--chart-4)' },
} satisfies ChartConfig;

/** Sign-ups and go-lives per month for six months. A gap between the lines means properties stall in onboarding. */
function GrowthCard({ summary }: { summary: Summary }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <TrendingUp
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    Growth
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ChartContainer
                    config={growthConfig}
                    className="aspect-auto h-40 w-full"
                    role="img"
                    aria-label="Property sign-ups and go-lives for the last six months"
                >
                    <LineChart
                        accessibilityLayer
                        data={summary.months}
                        margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                    >
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="month"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(month: string) => monthLabel(month)}
                        />
                        <YAxis
                            allowDecimals={false}
                            tickLine={false}
                            axisLine={false}
                            width={32}
                        />
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    indicator="line"
                                    labelFormatter={(_, payload) =>
                                        payload[0]
                                            ? monthLabel(
                                                  payload[0].payload.month,
                                                  true,
                                              )
                                            : ''
                                    }
                                />
                            }
                        />
                        <ChartLegend content={<ChartLegendContent />} />
                        <Line
                            dataKey="signUps"
                            type="monotone"
                            stroke="var(--color-signUps)"
                            strokeWidth={2}
                            dot={{ r: 3, fill: 'var(--color-signUps)' }}
                        />
                        <Line
                            dataKey="goLives"
                            type="monotone"
                            stroke="var(--color-goLives)"
                            strokeWidth={2}
                            strokeDasharray="4 4"
                            dot={{ r: 3, fill: 'var(--color-goLives)' }}
                        />
                    </LineChart>
                </ChartContainer>
            </CardContent>
        </Card>
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
                    <ul className="divide-y border-y xl:max-h-112 xl:overflow-y-auto">
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

/** The next payout from the commission ledger, and what properties owe Estatell when that is above zero. */
function PayoutsCard({ summary }: { summary: Summary }) {
    const { commissions: totals } = summary;

    return (
        <TileCard
            title="Next payout"
            href={commissions()}
            icon={Wallet}
            tone="neutral"
        >
            <p className="font-heading text-2xl font-semibold tabular-nums">
                <MoneyText
                    amount={totals.immediatePayable}
                    currency={totals.currency}
                />
            </p>
            <p className="text-sm text-muted-foreground tabular-nums">
                on <DateText date={totals.payableOn} />
            </p>
            {Number(totals.owedToEstatell) > 0 && (
                <p className="text-sm text-muted-foreground tabular-nums">
                    <MoneyText
                        amount={totals.owedToEstatell}
                        currency={totals.currency}
                    />{' '}
                    owed to Estatell by {totals.propertiesOwing}{' '}
                    {totals.propertiesOwing === 1 ? 'property' : 'properties'}
                </p>
            )}
        </TileCard>
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
                        <div className="grid gap-3 xl:grid-cols-3">
                            <CardSkeleton rows={4} />
                            <CardSkeleton rows={6} className="xl:col-span-2" />
                        </div>
                    }
                >
                    {summary && (
                        <div className="grid gap-3 xl:grid-cols-3">
                            <PropertiesCard summary={summary} />
                            <div className="min-w-0 xl:col-span-2">
                                <RevenueCard summary={summary} />
                            </div>
                        </div>
                    )}
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
                        fallback={<CardSkeleton rows={6} />}
                    >
                        {summary && (
                            <div className="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-1">
                                <GrowthCard summary={summary} />
                                <PayoutsCard summary={summary} />
                            </div>
                        )}
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
