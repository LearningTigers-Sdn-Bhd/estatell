import { Head, router, usePage } from '@inertiajs/react';
import {
    BadgePercent,
    ChartColumn,
    Clock,
    Receipt,
    ShoppingBag,
    Store,
    Tags,
    UtensilsCrossed,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { formatMoney, MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatCard } from '@/components/pms/stat-card';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { reportDatePresets } from '@/lib/date-presets';
import { posSales } from '@/routes/reports';

type Props = {
    filters: { from: string; to: string };
    summary: {
        total: string;
        orders: number;
        averageOrder: string | null;
        discounts: string;
    };
    daily: { date: string; total: string; orders: number }[];
    byOutlet: {
        name: string;
        total: string;
        orders: number;
        discounts: string;
    }[];
    byCategory: { name: string; net: string; quantity: string }[];
    audit: { voidedOrders: number; voidedLines: number; refunds: number };
    redeemedMeals: {
        outletName: string;
        mealPeriodName: string;
        portions: string;
        grossValue: string;
    }[];
    timeClocks: {
        id: number;
        staffName: string;
        outletName: string;
        clockedInAt: string;
        clockedOutAt: string | null;
        minutes: number | null;
    }[];
    currency: App.Data.Property.CurrencyData | null;
};

const chartConfig = {
    total: { label: 'Sales', color: 'var(--chart-3)' },
} satisfies ChartConfig;

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** `2026-11-26` as a local date, so it never shifts a day. */
function parseDay(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function shortDay(date: string): string {
    return parseDay(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function longDay(date: string): string {
    return parseDay(date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function dateTime(value: string): string {
    return new Date(value).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

function formatDuration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rem = minutes % 60;

    return hours > 0 ? `${hours}h ${rem}m` : `${rem}m`;
}

/** Display only: the width of a share bar. */
function share(value: string, whole: number): number {
    return whole > 0 ? Math.max(0, (Number(value) / whole) * 100) : 0;
}

function SectionCard({
    icon: Icon,
    title,
    description,
    className,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon
                        aria-hidden
                        className="size-4 text-muted-foreground"
                    />
                    {title}
                </CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col">
                {children}
            </CardContent>
        </Card>
    );
}

/** One ranked row: name and amount, a bar for its share of the whole, and a muted detail line. */
function ShareRow({
    name,
    amount,
    percent,
    detail,
}: {
    name: string;
    amount: ReactNode;
    percent: number;
    detail: string;
}) {
    return (
        <li className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="truncate font-medium">{name}</span>
                <span className="font-medium">{amount}</span>
            </div>
            <div aria-hidden className="h-2 rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-chart-3"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <div className="flex justify-between gap-3 text-xs text-muted-foreground tabular-nums">
                <span>{detail}</span>
                <span>{Math.round(percent)}%</span>
            </div>
        </li>
    );
}

export default function PosSales({
    filters,
    summary,
    daily,
    byOutlet,
    byCategory,
    audit,
    redeemedMeals,
    timeClocks,
    currency,
}: Props) {
    const currencyCode = currency?.code ?? 'MYR';
    const businessDay = usePage().props.businessDate?.date;
    const chartData = daily.map((day) => ({
        ...day,
        total: Number(day.total),
    }));
    const outletWhole = byOutlet.reduce(
        (sum, row) => sum + Number(row.total),
        0,
    );
    const categoryWhole = byCategory.reduce(
        (sum, row) => sum + Number(row.net),
        0,
    );

    function visit(from: string, to: string) {
        router.get(
            posSales.url(undefined, { query: { from, to } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="POS sales" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="POS sales"
                    description="Sales, exceptions, included meals and staff hours. Figures are net of refunds."
                    actions={
                        <DateRangePicker
                            from={filters.from}
                            to={filters.to}
                            onChange={visit}
                            presets={
                                businessDay
                                    ? reportDatePresets(businessDay)
                                    : undefined
                            }
                            months={2}
                            weekStartsOn={1}
                            className="w-auto"
                        />
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        icon={Wallet}
                        label="Total sales"
                        value={
                            <MoneyText
                                amount={summary.total}
                                currency={currency}
                            />
                        }
                        hint="With service charge and tax"
                    />
                    <StatCard
                        icon={ShoppingBag}
                        label="Orders"
                        value={summary.orders}
                        hint="Settled sales"
                    />
                    <StatCard
                        icon={Receipt}
                        label="Average order"
                        value={
                            summary.averageOrder === null ? (
                                '–'
                            ) : (
                                <MoneyText
                                    amount={summary.averageOrder}
                                    currency={currency}
                                />
                            )
                        }
                        hint="Total sales per order"
                    />
                    <StatCard
                        icon={BadgePercent}
                        label="Discounts"
                        value={
                            <MoneyText
                                amount={summary.discounts}
                                currency={currency}
                            />
                        }
                        hint="Given on settled orders"
                    />
                </div>

                <SectionCard
                    icon={ChartColumn}
                    title="Daily sales"
                    description="Total sales by business date."
                >
                    {chartData.length === 0 ? (
                        <EmptyState
                            icon={ChartColumn}
                            title="No settled orders in this range"
                        />
                    ) : (
                        <ChartContainer
                            config={chartConfig}
                            className="aspect-auto h-64 w-full"
                            role="img"
                            aria-label="Total sales by day"
                        >
                            <BarChart
                                accessibilityLayer
                                data={chartData}
                                margin={{
                                    top: 8,
                                    right: 8,
                                    left: 0,
                                    bottom: 0,
                                }}
                            >
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="date"
                                    tickLine={false}
                                    axisLine={false}
                                    tickMargin={8}
                                    minTickGap={24}
                                    tickFormatter={shortDay}
                                />
                                <YAxis
                                    tickLine={false}
                                    axisLine={false}
                                    width={48}
                                    tickFormatter={(value: number) =>
                                        compact.format(value)
                                    }
                                />
                                <ChartTooltip
                                    content={
                                        <ChartTooltipContent
                                            labelFormatter={(_, payload) =>
                                                payload[0]
                                                    ? longDay(
                                                          payload[0].payload
                                                              .date,
                                                      )
                                                    : ''
                                            }
                                            formatter={(value, _, item) => (
                                                <span className="tabular-nums">
                                                    {formatMoney(
                                                        String(value),
                                                        currency,
                                                    )}{' '}
                                                    · {item.payload.orders}{' '}
                                                    {item.payload.orders === 1
                                                        ? 'order'
                                                        : 'orders'}
                                                </span>
                                            )}
                                        />
                                    }
                                />
                                <Bar
                                    dataKey="total"
                                    fill="var(--color-total)"
                                    radius={[4, 4, 0, 0]}
                                    maxBarSize={32}
                                />
                            </BarChart>
                        </ChartContainer>
                    )}
                </SectionCard>

                <div className="grid gap-4 lg:grid-cols-2">
                    <SectionCard
                        icon={Tags}
                        title="Sales by category"
                        description={`Net sales (${currencyCode}), before service charge and tax.`}
                    >
                        {byCategory.length === 0 ? (
                            <EmptyState
                                icon={Tags}
                                title="No category sales in this range"
                            />
                        ) : (
                            <ul className="flex flex-col gap-4">
                                {byCategory.map((row) => (
                                    <ShareRow
                                        key={row.name}
                                        name={row.name}
                                        amount={
                                            <MoneyText
                                                amount={row.net}
                                                currency={currency}
                                                plain
                                            />
                                        }
                                        percent={share(row.net, categoryWhole)}
                                        detail={`${row.quantity} sold`}
                                    />
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    <SectionCard
                        icon={Store}
                        title="Sales by outlet"
                        description={`Total sales (${currencyCode}), with service charge and tax.`}
                    >
                        {byOutlet.length === 0 ? (
                            <EmptyState
                                icon={Store}
                                title="No outlet sales in this range"
                            />
                        ) : (
                            <ul className="flex flex-col gap-4">
                                {byOutlet.map((row) => (
                                    <ShareRow
                                        key={row.name}
                                        name={row.name}
                                        amount={
                                            <MoneyText
                                                amount={row.total}
                                                currency={currency}
                                                plain
                                            />
                                        }
                                        percent={share(row.total, outletWhole)}
                                        detail={`${row.orders} ${row.orders === 1 ? 'order' : 'orders'} · ${formatMoney(row.discounts, currency)} discounts`}
                                    />
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <SectionCard
                        icon={Receipt}
                        title="Voids and refunds"
                        description="Worth a check at shift close."
                    >
                        <dl className="flex flex-col divide-y">
                            {[
                                {
                                    label: 'Voided orders',
                                    count: audit.voidedOrders,
                                },
                                {
                                    label: 'Voided lines',
                                    count: audit.voidedLines,
                                },
                                { label: 'Refunds', count: audit.refunds },
                            ].map((row) => (
                                <div
                                    key={row.label}
                                    className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <dt className="text-sm text-muted-foreground">
                                        {row.label}
                                    </dt>
                                    <dd className="font-heading text-xl font-semibold tabular-nums">
                                        {row.count}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </SectionCard>

                    <SectionCard
                        icon={UtensilsCrossed}
                        title="Included meals redeemed"
                        description="Meals covered by the guest's rate, at menu value."
                        className="lg:col-span-2"
                    >
                        {redeemedMeals.length === 0 ? (
                            <EmptyState
                                icon={UtensilsCrossed}
                                title="No included meals redeemed in this range"
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Outlet</TableHead>
                                        <TableHead>Meal period</TableHead>
                                        <TableHead className="text-right">
                                            Portions
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Menu value ({currencyCode})
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {redeemedMeals.map((row) => (
                                        <TableRow
                                            key={`${row.outletName}-${row.mealPeriodName}`}
                                        >
                                            <TableCell className="font-medium">
                                                {row.outletName}
                                            </TableCell>
                                            <TableCell>
                                                {row.mealPeriodName}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.portions}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.grossValue}
                                                    currency={currency}
                                                    plain
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                </div>

                <SectionCard
                    icon={Clock}
                    title="Staff time clock"
                    description="Up to the 100 latest clock-ins in this range."
                >
                    {timeClocks.length === 0 ? (
                        <EmptyState
                            icon={Clock}
                            title="No clock entries in this range"
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff</TableHead>
                                    <TableHead>Outlet</TableHead>
                                    <TableHead>Clock in</TableHead>
                                    <TableHead>Clock out</TableHead>
                                    <TableHead className="text-right">
                                        Duration
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {timeClocks.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">
                                            {row.staffName}
                                        </TableCell>
                                        <TableCell>{row.outletName}</TableCell>
                                        <TableCell className="tabular-nums">
                                            {dateTime(row.clockedInAt)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.clockedOutAt === null
                                                ? '–'
                                                : dateTime(row.clockedOutAt)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.minutes === null ? (
                                                <Badge variant="secondary">
                                                    On shift
                                                </Badge>
                                            ) : (
                                                formatDuration(row.minutes)
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>
            </div>
        </>
    );
}

PosSales.layout = {
    breadcrumbs: [{ title: 'POS sales', href: posSales() }],
};
