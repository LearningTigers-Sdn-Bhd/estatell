import { BedDouble, ChartLine, Coins, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    Rectangle,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import type { BarShapeProps } from 'recharts';
import { MoneyText } from '@/components/pms/money-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ChangeLine } from '@/components/pms/dashboard/performance/kpi-tiles';
import {
    describeChange,
    longDate,
    monthLabel,
    rangeLabel,
    shortDate,
    whole,
} from '@/components/pms/dashboard/performance/format';

type Metric = 'occupancy' | 'adr' | 'revpar';

/** The same icons as the KPI tiles. */
const metrics: { value: Metric; label: string; icon: LucideIcon }[] = [
    { value: 'occupancy', label: 'Occupancy', icon: BedDouble },
    { value: 'adr', label: 'ADR', icon: Coins },
    { value: 'revpar', label: 'RevPAR', icon: TrendingUp },
];

const previousKeys = {
    occupancy: 'previousOccupancy',
    adr: 'previousAdr',
    revpar: 'previousRevpar',
} as const;

const config = {
    current: { label: 'This period', color: 'var(--chart-3)' },
    previous: { label: 'Compared', color: 'var(--muted-foreground)' },
} satisfies ChartConfig;

/**
 * One bar standing on the zero line: rounded at its free end, green above zero and warning below it. Recharts gives a
 * bar below zero a negative height, so the rectangle is turned the right way up first.
 */
function TrendBar(props: BarShapeProps) {
    const negative = props.height < 0;

    return (
        <Rectangle
            x={props.x}
            y={negative ? props.y + props.height : props.y}
            width={props.width}
            height={Math.abs(props.height)}
            radius={negative ? [0, 0, 4, 4] : [4, 4, 0, 0]}
            fill={negative ? 'var(--warning)' : 'var(--color-current)'}
            fillOpacity={props.isActive ? 0.8 : 1}
        />
    );
}

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/**
 * The trend of one metric as bars by day, or by month for 12 months, with the compared range as a dashed line. A day
 * without a figure has no bar: no night audit yet, or for ADR no rooms sold. Revenue counts on its posting date, so a
 * correction can take a day below zero; that bar hangs from the zero line in the warning color. The metric switch is
 * display only.
 */
export function TrendCard({
    summary,
    period,
}: {
    summary: App.Data.Dashboard.PerformanceSummaryData;
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    const [metric, setMetric] = useState<Metric>('occupancy');
    const byMonth = period.groupBy === 'month';
    const step = byMonth ? 'Months' : 'Days';
    const isMoney = metric !== 'occupancy';
    const code = summary.currency?.code ?? '';
    const decimals = summary.currency?.decimals ?? 2;
    const data = summary.trend.map((point) => ({
        date: point.date,
        current: point[metric],
        previous: point[previousKeys[metric]],
        reason:
            point.occupancy === null
                ? 'No night audit'
                : point[metric] === null
                  ? 'No rooms sold'
                  : (point[metric] ?? 0) < 0
                    ? 'Corrections reversed earlier nights'
                    : null,
    }));
    const empty = data.every((point) => point.current === null);
    const firstWithFigure = summary.trend.findIndex(
        (point) => point.occupancy !== null,
    );
    const notes = [
        firstWithFigure > 0 &&
            `No night audit figures before ${byMonth ? monthLabel(summary.trend[firstWithFigure].date) : shortDate(summary.trend[firstWithFigure].date)}`,
        data.some((point) => point.reason === 'No rooms sold') &&
            `${step} without a bar had no rooms sold`,
        data.some((point) => (point.current ?? 0) < 0) &&
            `A bar below zero is a ${byMonth ? 'month' : 'day'} when corrections reversed earlier nights' room revenue`,
    ].filter((note): note is string => typeof note === 'string');

    const highest = Math.max(
        0,
        ...data.flatMap((point) => [point.current ?? 0, point.previous ?? 0]),
    );
    const occupancyTop = Math.max(25, Math.ceil(highest / 25) * 25);
    const tick = (value: number) =>
        isMoney ? `${code} ${compact.format(value)}` : `${value}%`;
    const tooltipValue = (value: number) =>
        isMoney
            ? `${code} ${value.toLocaleString('en', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`
            : `${Math.round(value)}%`;

    const kpi = summary[metric];
    const headline =
        kpi.value === null ? null : isMoney ? (
            <MoneyText amount={kpi.value} currency={summary.currency} />
        ) : (
            `${whole(kpi.value)}%`
        );

    return (
        <Card className="min-w-0 flex-1">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    <ChartLine
                        aria-hidden
                        className="size-5 text-muted-foreground"
                    />
                    Trend
                </CardTitle>
                <ToggleGroup
                    variant="outline"
                    size="sm"
                    spacing={0}
                    value={[metric]}
                    onValueChange={(value) =>
                        value[0] && setMetric(value[0] as Metric)
                    }
                    aria-label="Trend metric"
                >
                    {metrics.map((item) => (
                        <ToggleGroupItem key={item.value} value={item.value}>
                            <item.icon data-icon="inline-start" />
                            {item.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                {headline !== null && (
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-heading text-2xl font-semibold tabular-nums">
                            {headline}
                        </p>
                        <ChangeLine
                            change={describeChange(
                                kpi.change,
                                isMoney ? '%' : 'pt',
                            )}
                        />
                        {kpi.change !== null && (
                            <p className="text-xs text-muted-foreground">
                                vs{' '}
                                {rangeLabel(
                                    period.previousFrom,
                                    period.previousTo,
                                )}
                            </p>
                        )}
                    </div>
                )}
                {empty ? (
                    <p className="flex min-h-64 flex-1 items-center justify-center text-sm text-muted-foreground">
                        {summary.hasStays
                            ? 'No night audit figures for this period'
                            : 'No stays in this period'}
                    </p>
                ) : (
                    <ChartContainer
                        config={config}
                        className="aspect-auto min-h-64 w-full flex-1"
                        role="img"
                        aria-label={`${metrics.find((item) => item.value === metric)?.label} trend`}
                    >
                        <ComposedChart
                            accessibilityLayer
                            data={data}
                            margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={32}
                                tickFormatter={(date: string) =>
                                    byMonth ? monthLabel(date) : shortDate(date)
                                }
                            />
                            <YAxis
                                domain={
                                    isMoney ? [0, 'auto'] : [0, occupancyTop]
                                }
                                ticks={
                                    isMoney
                                        ? undefined
                                        : Array.from(
                                              { length: occupancyTop / 25 + 1 },
                                              (_, index) => index * 25,
                                          )
                                }
                                tickLine={false}
                                axisLine={false}
                                width={isMoney ? 72 : 48}
                                tickFormatter={tick}
                            />
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        indicator="dot"
                                        labelFormatter={(label) => {
                                            const point = data.find(
                                                (item) => item.date === label,
                                            );

                                            return (
                                                <span className="flex flex-col">
                                                    {byMonth
                                                        ? monthLabel(
                                                              String(label),
                                                          )
                                                        : longDate(
                                                              String(label),
                                                          )}
                                                    {point?.reason && (
                                                        <span className="font-normal text-muted-foreground">
                                                            {point.reason}
                                                        </span>
                                                    )}
                                                </span>
                                            );
                                        }}
                                        formatter={(value, name, item) =>
                                            value === null ? null : (
                                                <span className="flex w-full items-center justify-between gap-4">
                                                    <span className="flex items-center gap-1.5 text-muted-foreground">
                                                        <span
                                                            aria-hidden
                                                            className="size-2.5 shrink-0 rounded-sm"
                                                            style={{
                                                                background:
                                                                    item.color,
                                                            }}
                                                        />
                                                        {
                                                            config[
                                                                name as keyof typeof config
                                                            ].label
                                                        }
                                                    </span>
                                                    <span className="font-mono font-medium text-foreground tabular-nums">
                                                        {tooltipValue(
                                                            Number(value),
                                                        )}
                                                    </span>
                                                </span>
                                            )
                                        }
                                    />
                                }
                            />
                            <Bar
                                dataKey="current"
                                fill="var(--color-current)"
                                maxBarSize={32}
                                shape={TrendBar}
                                activeBar={TrendBar}
                            />
                            <ReferenceLine
                                y={0}
                                stroke="var(--foreground)"
                                strokeOpacity={0.3}
                            />
                            <Line
                                dataKey="previous"
                                type="linear"
                                stroke="var(--color-previous)"
                                strokeWidth={1.5}
                                strokeDasharray="4 4"
                                dot={false}
                                activeDot={false}
                            />
                        </ComposedChart>
                    </ChartContainer>
                )}
                {!empty &&
                    notes.map((note) => (
                        <p key={note} className="text-xs text-muted-foreground">
                            {note}
                        </p>
                    ))}
            </CardContent>
        </Card>
    );
}
