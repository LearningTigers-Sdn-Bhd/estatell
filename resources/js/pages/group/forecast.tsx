import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { dashboard as groupDashboard, forecast } from '@/routes/group';
import { exportMethod as exportCsv } from '@/routes/group/forecast';

type Group = { id: number; name: string };
type Row = App.Data.Group.GroupForecastRowData;
type Currency = App.Data.Property.CurrencyData | null;

/** The value of the property switch for every property together. */
const ALL = 'all';

const HORIZONS = [3, 6, 12, 18, 24];

const chartConfig = {
    occupancy: { label: 'On the books', color: 'var(--chart-1)' },
    lastYear: { label: 'Last year', color: 'var(--chart-4)' },
} satisfies ChartConfig;

/** `2026-12` as "Dec 2026", or "Dec" when short. */
function monthLabel(month: string, short = false): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year, number - 1, 1)).toLocaleDateString(
        'en-GB',
        short
            ? { month: 'short', timeZone: 'UTC' }
            : { month: 'short', year: 'numeric', timeZone: 'UTC' },
    );
}

/** A `Y-m` month moved by whole months. */
function shiftMonth(month: string, by: number): string {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));

    return date.toISOString().slice(0, 7);
}

function money(amount: string | null, currency: Currency) {
    return amount === null ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <MoneyText amount={amount} currency={currency} plain />
    );
}

