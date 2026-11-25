import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import type { BarShapeProps } from 'recharts';
import { ChartBar } from '@/components/pms/reports/chart-bar';
import {
    axisDate,
    moneyText,
    moneyTick,
    tooltipDate,
} from '@/components/pms/reports/chart-format';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type Metric = 'occupancy' | 'adr' | 'revpar' | 'revenue';
type Point = App.Data.Reports.RoomStatsChartPointData;

/**
 * The chart above the Occupancy and History & forecast tables: one bar per day, or per month for a long range, of the
 * metric picked in the header. Every figure comes from the server and covers the whole range, not the table's page. A
 * forecast bar is lighter, with a legend when the range has one; a day without a row has no bar. Revenue counts on its
 * posting date, so a correction can take a day below zero; that bar hangs from the zero line in `warning`.
 * See docs/22-reports/06-report-charts.md.
 */
export function RoomStatsChart({
    chart,
    currency,
    revenueLabel,
}: {
    chart: App.Data.Reports.RoomStatsChartData;
    currency: App.Data.Property.CurrencyData | null;
    revenueLabel: string;
}) {
    const [metric, setMetric] = useState<Metric>('occupancy');

    if (chart.points.length === 0) {
        return null;
    }

    const metrics: Record<Metric, string> = {
        occupancy: 'Occupancy',
        adr: 'ADR',
        revpar: 'RevPAR',
        revenue: revenueLabel,
    };
    const isMoney = metric !== 'occupancy';
    const byMonth = chart.step === 'month';
    const data = chart.points.map((point: Point) => ({
        date: point.date,
        basis: point.basis,
        value: Number(point[metric]),
    }));
    const hasForecast = data.some((point) => point.basis === 'forecast');
    const highest = Math.max(0, ...data.map((point) => point.value));
    const occupancyTop = Math.max(25, Math.ceil(highest / 25) * 25);
    const config = {
        value: { label: metrics[metric], color: 'var(--chart-3)' },
    } satisfies ChartConfig;
    const basisLabel = {
        actual: 'Actual',
        today: 'Today',
        forecast: 'Forecast',
    };
    const value = (amount: number) =>
        isMoney ? moneyText(amount, currency) : `${amount.toFixed(2)}%`;

    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle>
                    {metrics[metric]} by {byMonth ? 'month' : 'day'}
                </CardTitle>
                <CardAction>
                    <ToggleGroup
                        variant="outline"
                        spacing={0}
                        size="sm"
                        aria-label="Chart figure"
                        value={[metric]}
                        onValueChange={(values) => {
                            if (values[0]) {
                                setMetric(values[0] as Metric);
                            }
                        }}
                    >
                        {(Object.keys(metrics) as Metric[]).map((key) => (
                            <ToggleGroupItem key={key} value={key}>
                                {metrics[key]}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                <ChartContainer
                    config={config}
                    className="aspect-auto h-56 w-full"
                    role="img"
                    aria-label={`${metrics[metric]} by ${byMonth ? 'month' : 'day'}`}
                >
                    <BarChart
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
                            minTickGap={24}
                            tickFormatter={axisDate}
                        />
                        <YAxis
                            domain={
                                isMoney ? ['auto', 'auto'] : [0, occupancyTop]
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
                            width={isMoney ? 72 : 44}
                            tickFormatter={(tick: number) =>
                                isMoney ? moneyTick(tick, currency) : `${tick}%`
                            }
                        />
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    indicator="dot"
                                    labelFormatter={(label, items) => {
                                        const basis = items[0]?.payload
                                            ?.basis as keyof typeof basisLabel;

                                        return (
                                            <span className="flex flex-col">
                                                {tooltipDate(String(label))}
                                                {basis && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {basisLabel[basis]}
                                                    </span>
                                                )}
                                            </span>
                                        );
                                    }}
                                    formatter={(amount) => (
                                        <span className="flex w-full items-center justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                {metrics[metric]}
                                            </span>
                                            <span className="font-mono font-medium text-foreground tabular-nums">
                                                {value(Number(amount))}
                                            </span>
                                        </span>
                                    )}
                                />
                            }
                        />
                        <Bar
                            dataKey="value"
                            fill="var(--color-value)"
                            maxBarSize={32}
                            shape={(props: BarShapeProps) => (
                                <ChartBar
                                    {...props}
                                    faded={props.payload?.basis === 'forecast'}
                                />
                            )}
                            activeBar={(props: BarShapeProps) => (
                                <ChartBar
                                    {...props}
                                    faded={props.payload?.basis === 'forecast'}
                                />
                            )}
                        />
                        <ReferenceLine
                            y={0}
                            stroke="var(--foreground)"
                            strokeOpacity={0.3}
                        />
                    </BarChart>
                </ChartContainer>
                {hasForecast && (
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="size-2.5 rounded-sm bg-chart-3"
                            />
                            Actual
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="size-2.5 rounded-sm bg-chart-3/45"
                            />
                            Forecast (on the books)
                        </span>
                    </div>
                )}
                {data.some((point) => point.value < 0) && (
                    <p className="text-xs text-muted-foreground">
                        A bar below zero is a {byMonth ? 'month' : 'day'} when
                        corrections reversed earlier nights&apos; room revenue.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