/** The change in occupancy against last year, in percentage points. */
function change(row: Row) {
    if (row.lastYearOccupancy === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const points = Number(row.occupancy) - Number(row.lastYearOccupancy);

    return (
        <span
            className={
                points > 0
                    ? 'text-success'
                    : points < 0
                      ? 'text-destructive'
                      : 'text-muted-foreground'
            }
        >
            {points > 0 ? '+' : ''}
            {points.toFixed(1)} pts
        </span>
    );
}

const basisLabels: Record<string, string> = {
    actual: 'Actual',
    mixed: 'Actual + on the books',
    forecast: 'On the books',
};

/**
 * Group › Forecast: occupancy and revenue month by month for the months ahead, at each property and for the group,
 * beside the same months last year. The property switch and the chart work on the loaded rows; the months and the
 * horizon reload the page. See docs/03-staff/05-group-mode.md#forecast.
 */
export default function GroupForecast({
    group,
    from,
    months,
    thisMonth,
    forecast: report,
}: {
    group: Group;
    from: string;
    months: number;
    maxMonths: number;
    thisMonth: string;
    forecast: App.Data.Group.GroupForecastData;
}) {
    const [view, setView] = useState<string>(ALL);
    const rows = useMemo(
        () =>
            report.rows.filter((row) =>
                view === ALL ? row.code === null : row.code === view,
            ),
        [report.rows, view],
    );
    const rowCurrency = report.currency;
    const unit = rowCurrency ? ` (${rowCurrency.code})` : '';
    const chartData = rows.map((row) => ({
        month: monthLabel(row.month, true),
        occupancy: Number(row.occupancy),
        lastYear:
            row.lastYearOccupancy === null
                ? null
                : Number(row.lastYearOccupancy),
    }));
    const totals = rows.reduce(
        (sum, row) => ({
            available: sum.available + row.available,
            sold: sum.sold + row.sold,
        }),
        { available: 0, sold: 0 },
    );
    const visit = (next: { from?: string; months?: number }) =>
        router.get(
            forecast.url({ propertyGroup: group.id }),
            { from: next.from ?? from, months: next.months ?? months },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title={`${group.name} forecast`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Forecast"
                    description="Occupancy and revenue month by month: closed dates as they happened, later dates from the bookings on hand, beside last year."
                    actions={
                        <a
                            href={exportCsv.url(
                                { propertyGroup: group.id },
                                { query: { from, months } },
                            )}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <Download data-icon="inline-start" />
                            Export CSV
                        </a>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Earlier month"
                            onClick={() =>
                                visit({ from: shiftMonth(from, -1) })
                            }
                        >
                            <ChevronLeft />
                        </Button>
                        <span className="min-w-36 text-center text-sm tabular-nums">
                            {monthLabel(from)} –{' '}
                            {monthLabel(shiftMonth(from, months - 1))}
                        </span>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Later month"
                            onClick={() => visit({ from: shiftMonth(from, 1) })}
                        >
                            <ChevronRight />
                        </Button>
                        {from !== thisMonth && (
                            <Button
                                variant="ghost"
                                onClick={() => visit({ from: thisMonth })}
                            >
                                This month
                            </Button>
                        )}
                    </div>
                    <EnumSelect
                        aria-label="Months ahead"
                        className="w-36"
                        options={HORIZONS.map((count) => ({
                            value: String(count),
                            label: `${count} months`,
                        }))}
                        value={String(months)}
                        onValueChange={(value) =>
                            visit({ months: Number(value) })
                        }
                    />
                    {report.properties.length > 1 && (
                        <ToggleGroup
                            value={[view]}
                            onValueChange={(value: string[]) =>
                                value[0] && setView(value[0])
                            }
                            variant="outline"
                            size="sm"
                        >
                            <ToggleGroupItem value={ALL}>
                                All properties
                            </ToggleGroupItem>
                            {report.properties.map((property) => (
                                <ToggleGroupItem
                                    key={property.code}
                                    value={property.code}
                                >
                                    {property.name}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                </div>

                {report.leftOut.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Not included: {report.leftOut.join(', ')}. Your role
                        there does not show reports.
                    </p>
                )}

                {report.properties.length === 0 ? (
                    <EmptyState
                        icon={TrendingUp}
                        title="No forecast to show"
                        description="Your role does not show reports at these properties."
                    />
                ) : (
                    <>
                        <div className="rounded-lg border p-4">
                            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="text-base font-medium">
                                    Occupancy by month
                                </h2>
                                <span className="text-sm text-muted-foreground tabular-nums">
                                    {totals.sold.toLocaleString()} of{' '}
                                    {totals.available.toLocaleString()} nights
                                    sold ·{' '}
                                    {totals.available === 0
                                        ? '0'
                                        : (
                                              (totals.sold / totals.available) *
                                              100
                                          ).toFixed(1)}
                                    %
                                </span>
                            </div>
                            <ChartContainer
                                config={chartConfig}
                                className="aspect-auto h-64 w-full"
                                role="img"
                                aria-label="Occupancy by month, on the books and last year"
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
                                        dataKey="month"
                                        tickLine={false}
                                        axisLine={false}
                                        tickMargin={8}
                                    />
                                    <YAxis
                                        domain={[0, 100]}
                                        ticks={[0, 25, 50, 75, 100]}
                                        tickLine={false}
                                        axisLine={false}
                                        width={40}
                                        tickFormatter={(value: number) =>
                                            `${value}%`
                                        }
                                    />
                                    <ChartTooltip
                                        cursor={false}
                                        content={
                                            <ChartTooltipContent
                                                formatter={(value, name) =>
                                                    `${chartConfig[name as keyof typeof chartConfig]?.label ?? name}: ${Number(value).toFixed(1)}%`
                                                }
                                            />
                                        }
                                    />
                                    <ChartLegend
                                        content={<ChartLegendContent />}
                                    />
                                    <Bar
                                        dataKey="occupancy"
                                        fill="var(--color-occupancy)"
                                        radius={4}
                                    />
                                    <Bar
                                        dataKey="lastYear"
                                        fill="var(--color-lastYear)"
                                        radius={4}
                                    />
                                </BarChart>
                            </ChartContainer>
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Month</TableHead>
                                        <TableHead>Basis</TableHead>
                                        <TableHead className="text-right">
                                            Available
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Sold
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Occupancy
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Last year
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Change
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {`ADR${unit}`}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Pax
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {`Room revenue${unit}`}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {`Total revenue${unit}`}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {`Room revenue last year${unit}`}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.month}>
                                            <TableCell className="font-medium">
                                                {monthLabel(row.month)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {basisLabels[row.basis] ??
                                                        row.basis}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.available}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.sold}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {Number(row.occupancy).toFixed(
                                                    1,
                                                )}
                                                %
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.lastYearOccupancy === null
                                                    ? '—'
                                                    : `${Number(row.lastYearOccupancy).toFixed(1)}%`}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {change(row)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(row.adr, rowCurrency)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.pax}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(
                                                    row.roomRevenue,
                                                    rowCurrency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(
                                                    row.totalRevenue,
                                                    rowCurrency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(
                                                    row.lastYearRoomRevenue,
                                                    rowCurrency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {view === ALL && report.currency === null && (
                            <p className="text-sm text-muted-foreground">
                                The properties use different currencies, so
                                money is shown per property only.
                            </p>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

GroupForecast.layout = (props: { group: Group }) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
        {
            title: 'Forecast',
            href: forecast({ propertyGroup: props.group.id }),
        },
    ],
});
